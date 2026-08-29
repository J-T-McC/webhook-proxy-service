# Brief: pause and resume dispatch (item #15)

## What

A member can pause a proxy (per proxy, not per destination) and resume it.
While paused, nothing dispatches to any destination: no original fan-out, no
automatic retry, no replay. Ingest never pauses — capture is unaffected.
Resume needs no further member action.

Background: `docs/product/prd-15-pause-and-resume-dispatch.md` `## Acceptance
Criteria` (lines 149-254) and
`docs/questions/prd-15-q-15-01-pause-dispatch-scheduler-interactions.md`
(full). Neither is updated by this brief.

## Decisions

- **Column:** `proxies.paused_at` (nullable timestamp), not a boolean — also
  satisfies AC14 ("says when it was paused"). Not mass-assignable; only the
  pause/resume action writes it.
- **The 3 named entry points (Q-15-01)** are guarded as instructed:
  `AdvanceProxyFifoQueue::claimNext()` checks `paused_at` as the first
  statement inside its claim transaction (no lock needed — a plain read); a
  proxy paused a moment after this check simply has its in-flight claim
  finish normally, an already-accepted-work-completes case, not a violation.
  `SweepStalledFifoDispatches` pass (b) excludes paused proxies from the
  idle-proxy nudge. Pass (a) is untouched, as instructed. `SweepDueRetries`
  excludes deliveries whose proxy is paused.
- **A 4th gap, not named by Q-15-01: Async mode's original dispatch.**
  `IngestController` dispatches `ProcessIngestedWebhook` inline for Async
  proxies; nothing in Q-15-01 gates it. AC3's "original fan-out delivery"
  dispatch form is mode-agnostic, so this needs a guard too. Fix: the guard
  lives inside `ProcessIngestedWebhook::handle()` itself, scoped to
  `processing_mode !== Fifo` — **not** applied to FIFO, because FIFO's own
  claim guard already prevents this call from ever running while paused, and
  adding a redundant check here would (in the one-in-a-million race where a
  pause commits between claim and this call) make `settleOrHold` treat the
  skipped dispatch as "no non-terminal deliveries" and silently settle the
  row as done — losing the event. Async has no such settle step, so the
  guard is safe there.
- **Resume, "no further member action":** FIFO dispatches
  `AdvanceProxyFifoQueue` immediately. Async has no persisted backlog
  structure (deliveries are created inside `ProcessIngestedWebhook`, which
  paused-Async skipped), so resume queries `WebhookEvent`s for the proxy
  with no `deliveries` row and an uncleaned payload, and dispatches
  `ProcessIngestedWebhook` for each. Retries (either mode): resume also
  dispatches `RetryDelivery` for the proxy's now-overdue `retrying`
  deliveries directly (`SweepDueRetries::forProxy()`), rather than waiting
  for the next tick.
- **Retention hold — the Owner's ruling governs over the draft PRD.** AC9
  narrows PRD-05/06's in-flight holds for a paused proxy specifically. As
  built today, `PurgeExpiredPayloads` H2 (unsettled `fifo_dispatches` row)
  and H5's `retrying`-delivery branch hold an event indefinitely regardless
  of age — which, applied to a paused backlog, would silently create exactly
  the hold AC9 forbids. Both holds gain a "unless the row's proxy is paused"
  bypass. H3/H4 are untouched (not implicated by pause).
- **The described livelock — did not match the code as literally stated.**
  Reproduced by direct test: `AdvanceProxyFifoQueue::handle()` on a Pending
  row for an already-cleaned event with zero `deliveries` rows settles and
  advances immediately (`hasNonTerminalDeliveries()` is vacuously false) —
  it does not stick at `claimed`. The real reachable defect is narrower and
  only surfaces once the H2 bypass above exists: a **replay's** dispatch
  pre-creates `deliveries` rows before the FIFO claim; if the event is
  cleaned (now reachable mid-pause) before the advancer reaches that row,
  those rows stay non-terminal forever and the row never leaves
  `awaiting_retry`. Fix, applied once, at the root (`ProcessIngestedWebhook`'s
  cleaned-early-return, so every caller — FIFO claim, Async direct dispatch,
  replay of either mode — is covered): terminalize any pre-existing
  non-terminal `deliveries` for the dispatch (CAS to `failed`, firing
  `DeliveryExhausted`, mirroring `RetryDelivery::terminalizeCleaned`'s
  already-established shape) before returning. Zero-delivery case (original
  dispatch) is an unaffected no-op.
- **Replay while paused (AC3):** rejected at the request, before any row is
  created — the same `ValidationException` shape `ProxyEventReplayController`
  already uses for a cleaned event.
- **UX:** pause/resume gated by the existing `update` policy (AC6), mirroring
  `ProxySigningController`'s pattern. Pause requires confirmation; the
  confirmation states, verbatim, that events keep aging and expire on
  schedule whether or not they were sent (AC10) — no separate
  expired-while-paused presentation is built (Owner ruling supersedes PRD-15
  AC11/AC12's representation apparatus; the cleaned event already reads as
  `cleaned` via the existing three-state payload signal, unchanged). Resume
  needs no confirmation (AC10).

## Known ceiling

Async resume is poll-free but not driven by a single atomic release like
FIFO's — it is a best-effort re-scan at resume time. Acceptable per AC21 (no
numeric targets). Upgrade path if ever needed: a persisted per-event
dispatch marker, same shape as `fifo_dispatches`.

## Done when

Migration + guards + livelock fix + retention bypass + UI landed, tests
green, `composer lint`/`types:check` clean, `pnpm` gates clean for touched JS.
