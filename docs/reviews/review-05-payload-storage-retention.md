# Review: Payload storage & retention — item #5

- **Reviewer / date:** Reviewer, 2026-08-05
- **Scope:** T1–T18 on `feat/item-05-payload-storage-retention` (27 commits ahead of
  `main`, HEAD `e544dce`, working tree clean) — all production + test code for the
  retention window, the erase-in-place garbage collector, the `dispatched_payloads`
  output store, the `webhook_events` schema change, at-rest header encryption, and the
  AC21 cleaned-state signal. No UI in scope (Q-05-01 Option B — no Designer gate).
- **Inputs verified:** PRD-05 **Approved + Amendment A** (AC1–AC22 as amended, D1),
  plan-05 (PE-self-certified, re-certified; § Revision A), ADR-012 / ADR-013 / ADR-014
  (all Accepted, Owner 2026-08-05), ADR-010 (two positions superseded), ADR-011,
  ADR-008, ADR-003, ADR-002, tasks-05 (T1–T18, **all carrying completion notes**),
  `docs/standards/` (review, coding, testing, documentation, architecture). All three
  backend gates run locally by the Reviewer.

## Summary
The implementation is a faithful, high-quality realisation of the erase-in-place design.
Every superseded artefact of the deletion-era plan is genuinely gone: nothing is deleted
anywhere, GC writes only `webhook_events` and `dispatched_payloads`, the holds H0–H4 are
expressed **once** in `applyHolds()` and applied **identically** to the selection query and
to the mutating `UPDATE`'s own `WHERE`, and both stores are erased inside one
`DB::transaction`. `AdvanceProxyFifoQueue` is **byte-identical to `main`** (MD5 verified).
`StoredPayloadLookup` is the only state resolver and a repository-wide grep confirms no
reader anywhere guards on `body === null` — plan Risk 2's silent-empty-dispatch hazard is
closed. Header encryption is transparent to ADR-008 forwarding. The T14 atomicity proof and
the T15 reappeared-hold race proof are both **genuine, not tautological** (analysis below).
All three gates are **green**: `composer lint` passed, `composer types:check` 0 errors,
`./vendor/bin/sail test` **423 passed / 1537 assertions** — the Senior Developer's claimed
numbers reproduce exactly.

One **Major** blocks approval: plan §Validation's *Config sanity* invariant was not
implemented, leaving two reachable failure modes on the most irreversible operation in the
product — a resolved retention window of ≤ 0 days erasing every payload in the system, and
a resolved purge batch of 0 hanging the daily scheduled command in an infinite loop. Both
are proven below; the fix is a few lines. Two Minors and three Nits are non-blocking.

