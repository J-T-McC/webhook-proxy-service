# ADR-012: Payload retention model and garbage collection (erase-in-place, team-keyed window, hold-based eligibility)

- **Status:** **Accepted — Project Owner, 2026-08-05.** Owner sign-off covers the irreversible
  destructive operation described in Impact: permanent, unrecoverable erasure of customer payload
  content, enabled by default for all teams, with no opt-in and no recovery path.
- **Author:** Principal Engineer
- **Date:** 2026-08-05 · **Revised:** 2026-08-05 (PRD-05 **Amendment A**, Owner ruling — see § Revision A)
- **Feature:** prd-05-payload-storage-retention (closes the PRD-03 AC11 interim gap; serves #6, #10, #11)
- **Depends on:** ADR-014 (the captured-entity shape this pass writes to) · ADR-013 (the second store it erases)

## Revision A — what the Owner's 2026-08-05 ruling changed here
This ADR was written against "expiry is derived, never stored", "no retention column, row, flag or
tombstone", "removal is a **hard delete**", and "*expired* is derived from the **absence** of a
record". Amendment A reverses all four. Revised in place while still **Proposed** (documentation.md:
in-place amendment applies to a decision that is not yet ratified; nothing Owner-approved is
rewritten — the ADR-010 reversal is carried by **ADR-014**, a superseding instrument).

| Prior decision | Now |
|---|---|
| (1) Expiry derived, never stored; no column/flag/tombstone | **Dropped.** AC21 requires an explicit cleaned state on the record. One column: `payload_cleaned_at` (ADR-014). Expiry *eligibility* is still derived from `created_at + window`; the *cleaned fact* is now stored. |
| (5) Removal is a **hard delete** | **Dropped.** Erasure is a conditional in-place `UPDATE`; the captured record is retained (AC11). |
| (6) "Expired" derived from the absence of a record | **Dropped.** Read `payload_cleaned_at` (AC21). The `delivery_attempts`-implies-capture derivation is gone. |
| (4) `settled`-only conditional `fifo_dispatches` DELETE + disjoint-index-range argument + restrict FK as the fail-loud net | **Dropped as GC apparatus** — see Decision 5. All three existed only to make *deleting a parent row* safe. |
| (2) Team-keyed `RetentionPolicy::windowFor()` | **Retained unchanged.** |
| (3) H1–H4 hold set | **Retained, all four still necessary** — re-derived under erase-in-place in Decision 4. Now additionally enforced *inside the erase statement*. |
| Batched, scheduled, per-team execution; `delivery_attempts` never touched; no payload content in logs; `Team::withTrashed()` | **Retained unchanged.** |

## Question
PRD-05 requires every stored payload to expire **30 days from capture**, expressed as a
**team-level** property (AC1–AC3), and its **content** to be erased automatically and completely
by a recurrent garbage collector (AC5, AC6, AC11) — erasing the raw body, the captured headers
(AC22b) and the dispatched output (AC12) in **one pass with no window in which one survives the
other**, marking the retained record cleaned (AC21), while **never** erasing a payload whose
dispatch is still outstanding under ADR-011 FIFO claim state or an in-flight Async job (AC8) and
**never** touching payload-free `delivery_attempts` (AC9, ADR-003). Q-05-03 (i)/(ii)/(iv) and
Q-05-04 (ii) are the open decisions.

## Decision

**(1) Erasure is a conditional in-place `UPDATE`, never a delete.** Per collectable event, in one
short transaction:

```
UPDATE webhook_events
   SET body = NULL, headers = NULL, payload_cleaned_at = NOW()
 WHERE id = ?
   AND payload_cleaned_at IS NULL          -- H0: not already cleaned (idempotence)
   AND <H1 expired> AND <H2> AND <H3> AND <H4>;   -- holds re-asserted at write time
-- if 1 row affected:
UPDATE dispatched_payloads SET body = NULL WHERE webhook_event_id = ?;
COMMIT;
```

Zero rows affected ⇒ a hold reappeared between selection and write ⇒ skip the event; the next run
collects it. **This compare-and-set is the direct replacement for the restrict-FK fail-loud net**
of the deletion design, and is strictly stronger: the holds are re-evaluated atomically *in the
mutating statement* rather than relying on a constraint to reject a stale decision.

**(2) The window is a team-keyed resolver, not a column and not a constant.** *(Unchanged.)*
`App\Services\RetentionPolicy::windowFor(Team $team): CarbonInterval` is the single source of the
window — today `config('retention.days')` (30) for **every** team (AC2, AC3). The `Team` parameter,
not a `teams.retention_days` column, is the V5/V6 extension point: a later tier or region lever
changes only this method body, per Q-05-02(a). **No `teams` column is added at #5.**

