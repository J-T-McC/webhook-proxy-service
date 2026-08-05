# Review: Queued processing (FIFO & Async) — item #4

- **Reviewer / date:** Reviewer, 2026-08-04
- **Scope:** T1–T25 on `feat/item-04-queued-processing` (draft PR #5), all production +
  test code for queued dispatch, the Async/FIFO processing mode, the FIFO
  claim-based single-advancer + sweeper, delivery idempotency, and the
  processing-mode UI (form/Show/Index).
- **Inputs verified:** PRD-04 (Approved, 13 ACs), plan-04 (PE-self-certified),
  ADR-011 (Accepted) + ADR-005 guardrails (a)–(d), design-04 (Approved, both PM
  rulings), tasks-04 (T1–T25 with completion notes), `docs/standards/` (review,
  coding, planning, testing, architecture, design). All three gates run locally.

## Summary
The implementation faithfully realizes ADR-011 and plan-04: capture still commits
synchronously before the response (ADR-010), the response resolves from proxy config
independent of delivery (ADR-004), dispatch is `afterCommit` by reference, the FIFO
sidecar row commits in the same transaction as capture, and delivery is idempotent
under a `UNIQUE(ingest_id, destination_id, attempt_number)` index. All three gates are
**green**: `composer lint` passed, `composer types:check` 0 errors, `./vendor/bin/sail
test` **353 passed / 1348 assertions**. The frontend triad is green (`pnpm
types:check` / `lint:check` / `format:check`) and every UI surface matches design-04 by
inspection. One **Major** liveness gap blocks approval: the advancer's
`WithoutOverlapping` lock has no TTL, so an ungraceful worker crash can permanently
stall a proxy's FIFO line despite the sweeper — the exact failure mode the sweeper
exists to cover. Three Minors are non-blocking.

## Gate results (run by the Reviewer)
| Gate | Command | Result |
|---|---|---|
| Lint | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` |
| Backend tests | `./vendor/bin/sail test` | `{"tool":"phpunit","tests":353,"passed":353,"assertions":1348}` |
| FE types | `pnpm types:check` (vue-tsc) | clean |
| FE lint | `pnpm lint:check` (eslint) | clean |
| FE format | `pnpm format:check` (prettier) | clean |

`pnpm run build` not run — Node 21 < required 22 in this sandbox (documented env
limitation, not a failure); SFCs validated by vue-tsc + eslint instead.

## AC coverage (PRD-04)
| AC | Verified by | Status |
|---|---|---|
| 1 Queued dispatch, no inline delivery | `IngestController` dispatches `afterCommit`; T13 asserts no `DeliveryAttempt` at return, both modes | Pass |
| 2 #3 response preserved | Response resolved before dispatch (`IngestController:84`); T13 drains a 500/throwing destination, response unchanged | Pass |
| 3 Capture-before-response | Capture in `DB::transaction` before resolve/dispatch; T12/T13 capture-failure → 500 + `assertNothingPushed` | Pass |
| 4 Per-proxy mode persisted | `processing_mode` enum column + fillable/validated; T19/T20 store+update | Pass |
| 5 Async default | Migration default `'async'`, no backfill; T4/T14 factory row reads `Async` | Pass |
| 6 FIFO received order | Atomic claim + `orderBy('webhook_event_id')`; T15 delivery order = receive order | Pass (see Minor 2 on concurrency scope) |
| 7 Per-proxy, no cross-proxy block | `fifo_dispatches` FIFO-only; T15 isolation case | Pass |
| 8 Attempt records/events unchanged | `DeliverToDestination` writes same payload-free record + events; T14 asserts per-destination | Pass |
| 9 Exactly-once under redelivery | `UNIQUE` index + skip-if-terminal guard; T9/T17 | Pass |
| 10 Independent destinations | `DeliverStep` loop never aborts; T14 one-fails-others-succeed | Pass |
| 11 No retry/replay | `$tries=1`, no backoff/dead-letter built | Pass |
| 12 Redis transport | No new dep; queue config only | Pass |
| 13 No SLA | No numeric target asserted | Pass |

Every task T1–T25 carries completion notes. UI (T23–T25) is inspection-verified
against design-04 (no JS test harness — deferred backlog T31; acceptable per
`docs/standards/review.md` Frontend section and #1/#3 precedent).

## Findings
| # | Severity | Location | Finding |
|---|---|---|---|
| 1 | **Major** | `app/Actions/AdvanceProxyFifoQueue.php:99-102` | `getJobMiddleware` returns `new WithoutOverlapping("proxy:{$proxyId}")` with **no `expireAfter()`** (framework default `$expiresAfter = 0` → the Redis lock has no TTL; verified in `vendor/.../WithoutOverlapping.php:57,71-85`). On an **ungraceful** worker crash (SIGKILL/OOM/power loss) while an advancer holds the lock, the lock leaks with no expiry. `SweepStalledFifoDispatches` correctly reaps the DB claim (`claimed`→`pending`) after the lease expires and re-dispatches `AdvanceProxyFifoQueue`, **but that job runs the same middleware** — `$lock->get()` fails, `job->release(0)` re-queues it immediately, and the loop repeats forever. The proxy's FIFO line **never advances** until the lock key is manually cleared. This defeats the ADR-005 (b) / plan-04 §Services liveness guarantee "under worker crash/deploy" — precisely the scenario the sweeper exists to cover. Other proxies are unaffected (per-proxy lock), so no PRD AC is directly violated, but a stated plan/ADR guarantee is materially undermined. |
| 2 | Minor | new FIFO tests (see below) | Single-advancer/ordering under **true** concurrency is not proven (and cannot be in single-connection PHPUnit). T16's "single-advancer under contention" proves the already-committed-claim short-circuit, not the window where two advancers both pass the live-claim check before either commits. In that window the atomic claim alone can let the second advancer skip a just-claimed row and claim the **next** one (adjacent-row double-claim / out-of-order). Production ordering therefore leans on `WithoutOverlapping` serialization — which ADR-011 frames as "not the guard." With finding #1 fixed (overlap lock present + TTL) this holds in production; flag as residual risk for a real-concurrency integration test if a harness is ever added. |
| 3 | Minor | `tests/Feature/Ingest/FifoLivenessAcceptanceTest.php:43`, `tests/Unit/Actions/AdvanceProxyFifoQueueTest.php:39`, `tests/Unit/Actions/SweepStalledFifoDispatchesTest.php:25` | `FifoDispatch::factory()->create([...])` used instead of `createQuietly()` — violates testing.md → Quiet factory creation ("never `create()`"). Benign here (the factory sets `team_id` explicitly, so `BelongsToCurrentTeam`'s `creating` hook is a no-op), but the standard is absolute. |
| 4 | Minor | `tests/Feature/Proxies/ProcessingModeSwitchAcceptanceTest.php` (T18) | Mode switch exercised at the model level (`$proxy->update`) not the HTTP endpoint. Adequately covered: endpoint persistence is T20 (`async→fifo→async` on update) and endpoint validation is T19. Noted for transparency, not a defect — the SE's judgment call is sound. |

**Not findings (checked, acceptable):** T7/T19 caller ripple (the
`ProcessIngestedWebhook` signature change forcing `IngestController`, and the required
`processing_mode` field forcing test-payload updates) are necessary in-scope
consequences, not scope creep. Migrations match ADR-011 exactly (enum NOT NULL default
`async`; `fifo_dispatches` UNIQUE(webhook_event_id) + composite index, no soft-delete,
no payload column; `delivery_attempts` composite UNIQUE preserving existing indexes).
No secret/token/raw-body logged on the worker path. Response contract, capture
placement, and attempt-record shape all unchanged.

## Recommendations
- **Finding #1 (Major) — blocks approval.** Return to the Senior Developer: give the
  advancer's `WithoutOverlapping` an explicit TTL (e.g. `->expireAfter(...)` aligned to
  or above `ingest.fifo_lease_seconds`) so a leaked lock self-heals within the lease
  window and the sweeper can actually restart a crashed line. Add a test that proves
  end-to-end recovery of a stalled line after a leaked overlap lock (or at minimum
  documents the TTL relationship to the lease). Re-review on fix.
- **Finding #2 (Minor)** — follow-up: record the concurrency-proof limitation; add a
  real-concurrency test when/if an integration harness lands.
- **Finding #3 (Minor)** — follow-up: swap the three `->create(` calls to
  `->createQuietly(`.
- **Finding #4** — no action.

## Approval
- **Recommendation:** Request changes (one Major, #1). The Async/queued half, the
  data model, idempotency, and the entire UI are clean; the block is isolated to the
  FIFO advancer's lock TTL and its interaction with the sweeper's liveness net.
- **Project Owner decision / date:** _pending_

## Re-review (2026-08-04)

Focused re-review of the Senior Developer's rework on `feat/item-04-queued-processing`
after the initial **Request changes** (Major #1 + Minors). Verified the fix, re-ran all
gates, and confirmed the deferred Minors did not regress.

### Major #1 (advancer lock TTL) — RESOLVED
`AdvanceProxyFifoQueue::getJobMiddleware` (`app/Actions/AdvanceProxyFifoQueue.php:115-121`)
now returns `(new WithoutOverlapping("proxy:{$proxyId}"))->expireAfter((int) config('ingest.fifo_lease_seconds'))`.
Confirmed this closes the leaked-lock deadlock:
- **TTL == lease is the correct upper bound.** The overlap lock is acquired by the
  `WithoutOverlapping` middleware *before* the job handle runs; `lease_expires_at` is
  stamped later, inside `claimNext` (`:87`). So with T0 = lock-acquire and T1 > T0 =
  lease-stamp, the lock expires at `T0 + lease` ≤ `T1 + lease` = the claim-lease expiry.
  `SweepStalledFifoDispatches` only reaps/re-drives once `lease_expires_at < now`
  (`:31-33`), i.e. after `T1 + lease` — by which point the leaked lock (gone at
  `T0 + lease`) has certainly expired, so the re-dispatched advancer can always
  reacquire. A TTL longer than the lease would push lock-expiry past the reap point and
  re-open the deadlock; equal is the right upper bound. SE reasoning confirmed correct.
- Config key exists: `config/ingest.php:75` → `fifo_lease_seconds` default 90s (same
  value the sweeper's reaper keys on), so lock TTL and claim lease are provably the
  same quantity, not two drifting constants.

### Recovery test — genuine, not a tautology
`tests/Feature/Ingest/FifoLivenessAcceptanceTest.php:163`
(`test_a_leaked_overlap_lock_self_heals_within_the_lease_and_the_line_advances`)
drives the **real sync queue** (no `Queue::fake`), so the sweeper's dispatched advancer
actually runs through the production `WithoutOverlapping` middleware. It:
- resolves the leaked lock via the *exact* production key + TTL from
  `getJobMiddleware($proxy->id)` (not a hand-rolled key), and asserts
  `$overlap->expiresAfter === config('ingest.fifo_lease_seconds')` (`:184`);
- proves the **stall while held**: sweep #1 reaps the stranded claim to `pending` and
  dispatches the advancer, which is blocked by the held lock — row stays `pending`, `0`
  delivery attempts (`:195-196`);
- proves the **advance after expiry**: releases the lock, sweep #2 re-drives, the row
  settles, `1` delivery attempt, `1` HTTP send (`:205-207`).
  This is a real block-then-recover proof against the production lock, not a tautology.
  One acceptable limitation: it uses `forceRelease()` to stand in for the TTL elapsing
  rather than waiting `fifo_lease_seconds` of wall-clock — bridged by the explicit
  `expiresAfter` assertion that ties the simulated release to the real TTL. Sound.

### Minors
- **createQuietly (was finding #3) — RESOLVED.** All three FIFO test files now use
  `->createQuietly(` for `FifoDispatch`/`WebhookEvent`/`Proxy`/`Destination`:
  `FifoLivenessAcceptanceTest.php:35-45`, `AdvanceProxyFifoQueueTest.php:29-39`,
  `SweepStalledFifoDispatchesTest.php:20-36`. No bare `->create(` remains in them.
- **Concurrency-proof limitation (finding #2) — deferred, no regression.** The
  contention tests (`FifoLivenessAcceptanceTest` `test_a_second_advancer_...`,
  `test_no_two_rows_are_ever_claimed_simultaneously_under_contention`) are intact;
  carries to backlog as a real-concurrency integration test if a harness ever lands.
- **T18-via-model (finding #4) — no action, no regression.** Endpoint persistence (T20)
  and validation (T19) still cover the HTTP path.

### Gate results (re-run by the Reviewer, 2026-08-04)
| Gate | Command | Result |
|---|---|---|
| Lint | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` |
| Backend tests | `./vendor/bin/sail test` | `{"tool":"phpunit","result":"passed","tests":354,"passed":354,"assertions":1355}` (+1 test, +7 assertions vs. initial review — the new recovery test) |
| FE types | `pnpm types:check` (vue-tsc) | clean |
| FE lint | `pnpm lint:check` (eslint) | clean |
| FE format | `pnpm format:check` (prettier) | clean |

`pnpm run build` not run — Node 21 < required 22 in this sandbox (documented env
limitation, unchanged); SFCs validated by vue-tsc + eslint.

### Re-review recommendation
**Approve with follow-ups.** Major #1 is fully resolved — fix is correct, the TTL/lease
relationship is provably the right upper bound, and the new recovery test genuinely
exercises it end-to-end against the production lock. Minor #3 (createQuietly) resolved.
No blockers remain. Two non-blocking follow-ups carry to backlog: (i) a real-concurrency
integration test for the single-advancer window (finding #2), (ii) optionally a
model→HTTP consolidation for T18 (finding #4). Final call rests with the Project Owner.

- **Project Owner decision / date:** _pending_

## Handoff
- **Inputs:** PRD-04, plan-04, ADR-011/ADR-005, design-04, tasks-04, `docs/standards/`,
  branch `feat/item-04-queued-processing`.
- **Outputs:** this review.
- **Dependencies:** finding #1 fix must precede merge; the two Minor follow-ups can be
  bundled or deferred at the Owner's discretion.
- **Outstanding Questions:** none — V8/V3 remain Owner-deferred (no SLA asserted), not
  gating.
- **Next Agent:** Senior Developer (Major #1 rework), then Reviewer (re-review), then
  Project Owner (approval / merge).
