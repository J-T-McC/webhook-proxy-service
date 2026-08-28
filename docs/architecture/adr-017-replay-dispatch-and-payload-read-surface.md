# ADR-017: Replay as a first-class dispatch through the existing pipeline, and the stored-event read surface with fetch-on-reveal

- **Status:** **Accepted (Project Owner, 2026-08-12)** — **one position of Decision 6 and one
  § Alternatives bullet are under a PROPOSED partial supersession by ADR-024 (pending Owner
  approval)**. Everything else stands, Accepted and operative. See the inline pointers below and
  ADR-024 § *Positions superseded*.
  Gates carried and approved with this
  acceptance: the first user-facing read path over stored payload content (security-sensitive —
  PRD-05 AC16's standing constraint lands here), and the pipeline-envelope extension
  (`PipelineContext` gains a dispatch identity).
- **Author:** Principal Engineer
- **Date:** 2026-08-12
- **Feature:** prd-06-retry-replay (AC9–AC17, AC25; realizes ADR-011's "dispatch-by-reference is
  the #6 replay shape" and Q-05-01's deferred stored-payload surface)
- **Companions:** ADR-015 (replay deliveries enter the same retry state machine — AC13) ·
  ADR-016 (replay's FIFO join-at-the-back)

## Question
PRD-06 requires manual replay of a retained event to one, several, or all of the proxy's current
destinations (AC9/AC10), executed as **a new dispatch now, through the same processing/dispatch
path as a live event** under the proxy's **current** configuration (AC11), landing on the same
record stream **identifiably** (AC12), retrying like a live delivery (AC13), permission-gated to
all three roles with no ownership limit (AC14), and strictly bound by the #5 retention contract
(AC15–AC18). AC25 requires the first payload-content viewer: masked by default, whole-payload
reveal, read-permission-gated. Q-06-03 asks (4) how replay composes with dispatch-by-reference
without new machinery and (5) whether the masked viewer's content is DOM-present or fetched on
reveal. Concretely:

1. How does the pipeline — whose `DeliverStep` fans out to *all* live destinations at attempt 1
   — execute a dispatch scoped to a chosen destination subset, without a parallel path (which
   AC11/AC12 forbid)?
2. What read endpoints expose the events list, event detail, and payload content, under which
   gates, and with the cleaned state honoured on every one (AC15–AC17)?

## Decision

**(1) A replay is a dispatch identity plus pre-created delivery rows; the pipeline itself is
unchanged in shape.** The replay endpoint mints a fresh **`dispatch_uuid`**, creates one
`deliveries` row per chosen destination (`kind = 'replay'`, `status = 'pending'`, ADR-015)
inside one transaction, then dispatches by reference exactly as ingest does (ADR-011
Decision 3):
- **Async proxy:** `ProcessIngestedWebhook::dispatch($ingestId, $replayUuid)` after commit.
- **FIFO proxy:** insert a `fifo_dispatches` row (`webhook_event_id`, `dispatch_uuid =
  $replayUuid`, `pending`) in the same transaction — joining the line at the back (ADR-016) —
  and nudge `AdvanceProxyFifoQueue` after commit.

`ProcessIngestedWebhook::handle(string $ingestId, ?string $dispatchUuid = null)` defaults the
dispatch identity to the ingest id (the original dispatch); `PipelineContext` gains one readonly
field, `dispatchUuid`, defaulted the same way — the minimal ADR-001 envelope extension, carried
because the terminal step genuinely needs it. `DeliverStep` iterates **the dispatch's
`deliveries` rows** (original rows are created at pipeline entry from the live destination set;
replay rows pre-exist) instead of `$proxy->destinations` directly, and executes attempt 1 per
row, Async-queued or FIFO-inline exactly as today. Everything else in the pipeline runs
unchanged: future #9/#8 transform steps re-process the raw payload under current config (AC11's
requirement, satisfied structurally); `CaptureDispatchedStep`'s `updateOrCreate` keyed on the
event stays idempotent (pre-#8 it re-writes nothing — divergence is impossible; the post-#8
"replay refreshes the stored dispatched output" semantic is recorded for #8, at zero content
cost today). The upstream response path is untouched by construction — response resolution lives
in `IngestController`, which replay never traverses (AC11's "never re-runs any upstream
response"; ADR-004).

**(2) Traceability is structural — the `replay_of` seam lands on `deliveries`, not on attempt
rows.** ADR-003's Impact sketched "a nullable `replay_of_id`" on attempt records. The deliveries
entity supersedes the need: `deliveries.kind = 'replay'` distinguishes (AC12, explicit — never
inferred), `deliveries.webhook_event_id` traces to the original received event, `dispatch_uuid`
groups one replay action's fan-out (the design's "Replay — {time}" grouping), and attempts chain
through `delivery_id`. One record stream, unchanged attempt shape, no parallel path — #11/#13
distinguish replays by a join, not a second pipeline. ADR-003 is not amended: the sketch was an
Impact note, not a ratified position, and its intent (same shape, replays identifiable) is met.

**(3) Replay eligibility is guarded on the cleaned signal, race-free.** Inside the endpoint's
transaction, the event row is re-read `WHERE payload_cleaned_at IS NULL … lockForUpdate()`
before any delivery row is inserted: either the endpoint commits its rows first (and GC's
compare-and-set erase then skips the event — hold H5/H2), or GC commits first (and the endpoint
sees the cleaned stamp and rejects — presented as expired-on-schedule, never an error, AC15).
Every downstream read re-guards (`ProcessIngestedWebhook`'s existing entry guard; ADR-015's
executor guard) per ADR-014 Decision 7 — a cleaned event can never produce a send (AC17).

**(4) Permission: one new `TeamPermission` case, `ReplayProxy = 'proxy:replay'`** — the exact
case ADR-009 anticipated. Granted in **all three** role bundles (Owner via `cases()`, Admin and
Member arms explicitly); **no `-any` ownership axis** — the Owner ruled the Q-02-01 ownership
rule does not apply to replay (AC14). `ProxyPolicy::replay()` is single-axis
(`hasTeamPermission($proxy->team, ReplayProxy)`); the page-level `ProxyPermissions` DTO gains
`canReplayProxy` for display (ADR-009 Amendment B posture: display client-side, enforcement in
the Policy).

**(5) The read surface: three GET routes and one POST, all inside the team-scoped group, all
resolving `{event}` by scoped binding through the proxy.**

| Route | Gate | Returns |
|---|---|---|
| `GET /proxies/{proxy}/events` | `ProxyPolicy::view` | Paginated descriptors + payload state + per-destination delivery state. **Never content.** |
| `GET /proxies/{proxy}/events/{event}` | `ProxyPolicy::view` | Descriptors, payload state, deliveries grouped original/replay with attempts. **Never content.** |
| `GET /proxies/{proxy}/events/{event}/payload` | `ProxyPolicy::view` | The raw payload bytes — the **only** content-bearing response in #6 (AC22/AC25). |
| `POST /proxies/{proxy}/events/{event}/replay` | `ProxyPolicy::replay` | Creates and dispatches the replay (Decision 1); Post/Redirect/Get + toast. |

Payload state on every read comes from `payload_cleaned_at` via the `StoredPayloadState`
mapping (`StoredPayloadLookup` remains the single resolver) — never from `body IS NULL`
(AC16/AC17; ADR-014 Decision 7).

**(6) Reveal is fetch-on-reveal — Q-06-03(5) answered as the Designer recommended (option b).**
The Inertia pages never receive `body` (or `headers`) in props; the masked viewer renders a
placeholder, and the explicit Reveal action fetches the payload endpoint. Grounds:
- **Defense in depth:** content is never resident in props/DOM/history state unless a user
  explicitly requested it — AC25's "never rendered unmasked without the user's explicit action",
  strengthened to *never delivered* without it.
- **Weight:** captured bodies run up to the ADR-006 cap (50 MB placeholder); embedding one in
  every detail-page visit is a correctness-adjacent performance hazard, not a taste call.
- **Auditability:** content egress becomes one endpoint that can log access
  (identifiers only — `payload.revealed`, never content) — the posture #10 will build on.

Response hardening (binding): `Content-Type: text/plain; charset=utf-8`,
`X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`; the client renders the
body exclusively as text (Vue text interpolation — never `v-html`). Cleaned ⇒ **410 Gone**
(lifecycle, not error); never captured ⇒ 404. The endpoint response is never logged, never
cached, never proxied into any resource or prop.

> **[Decision 6, response hardening — PROPOSED supersession by ADR-024 (pending Owner approval).]**
> **Narrowed to the non-JSON path only.** Under PRD-10 AC18 a stored payload that parses as JSON is
> returned as `application/json` carrying an obfuscation envelope; a payload that does not parse as
> JSON keeps this `text/plain` response verbatim (PRD-10 AC22). `nosniff`, `no-store, private`,
> never-logged, never-cached, never-a-prop, text-interpolation-never-`v-html`, the 410-on-cleaned
> mapping and the 404 are **unchanged and apply to both paths**.

## Alternatives
- **A parallel replay path (skip the pipeline, send stored bytes per destination directly)** —
  simpler today, but violates AC11 verbatim ("the same processing/dispatch path"), silently
  diverges the moment #9/#8 fill a transform seam, and creates the second path AC12 forbids.
  Rejected.
- **Scope `DeliverStep` by passing a destination-id list on the context** — carries request
  state where durable state is needed: a redelivered replay job would need the list again
  (serialized into the job — drifting from by-reference), and delivery rows must exist anyway
  for retry/terminal state (ADR-015). Pre-created rows make the job self-describing. Rejected.
- **`replay_of_id` on `delivery_attempts` (ADR-003's literal sketch)** — leaves attempt-1
  uniqueness broken for replays (the ADR-011 key), leaves AC4's per-delivery terminal state
  homeless, and denormalizes lineage per attempt row. The deliveries entity answers all three at
  one grain. Rejected.
- **A `replays` table (one row per replay action) instead of `dispatch_uuid` grouping** — a real
  entity for a fact `deliveries` already carries (kind + uuid + timestamps); adds a join to
  every read and a second insert to every replay for zero additional information at #6. Rejected
  (revisit only if a later item needs replay-level attributes, e.g. actor attribution — which
  the approved design explicitly declined).
- **DOM-present masked content (Q-06-03(5) option a)** — satisfies AC25's letter, fails its
  "optimize for deliberate exposure" spirit (content ships to every viewer's browser unrequested)
  and the 50 MB-cap weight problem. Rejected; the Designer's recommendation stands.
- **A JSON envelope (`{"body": …}`) for the payload endpoint** — `json_encode` fails on
  non-UTF-8 bytes (captured bodies are arbitrary), forcing base64 and a decode hop. Raw
  `text/plain` + `nosniff` is exact, binary-safe-enough for display, and simpler. Rejected.
  > **[ADOPTED for the JSON path only — this bullet's original "rejected" position is superseded
  > there by ADR-024 (PROPOSED, pending Owner approval); it still governs the non-JSON path.]**
  > The reasoning above is correct and is kept rather than deleted, with the correction attached:
  > it rests on captured bodies being arbitrary bytes, and that premise does not describe a body
  > which `json_decode` has already accepted — such a body is by definition valid UTF-8, so
  > re-encoding cannot fail and needs no base64 hop. On every payload that does **not** parse as
  > JSON the premise holds exactly as written, which is why ADR-024 keeps the raw `text/plain`
  > response there instead of wrapping it.
- **A distinct reveal permission or reveal-specific gate** — explicitly ruled out by the Owner
  (Q-06-02a / AC14/AC25): the proxy read permission is the gate. Not ours to reopen.
- **Signed/expiring URLs for the payload endpoint** — machinery for a session-authenticated,
  policy-gated, same-origin XHR; adds a bearer artifact (the signed URL) that can leak. Rejected.

## Reasoning
- **"No new machinery" holds.** Replay reuses: the capture row (source), the pipeline (path),
  `deliveries` (state, ADR-015), `fifo_dispatches` (ordering, ADR-016), the attempt
  records/events (stream), the retry engine (AC13), and the GC holds (AC18). #6's replay is a
  second *caller* of #4/#5's machinery, plus one identity field — precisely what ADR-011
  designed dispatch-by-reference for.
- **The read surface inherits every standing constraint by construction:** team-scoped routes +
  `TeamScope` + scoped bindings (cross-team ⇒ 404), Policy enforcement server-side with
  client-side affordance derivation (ADR-009 Amendment B), `$wrap = null` resources, paginator
  envelope — nothing new to ratify beyond the content endpoint itself.
- **PRD-05 AC16 lands where it was always going to land.** #5 deliberately shipped no read path
  and left AC16 as "the standing constraint on whoever adds the first read path (#6), which must
  be team-scoped and gated on the existing proxy read permission" — this ADR is that
  constraint's discharge, verbatim.
- **410 for cleaned content is the honest HTTP rendering of "expired on schedule"** — a
  permanent, expected absence, distinct from 404's "no such thing", matching PRD-05 AC10's
  "lifecycle, never data loss".

## Impact
- **Security-sensitive (Owner-gated ✋):** the first user-facing egress of stored payload
  content (decrypted raw bodies) — team-scoped, `proxy:view`-gated, fetch-on-reveal, no-store,
  never logged. The Owner ratifies the surface's existence and its gate being the read
  permission (as already ruled in Q-06-02a); this ADR fixes the mechanism.
- **Data-model:** none of its own — `deliveries` is ADR-015's, the `fifo_dispatches` change is
  ADR-016's. The `TeamPermission::ReplayProxy` case and role-bundle arms are code-level
  extensions of Accepted ADR-009 by its own documented extension recipe.
- **Pipeline envelope:** `PipelineContext` gains `dispatchUuid` (readonly, defaulted) —
  a deliberate, recorded ADR-001 extension; construction sites are unchanged
  (`ProcessIngestedWebhook` is the only one).
- **Easier later:** #8 gets replay-with-current-mapping for free (the pipeline re-runs);
  #10 layers content policy on a single egress endpoint; #14's test-payload send has a worked
  example of "dispatch that is not an ingest".
- **Constrained:** the payload endpoint is the **only** content-bearing response #6 may add
  (AC22 binds every other read path to descriptors + states); replay targets only the proxy's
  current live destinations (AC10 — no ad-hoc URLs, no trashed destinations at selection time);
  the replay endpoint must lock-and-recheck the cleaned signal inside its transaction; nothing
  in this surface may read `dispatched_payloads.body` (the design ruled the viewer raw-only;
  ADR-013 Decision 3 confines its interpretation to `StoredPayloadLookup`).