**(3) The cleaned state is read, never inferred.** `payload_cleaned_at` (ADR-014 Decision 4) is
the AC21 signal. `App\Services\StoredPayloadLookup` returns
`StoredPayloadState::Retained | Cleaned | NeverCaptured` by reading it. **No consumer may infer
"cleaned" from `body IS NULL`** (ADR-014 Decision 7) — the guard is the timestamp.

**(4) GC eligibility is the same conjunction of named holds, re-derived under erase-in-place.**
A captured row is **collectable** only when every hold is clear. Holds stay additive — #6 attaches
replay/dead-letter holds to the same list.

| Hold | Predicate | Still necessary? |
|---|---|---|
| **H0 — not already cleaned** | `payload_cleaned_at IS NULL` | **New.** Makes the pass idempotent and keeps the daily selection bounded to work it will actually do. Trivially true under delete (a collected row was gone). |
| **H1 — expired** | `created_at <= now() - windowFor(team)` | **Yes**, unchanged (AC1–AC3, AC7). |
| **H2 — FIFO ordering** | no `fifo_dispatches` row for the event with `status <> 'settled'` | **Yes.** A `pending`/`claimed` row means `AdvanceProxyFifoQueue` will drive `ProcessIngestedWebhook`, which reads `body`+`headers` from this row. This is the read hop AC8 protects. |
| **H3 — in-flight delivery** | no `delivery_attempts` row for the event's `ingest_id` with `status = 'dispatched'` | **Yes — but the reason changed.** Mechanically it no longer protects a read: once `ProcessIngestedWebhook` has built the context, per-destination Async jobs carry their bytes in the `DeliveryUnit` and never re-read `webhook_events`. It is required because **AC8 states the requirement in terms of outstanding dispatch**, and marking an event *cleaned* while its deliveries are still landing would publish a false state (AC21). Necessary by criterion and by consistency, not by loss risk. |
| **H4 — pre-dispatch horizon** | if the event has **zero** `delivery_attempts` rows, `created_at <= now() - config('retention.dispatch_horizon_minutes')` | **Yes.** Covers the captured-but-pipeline-job-not-yet-started window, which leaves no record of its own — the one window H2/H3 cannot see. |

**Sufficiency.** Exactly one hop reads the captured row after capture: the pipeline entry
(`ProcessIngestedWebhook`, ADR-011 Decision 3). Under FIFO that hop is always preceded by a
non-settled `fifo_dispatches` row ⇒ **H2** covers it. Under Async that hop is either before any
attempt row exists ⇒ **H4** covers it within the horizon, or after ⇒ the hop has already happened.
A job that has already read the row holds the plaintext in memory and is unaffected by a
concurrent erase. **H4's residual gap is unchanged in size and improved in consequence:** an Async
job that queues longer than the horizon could still find its payload erased — under the delete
design it then threw `ModelNotFoundException` and the event vanished; under erase-in-place the
ADR-014 Decision 7 guard logs `payload.expired`, returns cleanly, and the record survives marked
cleaned. Inert while a 30-day window dwarfs a ~60-minute horizon.

**(5) GC no longer writes anything ADR-011 reads or writes.** The deletion design had to remove
the `settled` `fifo_dispatches` row first, because the restrict FK would otherwise block the parent
delete. Erase-in-place deletes nothing, so:
- the **conditional `settled`-only `fifo_dispatches` DELETE** is **unnecessary and removed** — GC
  never touches `fifo_dispatches` at all;
- the **disjoint-index-range argument** (`settled` vs `pending`/`claimed` under
  `(proxy_id, status, webhook_event_id)`) is **unnecessary** — it proved GC's writes could not
  disturb the advancer, and there are now no such writes. Replaced by a stronger and simpler
  claim: **GC's writes are confined to `webhook_events` and `dispatched_payloads`; it reads
  `fifo_dispatches` and `delivery_attempts` and writes neither.**
- the **restrict FK on `fifo_dispatches.webhook_event_id` is retained but is no longer a GC
  safety net.** It stays for referential integrity — nothing may orphan ordering state — and
  costs nothing; **no migration.** It is now dead as far as the retention path is concerned and
  must not be cited as an AC8 or AC6 guarantee. Its role is taken by Decision 1's compare-and-set.