## Gate results (run by the Reviewer)
| Gate | Command | Result |
|---|---|---|
| Lint | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` (L7, no baseline file) |
| Backend tests | `./vendor/bin/sail test` | `{"tool":"phpunit","result":"passed","tests":423,"passed":423,"assertions":1537,"duration_ms":8617}` |

No frontend gates run — #5 ships **no** UI, route, prop, or SFC change (verified: the
branch diff touches no `resources/` file). SE's reported numbers verified independently and
match exactly.

## AC coverage (PRD-05, as amended by Amendment A)
| AC | Verified by | Status |
|---|---|---|
| 1 Expiry measured from capture | H1 `created_at <= cutoff` (`PurgeExpiredPayloads.php:143`); `RetentionExpiryTest` 31d-cleaned/29d-untouched, and a recent terminal attempt does **not** reset the clock | Pass |
| 2 Window is 30 days | `config/retention.php:17` default 30; `RetentionConfigTest` | Pass — but see **Major 1** (no lower bound) |
| 3 Retention is team-level | `RetentionPolicy::windowFor(Team)` is the sole seam; `cutoffFor`/`expiresAt` compose through it; container-substituted subclass cleans only the other team | Pass |
| 4 Both proxy modes | `RetentionExpiryTest::test_simple_and_enhanced_mode_proxies_raw_payloads_are_both_cleaned` | Pass |
| 5 Automatic + recurrent | `routes/console.php:28-32` `daily()->at('02:00')->withoutOverlapping()`; `Schedule::events()` assertion (`0 2 * * *`, `withoutOverlapping === true`); `artisan payloads:purge-expired` exit 0 | Pass |
| 6 Erasure complete (content, not record) | `body`/`headers`/`dispatched_payloads.body` NULL asserted at the **raw column** level; retained descriptors byte-identical; **only three `Log::` calls exist in `app/`**, all identifiers/counts (`payload.expired` → `ingest_id`; `payload.purged` → `team_id`+`count`) — no payload content, no hash, no prefix, no preview anywhere | Pass — see **Minor 2** |
| 7 Unexpired never erased | 29-day event byte-for-byte identical incl. `updated_at`; whole-row `assertEquals` | Pass |
| 8 In-flight not eligible | H2 (`fifo_dispatches` non-settled), H3 (`delivery_attempts` `dispatched`), H4 (horizon) + compare-and-set; five acceptance cases incl. FIFO-liveness-under-GC; `AdvanceProxyFifoQueue` MD5-identical to `main` | Pass — see **Minor 1** |
| 9 Delivery history survives | `delivery_attempts` **and** `fifo_dispatches` rows whole-row `assertEquals` before/after a pass; GC never writes either table (`applyHolds` reads only) | Pass |
| 10 Expiry is a normal state | Both guards `return` cleanly, throw nothing; `firstOrFail()` retained so an absent row still throws (a bug, never expiry) | Pass |
| 11 Immutable while retained; erase permitted | Exactly three columns written via the **query builder** — `updated_at` proven unchanged; output write proven not to touch the parent row | Pass |
| 12 Output stored (enhanced) + same pass, atomic | One `DB::transaction` per event, second `UPDATE` gated on `$affected === 1`; fault-injection rollback test | Pass |
| 13 One output per received event | `UNIQUE(webhook_event_id)` + `updateOrCreate`; 3-destination fan-out yields 1 row; redelivery yields 1 row | Pass |
| 14 Storage not separately toggleable | No `proxies` change; step lives inside the existing `ProxyMode::Enhanced` branch; simple mode produces zero rows | Pass |
| 15 At-rest floor preserved/raised | `encrypted` on `webhook_events.body`, `encrypted:array` on `headers`, `encrypted` on `dispatched_payloads.body`; GC writes literal `NULL` (no cleartext intermediate); `byte_size` computed pre-cast | Pass |
| 16 Access team-scoped/permission-gated | No read path, route, resource, prop, or policy added — nothing to gate at #5; `BelongsToCurrentTeam` present as future defence only | Pass (N/A by design) |
| 17 No retry/replay | None added | Pass |
| 18 No mode toggle | None added | Pass |
| 19 No mapping | Divergence gate only **reads** `$ctx->payload`/`$ctx->rawBody`; explicit context-untouched test | Pass |
| 20 No numeric target | None asserted | Pass |
| 21 Cleaned state explicitly signalled | `payload_cleaned_at` + `StoredPayloadState{Retained,Cleaned,NeverCaptured}` + `StoredPayloadLookup` as sole resolver; repo-wide grep confirms **zero** `body === null` guards; `NeverCaptured` proven even when `delivery_attempts` rows exist | Pass |
| 22 Headers encrypted at rest + cleared on expiry | Raw column is ciphertext over the **real capture path** (header name, value and `content-type` all absent from the stored string); attribute round-trips; nulled by the same pass; `content_type` survives; ADR-008 `STRIPPED_HEADERS` filtering unchanged (`DeliverStep`/`DeliveryUnit` untouched) | Pass |

Every task T1–T18 carries completion notes. No UX Direction section exists in PRD-05 and no
UI is added, so the absent Designer gate is correct, not a skipped phase.

## Targeted scrutiny findings (the eight areas raised)

**1. AC6 completeness — genuinely unrecoverable.** After a pass the raw body, the captured
header collection and the dispatched output body are all `NULL` at the raw-column level.
What survives is exactly the AC6-permitted descriptor set (`method`, `content_type`,
`byte_size`, `received_at`, `ingest_id`, `team_id`, `proxy_id`, `created_at`,
`payload_cleaned_at`), plus `dispatched_payloads.byte_size`/`dispatched_at`. `content_type`
is denormalised at capture from the **in-memory** header array (`WebhookEventCapture::contentTypeFrom`,
unchanged), so it needs no exception carved into the erasure — matching ADR-014 Decision 6.
**Logs are clean:** this feature introduces the first three `Log::` calls in `app/` and
every one passes identifiers or counts only; nothing on the never-log list
(`coding.md` → *Never log*) is emitted. No truncated, prefixed, previewed, summarised or
hashed copy exists anywhere in the schema. `delivery_attempts.error_summary` holds a
250-char **exception** message from the outbound HTTP client (destination-side response or
transport error), never the inbound payload, and is not written by the pass.

**2. AC12 atomicity — the T14 proof is genuine.**
`RetentionErasureCompletenessTest.php:112-116` registers a `DB::listen()` closure
that throws when it sees `` update `dispatched_payloads` ``. `Connection::run()` dispatches
`QueryExecuted` **after** the statement has executed against the open transaction, so at
throw time the `webhook_events` `UPDATE` has already run *and* the `dispatched_payloads`
`UPDATE` has already run — the exception then unwinds through the real `DB::transaction()`
wrapper, which rolls both back. The test proves this observationally, not structurally: it
asserts the whole `webhook_events` row is byte-identical afterwards and
`payload_cleaned_at` is still `NULL` (`:131-133`). A tautological version would have
injected the fault before the first `UPDATE` or asserted on the presence of
`DB::transaction` in source; this does neither. One recorded limitation: under
`FasterRefreshDatabase` the inner transaction is a **savepoint**, so the test exercises
`ROLLBACK TO SAVEPOINT` rather than a top-level `ROLLBACK`. Laravel's transaction manager
handles both identically and no cheaper proof exists in this harness — sound, noted as
Nit 3.

**3. The compare-and-set — asserted in the mutating statement, and the race test exercises
the real window.** `applyHolds()` (`PurgeExpiredPayloads.php:139-163`) is one private method
applied to **both** `selectCollectableIds()`'s `SELECT` and `eraseOne()`'s `UPDATE` — the
holds cannot drift apart by construction. T15's
`test_a_hold_that_reappears_between_selection_and_erase_causes_the_erase_to_affect_zero_rows`
(`RetentionInFlightHoldsTest.php:114-150`) hooks `DB::listen` on the **selection**
query (`` select `id` from `webhook_events` ``) and inserts a `pending` `fifo_dispatches`
row from inside the listener. Because `QueryExecuted` fires after the result set is fetched
but before `selectCollectableIds()` returns, the insert lands **exactly** in the
selection→erase window: the id was already selected, and the erase then finds H2 violated.
The assertions (row byte-identical, `payload_cleaned_at` still `NULL`) can only hold if the
`UPDATE`'s own `WHERE` re-evaluated the hold. This is the real window, not a simulated one.

**4. AC8 / #4 composition.** `AdvanceProxyFifoQueue.php` is **byte-identical to `main`** —
`git diff main..HEAD` is empty and both MD5s are `7aa3c7c350d04048c3900c4ebff64e67`. GC and
#4 share **zero written tables**. T15's FIFO-liveness case runs a full GC pass over three
fully-expired events while row 1 is live-claimed: the claim, `claimed_at`,
`lease_expires_at` and the pending set are all unchanged, nothing is cleaned, and after
settling the frozen claim the line still delivers in receive order (`['evt-2','evt-3']`).
T16 additionally proves the unmodified advancer settles and advances past a cleaned claim
with no stall and no exception. See **Minor 1** for one narrow hold gap that is a plan-level
question, not an implementation deviation.

**5. AC21 — every reader guards on the signal.** Repository-wide grep finds exactly two
guards (`ProcessIngestedWebhook.php:37`, `CaptureDispatchedStep.php:50`), both on
`payload_cleaned_at !== null`, and **zero** occurrences of a `body === null` /
`headers === null` guard outside documentation prose. `StoredPayloadLookup` is the only
resolver of the three states and nothing re-derives them. `WebhookEvent` and
`DispatchedPayload` sit outside `ApplyTeamScope::MODELS`, so no global scope silently alters
the resolver's result on the worker path. Plan Risk 2 is closed.

**6. AC15 / AC22.** `headers` is `MEDIUMTEXT NULL` cast `'encrypted:array'`; the real-capture
acceptance test proves the stored string contains neither the header name, its value, nor
`content-type` in plaintext, while the attribute round-trips exactly. No plaintext copy is
introduced: `content_type` is the single Owner-ruled retained descriptor. ADR-008 forwarding
is untouched — `DeliverStep`/`DeliveryUnit` are not in the branch diff, and the existing
`IngestFanOutTest::test_header_forwarding_end_to_end` and `IngestEventCaptureTest`
pass unmodified.

**7. The T4 migration.** Correctly implements all four steps: `body` `MODIFY`'d to
`LONGBLOB NULL` (value-preserving), `headers` **dropped and re-added** as `MEDIUMTEXT NULL
AFTER method` (column order preserved), `payload_cleaned_at TIMESTAMP NULL AFTER byte_size`,
and the `(team_id, payload_cleaned_at, created_at)` index — index presence confirmed
directly in MySQL. The docblock states the destructiveness in capitals, names the Owner
basis (ADR-014 Decision 2, "no production data to protect"), and states plainly that
`down()` is best-effort and does not round-trip, with the reason. This is exactly the
documentation the Owner-approved shape called for.

**8. N+1 / efficiency in the GC loop.** `RetentionPolicy::cutoffFor($team)` is resolved
**once per team** from the `Team` already in hand (`purgeForTeam:65`), and the class docblock
explicitly records why `expiresAt()`'s per-event `Team::withTrashed()->findOrFail()` resolver
is *not* used. No per-row team resolution exists. The `whereNotExists` correlations hit
`fifo_dispatches_webhook_event_id_unique` and `delivery_attempts.ingest_id` indexes. See
Nit 1 for a small ordering refinement.

## Findings
| # | Severity | Location | Finding |
|---|---|---|---|
| 1 | **Major** | `config/retention.php:17,31`; `app/Services/RetentionPolicy.php:27`; `app/Actions/PurgeExpiredPayloads.php:67,82` | **Plan §Validation's *Config sanity* invariant is not implemented.** The plan states it normatively — "`retention.days` must be a positive integer; `purge_batch` a positive integer … A non-positive window would make every payload instantly collectable — read via `(int)` casts with the documented defaults and **never allow a resolved window of zero or less**" — under a section headed "system-side invariants **the implementation must uphold**". The code performs the `(int)` cast and supplies the defaults but implements no lower bound anywhere, and no test covers one (`RetentionConfigTest` asserts defaults and overrides only). Two reachable failure modes follow. **(a) Unrecoverable mass erasure.** `RETENTION_DAYS=` (blank line) or any non-numeric value casts to `0`; `RETENTION_DAYS=-1` is worse. `windowFor()` then returns `CarbonInterval::days(0)` and `cutoffFor()` returns `now()`, so H1 (`created_at <= cutoff`) admits **every** captured event, and the next 02:00 pass permanently erases every payload body, header collection and dispatched output in the system — subject only to H2/H3/H4, which hold nothing back beyond the 60-minute H4 horizon. There is no soft delete, archive or recovery path (PRD flag 6; plan Risk 1: "A bug in … the cutoff arithmetic destroys data that cannot be restored"). **(b) Infinite loop in the scheduled command.** `RETENTION_PURGE_BATCH=` or `0` makes `$batchSize === 0`; `selectCollectableIds()` then issues `LIMIT 0` — verified in this sandbox: `DB::table('webhook_events')->limit(0)` emits `select * from \`webhook_events\` limit 0` and returns 0 rows — so the terminating condition `while (count($ids) === $batchSize)` (`:82`) evaluates `0 === 0` and the `do/while` never exits, hanging `payloads:purge-expired` on the **first** team forever (and, with `withoutOverlapping()`, blocking every subsequent run). Neither mode is defended in depth by anything else in the pass. *Criterion violated:* plan-05 §Validation → *Config sanity* (explicit); compounded by plan-05 Risk 1 and Owner-approval flag 6. |
| 2 | Minor | `app/Actions/PurgeExpiredPayloads.php:150-162` (holds H3/H4) | **A partially fanned-out Async event can pass all holds while a dispatch is still outstanding.** In Async mode `DeliverStep` queues one `DeliverToDestination` job per destination (`DeliverStep.php:51-57`) and each job creates its own `dispatched` attempt row only **when it runs** (`DeliverToDestination.php:52-60`). For a multi-destination event, a state exists where destination A's attempt is terminal (`succeeded`) and destination B's job is still sitting on the `webhooks` queue with no row yet: H3 finds no `dispatched` row, and H4's `whereExists` is satisfied by A's row, so both holds clear and the event is marked cleaned while B's dispatch is outstanding. AC8's letter — "in flight under Async — is **not** erased while that dispatch is outstanding" — is not met in that window. **No data is lost** (the queued `DeliveryUnit` carries its own bytes and never re-reads the event) and the window requires a fan-out still pending 30 days after capture, so exposure is effectively nil. Importantly this is a **gap in the plan's hold set, faithfully implemented** — the plan's Q-05-03(i) analysis reasons about "zero attempt rows" and "a `dispatched` row" but not about the partial-fan-out state — so it is not an implementation deviation. *Owning agent:* **Principal Engineer** (question doc against plan-05 / ADR-012 Decision 4), not the Senior Developer. |
| 3 | Minor | `config/queue.php:123-127`; `app/Actions/DeliverToDestination.php:62-68` | **`failed_jobs` durably retains a plaintext copy of payload content outside the GC's reach.** An Async `DeliverToDestination` job that rethrows (the non-race `QueryException` path, with `$tries = 1`) is written to the `failed_jobs` table with its serialized `DeliveryUnit`, which carries `payload` and `headers` verbatim. That row is never erased by the expiry pass and has no retention of its own, so payload content for a cleaned event remains readable through a system path — the broad reading of AC6 ("none of that event's payload content is retrievable through **any** … system path"). This is **pre-existing from #4/ADR-011**, not introduced or worsened by #5, and no plan or ADR at #5 asks for it; AC6's "as a side effect of the pass" clause is not violated. Recorded so it is not silently inherited by #6/#10. *Owning agent:* Product Manager (is this in AC6's scope?) then Principal Engineer — **not** a code change at #5. |
| 4 | Nit | `app/Actions/PurgeExpiredPayloads.php:95` | `->orderBy('id')` forces a `filesort` over the collectable set. Verified with a 50 000-row / 90 %-cleaned scratch table and `ANALYZE`: the query **does** use `team_cleaned_created` (`type: range`, `Using index`) so plan Risk 10's index intent is preserved — but `ORDER BY id` adds `Using filesort`, materialising and sorting all ~1 666 candidate rows before applying `LIMIT 500`, whereas `ORDER BY created_at` (the index's trailing column) produces the identical plan **without** the filesort and can stop at 500. Determinism is unaffected. No AC is bound to this (AC20 asserts no performance target). |
| 5 | Nit | `app/Actions/PurgeExpiredPayloads.php:79-81` | `Log::info('payload.purged', …)` fires once per **batch iteration**, inside the `do/while`, so a team with more than `purge_batch` collectable rows emits several partial-count lines per run rather than one total. Cosmetic; content is correct and never includes payload data. |
| 6 | Nit | `tests/Feature/Retention/RetentionErasureCompletenessTest.php:96-134` | The AC12 atomicity proof exercises `ROLLBACK TO SAVEPOINT` (the suite's outer `FasterRefreshDatabase` transaction) rather than a top-level `ROLLBACK`. Behaviourally equivalent under Laravel's transaction manager and no cheaper proof exists in this harness — recorded as a known limitation of the evidence, not a defect. |

**Not findings (checked, acceptable):** `RetentionPolicy::windowFor`'s unused `$team`
parameter is the deliberate V5/V6 seam (plan §Services). `StoredPayloadLookup` and
`expiresAt()` having no consumer at #5 is plan-authorised (named homes for #6), not dead
code. `Actions::registerCommands()` in `routes/console.php` was a genuine necessity for
`Schedule::command()` and exposes exactly one new Artisan command
(`payloads:purge-expired` — verified against `artisan list`), because laravel-actions
registers only classes declaring `commandSignature`. `WebhookEventFactory::cleaned()` sets
the non-fillable `payload_cleaned_at` legitimately (factories run under
`Model::unguarded`). `CaptureDispatchedStep`'s `lockForUpdate()` compare-and-set on the
parent correctly interlocks with the GC's own row lock in **both** orderings, closing plan
Risk 4. `dispatched_payloads`'s `cascadeOnDelete` deviates from the house restrict-FK rule
but is the Owner-approved shape and is documented as orphan prevention only, explicitly not
an AC6/AC12 mechanism. Every new test uses `createQuietly()`, none declares
`RefreshDatabase` (testing.md, both satisfied). Migrations, model casts, `#[Fillable]`
sets, and index shapes match ADR-013/ADR-014 verbatim.

## Recommendations
- **Finding 1 (Major) — blocks approval.** Return to the Senior Developer: enforce the
  plan's *Config sanity* invariant at the single point each value is resolved — clamp or
  reject a non-positive `retention.days` in `RetentionPolicy::windowFor()` (the plan's
  designated sole seam), and a non-positive `purge_batch` where `$batchSize` is read in
  `purgeForTeam()`; treat `dispatch_horizon_minutes` as non-negative. Failing loudly is
  preferable to silently falling back for `retention.days`, given the operation is
  irreversible. Add unit coverage for each: a zero/blank/negative/non-numeric env value must
  never yield a window of ≤ 0 and must never yield a batch size of 0. Re-review on fix.
- **Finding 2 (Minor)** — question doc to the **Principal Engineer**: does AC8 require a
  hold covering the partial-fan-out window, or is the ADR-012 Decision 4 hold set complete
  as ruled? Not a Senior Developer task until that is answered.
- **Finding 3 (Minor)** — question doc to the **Product Manager** on AC6's reach over
  `failed_jobs`, then to the Principal Engineer if in scope. Carry to backlog; do not widen
  #5.
- **Findings 4–6 (Nits)** — optional follow-ups; 4 is a one-word change worth bundling with
  the Major fix, 5 and 6 need no action.

## Approval
- **Recommendation:** **Request changes** (one Major, finding 1). Everything the Owner's
  ruling turned on is correct and well proven — erase-in-place, the two-store single
  transaction, the compare-and-set, the AC21 signal and its sole resolver, header
  encryption, and the untouched FIFO machinery. The block is narrow and mechanical: the
  plan-mandated config guard on the one operation that cannot be undone.
- **Project Owner decision / date:** _pending_

## Re-review (2026-08-05)

Focused re-review of the Senior Developer's rework on
`feat/item-05-payload-storage-retention` (HEAD `0ae3219`, working tree clean) after the
initial **Request changes**. Scope: commit `33f4884` *"fix(item-05): enforce retention
config-sanity invariant (review-05 M-1)"* — `app/Services/RetentionPolicy.php`,
`app/Actions/PurgeExpiredPayloads.php`, two unit test files, and the T11 rework note in
tasks-05. All three gates re-run, both failure modes re-probed independently, and the
eight areas cleared in the first pass re-checked for regression.

### Major 1 (config-sanity invariant) — RESOLVED

**(a) Mass-erasure mode — closed at the plan's designated seam.**
`RetentionPolicy::windowFor()` (`app/Services/RetentionPolicy.php:36-50`) now reads
`(int) config('retention.days')` and throws `RuntimeException` when `$days < 1`, before
`CarbonInterval::days()` is ever constructed. This is the correct placement, and it is
provably total: a repository-wide grep finds `retention.days` read in **exactly one**
place (`RetentionPolicy.php:38`), `cutoffFor()` (`:58`) and `expiresAt()` (`:75`) both
compose through `windowFor()` rather than re-reading, and there is no hard-coded day count
anywhere in `app/` (`grep subDays|addDays|CarbonInterval` over `app/` returns only
`TeamInvitation`'s unrelated 3-day invite expiry). There is therefore **no path to a
resolved window of ≤ 0** — including the #6 consumer `expiresAt()`, which inherits the
guard for free.

The blank/non-numeric env cases are genuinely covered, not assumed. Verified independently
in this sandbox rather than taken from the test: with `putenv('RETENTION_DAYS=')` and with
`putenv('RETENTION_DAYS=not-a-number')`, re-resolving `config/retention.php` yields
`days = 0` in both cases (`blank=0 nonnumeric=0`), and `0 < 1` trips the guard. `-1` is
caught by the same comparison. The guard is `< 1`, not `=== 0`, so no negative value slips
past.

**(b) Infinite-loop mode — closed, and the structural-unreachability claim holds.** I
checked this claim specifically rather than accepting it. `handle()`
(`PurgeExpiredPayloads.php:63-73`) resolves and validates both values *before*
`Team::query()->withTrashed()->chunkById(...)` is called, then threads them into
`purgeForTeam(Team, int $batchSize, int $horizonMinutes)` as parameters. Verified there is
no residual re-read: `config(` appears in that file at exactly two lines (`:81`, `:102`),
both inside the two guards; `purgeForTeam()`, `selectCollectableIds()`, `eraseOne()` and
`applyHolds()` read no config at all. `purgeForTeam()` is `private` and has exactly one
call site (`:70`), so it cannot be invoked directly — including from a subclass, since
`private` is not overridable. `handle()` takes no arguments and is the sole entry for every
laravel-actions surface (`::run()`, the `payloads:purge-expired` command decorator — no
`asCommand()` override exists). With `$batchSize ≥ 1` guaranteed by type and by guard,
`count($ids) === $batchSize` cannot evaluate `0 === 0`, and `limit($batchSize)` cannot
return more than `$batchSize`. The terminator is genuinely unreachable with an invalid
value, not defended inside the loop. Claim verified.

**Fail-loud rather than substitute — correct call.** `RuntimeException` naming the key and
the offending value is the right posture for `retention.days` (a silent 30-day fallback
would mask an operator's genuinely different intent on the one operation with no recovery
path — plan Risk 1, PRD flag 6) and costs nothing for the other two. I checked the blast
radius of the new throw and it is contained: `RetentionPolicy` is injected in exactly one
production class (`PurgeExpiredPayloads`), so the ingest/dispatch hot path cannot be broken
by a bad retention config. Under `Schedule::command('payloads:purge-expired')` the throw
becomes a non-zero exit in a child process, and Laravel's `Event::runCommandInForeground()`
releases the `withoutOverlapping()` mutex in a `finally`, so failing loudly does **not**
leave a stuck lock that blocks subsequent runs — a regression I looked for and did not find.

**Plan conformance on the zero/negative distinction — correct as worded.** plan-05
§Validation → *Config sanity* reads: "`retention.days` must be a positive integer;
`purge_batch` a positive integer; `dispatch_horizon_minutes` a **non-negative** integer."
The implementation is `days < 1` reject, `purge_batch < 1` reject,
`dispatch_horizon_minutes < 0` reject — i.e. zero accepted for the horizon only. That is the
plan's wording exactly, and it is also semantically right: a zero horizon is degenerate but
well-defined (H4 collapses to "any age"), whereas a zero window or a zero batch is a defect.
No deviation.

### The 11 new tests — genuine proofs, with two noted limits

Counted and run in isolation: `RetentionPolicyTest` 4 new (8 total),
`PurgeExpiredPayloadsTest` 7 new (13 total) — `./vendor/bin/sail test --filter
"RetentionPolicyTest|PurgeExpiredPayloadsTest"` → 21 tests / 31 assertions, all green. The
claimed count of 11 is accurate.

- **The four env-reproduction cases are not tautologies.** Each does
  `putenv(...)` → `require base_path('config/retention.php')` (a `require`, not
  `require_once`, so the file re-executes) →
  `Config::set('retention.days', $resolved['days'])` → expect throw. If Laravel's `env()`
  did **not** pick up the `putenv` value, `$resolved` would be the documented default (30 /
  500), no exception would be thrown, and the test would **fail**. Passing is therefore
  positive evidence that the real config file resolves a blank/non-numeric env to `0` —
  which I also confirmed directly out-of-band (above). `putenv` is restored in a `finally`,
  so no state leaks between tests.
- **The `DB::listen()` batch-terminator proof is genuine.**
  `PurgeExpiredPayloadsTest.php:203-233` seeds a *real* collectable 31-day-old event (so the
  loop would have live data to spin on), sets `purge_batch` to 0, counts every executed query
  whose SQL contains `webhook_events`, and asserts both that the `RuntimeException`
  propagated **and** that the count is `0`. Because `Connection::run()` dispatches
  `QueryExecuted` synchronously after each statement, a single entry into the loop body would
  register at least one selection and fail the count. It proves the loop is never entered, not
  merely that it exits — which is exactly the claim under test, and it is falsifiable in the
  direction that matters. Limit worth recording: if the guard were later removed, this test
  would **hang** rather than fail (the un-guarded loop is genuinely infinite), so it protects
  the invariant but degrades badly as a regression signal. Nit 7 below.
- `test_a_zero_dispatch_horizon_minutes_is_allowed` is an absence-of-throw test carrying a
  placeholder `assertTrue(true)`; its real content is that `run()` completes. Legitimate for
  the claim ("zero must not be rejected") but assertion-free by construction. Nit 8 below.

### Regression check — the eight cleared areas

The rework touches two production files and adds only guards and parameter threading; I
re-verified each cleared area rather than inferring.
- **`AdvanceProxyFifoQueue` still byte-identical to `main`** — `git diff --stat main..HEAD
  -- app/` lists nine files and `app/Actions/AdvanceProxyFifoQueue.php` is **not** among
  them; MD5 still `7aa3c7c350d04048c3900c4ebff64e67`. #4's machinery is untouched (AC8).
- **No new `Log::` call, and none carries payload content** — `grep -rn "Log::" app/`
  returns the same **three** calls as the first pass (`payload.expired` ×2 with `ingest_id`;
  `payload.purged` with `team_id` + `count`). The fix adds none; the two `RuntimeException`
  messages carry a config key and an integer, nothing from a payload (AC6,
  `coding.md` *Never log*).
- **AC12 / compare-and-set / AC21 / AC15+AC22 / T4 migration** — `eraseOne()`,
  `applyHolds()`, the transaction, the reader guards, the casts, and the migration are
  unchanged in the diff; their acceptance tests all pass unmodified in the full run.
- **N+1 posture unchanged or slightly better** — `cutoffFor($team)` is still resolved once
  per team (`:120`); the two config reads moved from once-per-team to once-per-run.
- Findings 4, 5 and 6 (Nits) were not addressed and were not required to be:
  `->orderBy('id')` is unchanged at `:149`, the per-batch `Log::info` at `:134`, and the
  savepoint limitation of the AC12 proof all stand as recorded.

### Additional findings introduced by the fix
| # | Severity | Location | Finding |
|---|---|---|---|
| 7 | Nit | `tests/Unit/Actions/PurgeExpiredPayloadsTest.php:203-233` | The batch-terminator proof would **hang** rather than fail if the guard regressed, because the un-guarded `do/while` is genuinely infinite. It is a correct proof of the current invariant but a poor regression alarm. A bounded variant (e.g. asserting on the guard's own return before any DB work) would fail fast. Non-blocking; no AC or standard binds this. |
| 8 | Nit | `tests/Unit/Actions/PurgeExpiredPayloadsTest.php:192-201` | `test_a_zero_dispatch_horizon_minutes_is_allowed` carries a placeholder `assertTrue(true)`; the meaningful outcome is that `run()` does not throw. Correct for its claim, but it exercises a pass over an empty data set, so it does not also show that a zero horizon *behaves* (H4 collapsing to "any age"). Optional strengthening only. |
| 9 | Nit | `app/Services/RetentionPolicy.php:36-50` | The `days` guard is on the **config read**, not on the returned `CarbonInterval`. `windowFor()` is `public` and is explicitly the V5/V6 per-team extension point (and is already overridden by anonymous subclasses in two test files), so a future per-team override that computes a window without calling `parent::windowFor()` would bypass the invariant. Not a defect at #5 — there is exactly one implementation and the plan designates this seam — but the V5 lever should either validate its own return or the guard should move onto the returned interval when that lever lands. Forward-looking note for the Principal Engineer, no action now. |

### Gate results (re-run by the Reviewer, 2026-08-05)
| Gate | Command | Result |
|---|---|---|
| Lint | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` (L7, no baseline file) |
| Backend tests | `./vendor/bin/sail test` | `{"tool":"phpunit","result":"passed","tests":434,"passed":434,"assertions":1549,"duration_ms":9469}` (+11 tests, +12 assertions vs. the initial review's 423/1537 — exactly the 11 new cases) |
| Targeted | `./vendor/bin/sail test --filter "RetentionPolicyTest\|PurgeExpiredPayloadsTest"` | 21 tests / 31 assertions, passed |

No frontend gates — #5 still ships no `resources/` change; the fix commit touches only
`app/`, `tests/` and `docs/tasks/`. The Senior Developer's reported numbers reproduce
exactly.

### Re-review recommendation
**Approve with follow-ups.** Major 1 is **fully closed**. Both failure modes are shut at the
right seams: `retention.days` where every consumer already converges, and `purge_batch` /
`dispatch_horizon_minutes` at command entry, which makes the infinite-loop terminator
structurally unreachable rather than defended in place — a claim I verified against the call
graph, not just the commit message. Blank and non-numeric env values are covered and were
re-proved independently. The zero/negative split matches plan-05 §Validation word for word.
Failing loudly is the right posture on an irreversible operation and introduces no stuck
scheduler lock. The 11 tests are genuine, falsifiable proofs. No regression in any of the
eight areas cleared in the first pass. No new Blocker or Major.

Carried forward as non-blocking follow-ups, unchanged and still upstream — **not** Senior
Developer work and **not** re-litigated here: **finding 2** (Minor, partial-fan-out Async
hold-set gap → question doc to the **Principal Engineer** against plan-05 / ADR-012
Decision 4) and **finding 3** (Minor, `failed_jobs` plaintext `DeliveryUnit` vs AC6's reach
→ question doc to the **Product Manager**, then the Principal Engineer if in scope; pre-existing
from #4, do not widen #5). Both await an Owner decision. Nits 4–9 are optional. The final
call rests with the Project Owner.

- **Project Owner decision / date:** _pending_

## Handoff
- **Inputs:** PRD-05 (Approved + Amendment A), plan-05 (re-certified; § Revision A),
  ADR-012 / ADR-013 / ADR-014, ADR-010/011/008/003/002, tasks-05 (T1–T18 + T11 rework note),
  `docs/standards/`, branch `feat/item-05-payload-storage-retention` @ `e544dce` (initial
  review) and @ `0ae3219` / fix commit `33f4884` (re-review).
- **Outputs:** this review, including the re-review section.
- **Dependencies:** none remaining on this branch — finding 1 is closed. Findings 2 and 3
  are upstream questions and do not gate the merge.
- **Outstanding Questions:** two raised by this review, both still open — finding 2
  (Principal Engineer, AC8 hold completeness over the partial-fan-out window) and finding 3
  (Product Manager, AC6's reach over `failed_jobs`). Neither gates this branch. Nit 9 adds a
  forward-looking note for the Principal Engineer when the V5 per-team retention lever lands.
- **Next Agent:** Project Owner (approval / merge), then Principal Engineer and Product
  Manager for findings 2 and 3.