Consequence to state plainly: `fifo_dispatches` rows are now never removed. That is record growth,
not payload content — **deferred concern D1**, explicitly out of scope (Owner, 2026-08-05). No cap,
prune, or target is asserted. Extension point noted at zero cost: a settled-row prune, if ever
wanted, attaches to this same per-team pass with no re-modelling.

**(6) Both payload stores are erased in one transaction (AC12).** Under the deletion design AC6/AC12
were structural via `cascadeOnDelete`. Nothing is deleted now, so the cascade never fires on expiry
and the guarantee must be **explicit in GC**: the two `UPDATE`s in Decision 1 commit together, so
no reader can observe one erased and the other intact. Ordering (event first, then output) is a
consequence of the event `UPDATE` being the compare-and-set that decides eligibility; atomicity —
not order — is what AC12 requires.

**(7) Execution: batched, scheduled, per-team.** *(Unchanged in shape.)* `PurgeExpiredPayloads`
(an `AsCommand` action scheduled in `routes/console.php`, matching the `SweepStalledFifoDispatches`
precedent) iterates teams **including trashed** (a soft-deleted team's payloads must still expire),
selects up to `config('retention.purge_batch')` collectable ids per team under H0–H4 using the
ADR-014 index `(team_id, payload_cleaned_at, created_at)`, erases each per Decision 1, and loops
until a batch comes back short. Cadence is fixed (daily, off-peak, `withoutOverlapping()`), not a
tunable; batch size and the H4 horizon are config defaults. No soft delete, no archive, no
truncated/previewed/hashed copy, and **no payload content in logs** — counts and identifiers only
(AC6; coding.md never-log list).

## Alternatives
- **Hard delete of the captured record (this ADR's own prior decision)** — settled by the Owner on 2026-08-05: erase in place, retain the record. Not reopened; recorded in § Revision A.
- **Erase by writing an empty string/empty array rather than NULL** — makes "erased" indistinguishable from "captured empty body / no headers", exactly what AC21(c) forbids, and would force the guard onto a value instead of a signal. Rejected.
- **Signal cleaned by `body IS NULL` alone, no column** — AC21 requires an explicit signal, not an empty value; and it collides with ADR-013's nullable `body` where NULL legitimately means "output identical to input". Rejected.
- **Select-then-erase without re-asserting the holds in the `UPDATE`** — reintroduces the select→act gap that the restrict FK used to close, with nothing left to close it. Rejected; the compare-and-set is mandatory.
- **Erase the two stores in two separate transactions** — creates exactly the window AC12 forbids. Rejected.
- **Keep deleting the `settled` `fifo_dispatches` row anyway, for tidiness** — reintroduces a GC write into ADR-011's table (and with it the whole disjoint-range argument, lock-contention analysis, and re-claim race) to solve a problem PRD-05 explicitly defers (D1). Rejected: fewer shared tables is the stronger AC8 position.
- **`expires_at` column on `webhook_events`** — still rejected, and for the surviving reason: it persists a pure function of `created_at` + the team's window, needs a backfill for every #3/#4 row, and pins the value at capture time so a V5 window change could not apply retroactively. `payload_cleaned_at` records a *fact that happened*; `expires_at` would record a *prediction*. Only the latter is bookkeeping.
- **Per-event retention sidecar row** — a row and a write per event for a value H0–H4 already answer from columns present. Rejected.
- **`teams.retention_days` column** — a data-model change to persist an identical constant for every team, with no writer that could vary it; V5 will likely derive from a plan/tier, superseding the column rather than reusing it. Rejected in favour of the resolver.
- **Hard-coded 30-day constant at the GC query** — violates AC3's team-level requirement; forces a re-model at V5. Rejected.
- **Generalize `fifo_dispatches` to all events as a dispatch-completion marker** — violates ADR-011 ("Async proxies never touch this table") and adds a write to the Async ingest hot path #5 must not touch. Rejected.
- **A new per-event dispatch-completion marker table** — correct but pays a table plus a write per event for a signal H2/H3/H4 already derive. Rejected as disproportionate.
- **Hold "any event with zero delivery attempts is held forever"** — an event captured moments before its proxy and destinations are soft-deleted legitimately produces zero attempts, making that payload immortal — the opposite of AC6. Rejected in favour of H4.
- **Two-phase soft-delete then purge** — retains a copy of the content after "removal", contradicting AC6. Rejected. (Note: erase-in-place is *not* this — nothing of the content survives the single write.)
- **MySQL event scheduler / date-partitioned tables with partition drop** — moves lifecycle where H2/H3/H4 cannot be expressed, and now cannot express in-place erasure at all. Rejected.
- **Skip the ADR-014 `(team_id, payload_cleaned_at, created_at)` index and filter in the scan** — every run would re-scan every row it has ever cleaned, since cleaned rows stay inside the expired range permanently. Rejected as a liveness defect of the pass (not a growth measure — D1 unchanged).

## Reasoning
- **AC11 is still satisfied structurally, on the amended reading.** Immutability binds while content
  is retained; the only writer that may touch a captured row is this pass, and it writes exactly
  three columns. ADR-014 records the narrowing of ADR-010's constraint; this ADR is its only
  authorised exerciser.
- **Erase-in-place removes GC's blast radius on other tables.** Under delete, GC's correctness
  depended on the FIFO index topology, the FK mode, and a delete ordering. Under erase, it depends
  on one conditional `UPDATE` against rows nothing else writes. Fewer shared surfaces, fewer races,
  and AC8 stops being an argument about lock ranges.
- **The explicit signal is load-bearing operationally, not just for AC21.** Under delete, a raced
  reader hit `firstOrFail` and failed loudly. Under erase, a reader that checks the wrong thing
  gets a `NULL` body and would **dispatch an empty payload to every destination**. The
  `payload_cleaned_at` guard is what makes the erase-based design safe, which is why AC21's
  "explicitly signalled, never inferred" is a correctness requirement here and not a nicety.
- **Two consumers get simpler, not more complex.** `ProcessIngestedWebhook` keeps `firstOrFail`
  (an absent row is now genuinely a bug, never expiry) and gains one cleaned-state guard.
  `AdvanceProxyFifoQueue` needs **no change at all** — its `$claimed->webhookEvent->ingest_id`
  dereference can no longer meet a missing event, and the early return from
  `ProcessIngestedWebhook` still lets it settle and advance. Both patches the deletion design
  required are dropped.
- **AC3's "team-level property" is about the model, not the storage.** A `Team`-keyed resolver *is*
  the seam Q-05-02(a) ratified, at zero schema cost.
- **V4 inherits retention for free.** Both anchors — `created_at` and `team_id` — are intrinsic to
  the captured row and derived from neither the HTTP ingest request nor its context, so a future
  offline-capture path that yields a `webhook_events` row is retained and collected unchanged.
  `created_at` (durable custody) is also the anchor a sender or edge buffer cannot influence; a
  sender-receipt anchor would be a one-line swap inside `RetentionPolicy`.

## Impact
- **Easier:** #6 replay gets a defined retrievability guarantee **and an exact three-state answer**
  (`StoredPayloadLookup`), attaching its own holds to the same named list; #10 layers policy on the
  same stores with no lifecycle change; #11 is unaffected by construction (AC9 — `delivery_attempts`
  is never read for erasure and never written); V5/V6 change one method body.
- **Constrained:**
  - Only this pass may mutate a captured row, and only `body`, `headers`, `payload_cleaned_at`.
  - GC **never writes** `fifo_dispatches` or `delivery_attempts`; it reads both.
  - Any new consumer that reads a captured row asynchronously **must** register a hold **and**
    check `payload_cleaned_at` before reading `body`/`headers`.
  - The holds must be re-asserted inside the erase statement, not only in the selection query.
- **Data-model change (Owner-gated ✋):** **none from this ADR** — the `webhook_events` changes are
  **ADR-014's**, and `dispatched_payloads` is **ADR-013's**. This ADR adds no column, index, or table.
- **Irreversible / Owner flag (✋):** scheduled **permanent, unrecoverable erasure of customer
  payload content** — raw bodies, captured inbound headers, and dispatched outputs — enabled by
  default, for all teams, no opt-in, no soft delete, no archive, no recovery path (AC5, AC6, AC22b).
  Erase-in-place is **not** less destructive than the delete it replaces: the content is
  unrecoverable either way; only the record survives. At deploy the first pass erases nothing (the
  oldest captures date from #3, 2026-08-04); it becomes destructive from ~2026-09-03.
- **Operational:** requires the scheduler (`schedule:run`) — already required by #4's FIFO sweeper,
  so no new ops surface. Nulling a `LONGBLOB` frees its off-page overflow pages inside the
  tablespace but does not return space to the OS without a table rebuild; the logical unbounded
  growth of **payload content** is closed, physical reclamation stays an ops concern. Retained
  cleaned records and never-pruned `fifo_dispatches` rows are D1 — accepted, out of scope.
- **Within stack:** MySQL 8.0, Laravel scheduler, Eloquent, `lorisleiva/laravel-actions` (ADR-007).
  No new dependency, no stack change. V6 (Postgres) **not reopened**.
