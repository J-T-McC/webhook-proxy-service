# Q-10-02: The authoritative at-rest payload-copy inventory (D2 item 3 / F1), and whether AC5 holds for the failed-job surface

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Product Manager, while drafting `docs/product/prd-10-sensitive-data-handling.md`
- **Directed To:** Principal Engineer
- **Required By:** Before #10's technical design. **Non-blocking for requirement approval** — PRD-10
  can be approved by the Project Owner with this open, in the same way PRD-05 was approved with
  Q-05-03 open.
- **Priority:** Medium
- **Status:** **RESOLVED — Principal Engineer, 2026-08-27.** Both items answered in § Answer.
  Two findings; **AC1, AC2 and AC4 hold as written, AC5 holds conditionally with the conditions
  named, and one entry sits outside AC3's enumeration** — recorded for the Product Manager's
  awareness with the mitigation ruled in `plan-10` rather than raised as a separate requirement
  question, because the mitigation is protective on either reading. Answered at
  `docs/plans/plan-10-sensitive-data-handling.md` (§ *Technical rulings* 8, § *Risks* R5,
  § *Test strategy*) and `docs/architecture/adr-021-secret-handling-and-rotation.md` (Decision 1).
- **Raised:** 2026-08-27

## Question

Two items, related but separable.

**(i) Produce the authoritative inventory of durable at-rest copies of payload content.** This is
follow-up **F1**, assigned to the Principal Engineer on 2026-08-25 in
`docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md`, and required by **D2 item 3** as a
component of #10's PRD. It has never been produced. Its original wording, unchanged:

> **F1 — Produce the authoritative inventory of durable at-rest payload-content copies.** Every
> location where the body or the captured headers of an event exist at rest outside the three
> encrypted columns ADR-014 covers — the failed-job store, the queue backend's own job storage
> while a job is queued or delayed, and anything else (cache, batch, telemetry) the current stack
> puts them in. Deliverable: an enumeration #10's PRD can carry as D2 item 3, and a statement of
> which entries are **transient** (destroyed by the mechanism on success) versus **durable**
> (persist until something removes them). This is an enumeration, not a design.

**(ii) Does PRD-10 AC5 hold for the failed-job surface as the code stands today?** AC5 reads:

> **A failure record carries no payload content.** Whatever the dispatch mechanism durably records
> when a unit of work fails — its arguments, its exception, and any operator-facing rendering of
> either — contains no payload content.

The **arguments** half is settled: ADR-020 Decision 7 is merged and `DeliverStep` dispatches two
integers. The **exception** half is not, and the Product Manager is not the one to settle it.
Because the `DeliveryUnit` is now resolved **on the worker**, it exists as a live object inside the
call that may throw, where previously it arrived through the job's arguments. That relocates the
question rather than answering it. Whether anything payload-bearing can reach a durably recorded
exception, its trace, or an operator-facing rendering of either — including Horizon's own failed-job
display — is a technical finding, not a requirement.

## Context

**Why this is asked now rather than assumed.** D2 (PRD-05 § Deferred concerns, Amendment B, Owner
ratification 2026-08-25) gates #10's PRD and states that the PRD "does not pass requirement
approval without" its five items. Four of them are discharged in PRD-10 § D2. Item 3 is the
exception, and D2 says why in its own words: "Producing the authoritative, complete inventory is a
**Principal Engineer** task (follow-up F1 in Q-05-06), **not the Product Manager's**."

**What PRD-10 carries in the meantime, and what it is not.** PRD-10 § D2 states an inventory
snapshot dated 2026-08-27, compiled from ADR-020 § Impact and re-checked against the merged code.
It is labelled explicitly as a Product Manager's compilation and explicitly not as F1's deliverable.
Two reasons it should not be treated as authoritative:

1. **ADR-020's table was produced to answer ADR-020's own question**, which was about the queue and
   what Decision 7 removes from it. F1 asks a wider question — "anything else (cache, batch,
   telemetry) the current stack puts them in" — that ADR-020 had no reason to ask.
2. **The stack has moved since D2 was written, twice in one day.** Laravel Horizon landed on
   2026-08-27 and is a **second independent 7-day retention of every job record**, which
   `queue:prune-failed` does not touch; ADR-020 caught it. `symfony/mailgun-mailer` and
   `symfony/http-client` also landed on 2026-08-27. Neither carries payload content today, and
   neither is being flagged as a suspicion — the point is only that "the current stack" is a moving
   target and an inventory needs a date and an author who can vouch for its completeness.

**What the answer feeds.** PRD-10 **AC1** binds "every durable at-rest copy of payload content the
system creates … wherever the dispatch mechanism, the queue backend, the framework or any scheduled
process places it", and **AC3** asserts the set of stores holding payload content is closed at two.
Both are stated backend-agnostically on purpose — roadmap **V3** may change the queue backend — but
they can only be **verified** against an enumeration. Without F1, AC1 and AC3 are approvable but not
checkable, and the Reviewer at #10 would have to build the inventory themselves at the latest
possible moment.

**One ordering already recorded, carried here so it is not lost.** ADR-020 § Impact notes that
`queue:prune-failed`'s hard-coded 168 hours in `routes/console.php` must stay **below** the resolved
retention window (`retention.days`, default 30), or a failure record could outlive the erase meant to
destroy the content it once held. It does today, by 23 days. ADR-020 named it rather than testing it,
on the ground that after Decision 7 there is no payload in that store for an inversion to expose.
**PRD-10 AC2 makes that ordering a requirement rather than a coincidence.** Whether it now warrants a
test — given that `RETENTION_DAYS` is env-overridable and the prune window is a literal — is part of
(ii) and is the Principal Engineer's call.

**What is not being asked.** No design, no mitigation, and no ADR is requested. If (i) or (ii) turns
up a location that AC1 or AC3 does not fit, **that returns to the Product Manager as a requirement
question** rather than being resolved as a design change — the same routing PRD-05 Q-05-04 used.

## Impact if unresolved

D2 exists because a real plaintext exposure sat in a review finding for twenty days with nobody
holding it, and #6 shipped straight through it. The failure mode this question guards against is the
same one one step later: #10 ships with AC1 and AC3 approved, everyone reads "the three encrypted
columns plus ADR-020 removed the rest", and a location nobody enumerated is outside the guarantee —
with the guarantee now written down, which makes it worse rather than better, because a stated
property that is not true reads as assurance.

## Answer

**Principal Engineer, 2026-08-27.** Compiled by reading the merged code on `main` at `6bfb782` and
the relevant vendor source, not from ADR-020's table. Every row below was checked; where a claim
rests on a framework behaviour, the vendor file is named so it can be re-checked rather than
trusted.

### (i) The authoritative inventory — every at-rest location, dated 2026-08-27

**Durable** = persists until something removes it. **Transient** = destroyed by the mechanism on
success, held for the life of one unit of work.

| # | Location | Durable? | Governed by | Payload content today |
|---|---|---|---|---|
| 1 | `webhook_events.body` — `LONGBLOB NULL`, cast `encrypted` | **Durable**, 30-day retention | `PurgeExpiredPayloads`, erase-in-place | **Present, encrypted at rest** (ADR-010 Amendment B, ADR-014) |
| 2 | `webhook_events.headers` — `MEDIUMTEXT NULL`, cast `encrypted:array` | **Durable**, same pass | same | **Present, encrypted at rest** (PRD-05 AC22, ADR-014) |
| 3 | `dispatched_payloads.body` — `LONGBLOB NULL`, cast `encrypted` | **Durable**, same pass | same | **Present when the output diverged**, encrypted at rest (ADR-013) |
| 4 | Queue store — `jobs` table (`longText payload`) or the Redis list | **Transient** | deleted on success; `retry_after` re-reserves | **Absent.** Every job's arguments are scalars: `DeliverToDestination(deliveryId, attemptNumber)`, `RetryDelivery(deliveryId, attemptNumber)`, `ProcessIngestedWebhook(ingestId, dispatchUuid)`, `AdvanceProxyFifoQueue(proxyId)`, `SweepDueRetries()`, `SweepStalledFifoDispatches()`, `PurgeExpiredPayloads`. Verified by reading each dispatch site |
| 5 | `failed_jobs.payload` — `longText` | **Durable**, 7 days | `queue:prune-failed --hours 168`, daily | **Absent** — the same scalar arguments as (4) |
| 6 | `failed_jobs.exception` — `longText`, holds `(string) $e` | **Durable**, 7 days | same | **Absent in plaintext. Reachable in ciphertext** — see finding A |
| 7 | Horizon's job records — Redis; `recent`/`pending`/`completed` 60 min, `recent_failed`/`failed`/`monitored` **10080 min = 7 days** (`config/horizon.php`) | **Durable**, 7 days, **a second and independent retention** that `queue:prune-failed` does not touch | `horizon.trim` | Same content as (5) and (6): `JobPayload` keeps the raw string and `RedisJobRepository` writes it verbatim |
| 8 | The application log — `LOG_CHANNEL` defaults to `stack`, no rotation policy configured | **Durable, and governed by nothing in this repository** | — | **Absent in plaintext. Reachable in ciphertext** — see finding A. The six deliberate `Log::info` calls in `app/` (`payload.revealed`, `payload.expired` ×3, `payload.purged`) carry identifiers and counts only |
| 9 | `sessions` table (`SESSION_DRIVER` defaults to `database`) | **Durable** until session GC | Laravel session lifetime | **Absent today.** Listed because it is where flashed old input and Inertia flash props land — the reason `plan-10` Technical rulings 5 and 7 keep #10's **secrets** out of it |
| 10 | `cache` table (`CACHE_STORE` defaults to `database`) | Durable until evicted | — | **Absent.** `grep` finds **no `Cache::` or `cache()` call anywhere in `app/`** — nothing in this application caches anything |
| 11 | Worker memory; the outbound HTTPS request | Not at rest | — | Present, necessarily. Outside AC1 |
| 12 | Database backups, binlog, replica | — | — | **None configured in this repository.** Named so the absence is deliberate rather than an oversight: `docs/stack/stack.md` records no deployment target, so any production backup is deployment configuration outside these documents and inherits whatever the database holds |
| 13 | `webhook_events.method`/`.content_type`/`.byte_size`/`.received_at`, `dispatched_payloads.byte_size` | Durable | survives erasure by design | **Not payload content** — PRD-05 AC6's permitted non-content descriptors |

**Summary against the criteria.** Payload content exists at rest in **exactly the three encrypted
columns of rows 1–3**, which are the two stores AC3 enumerates (the captured event and the stored
dispatched output). **AC1 holds**: every durable copy carries the at-rest floor. **AC2 holds**: no
durable *plaintext* copy exists, so none can outlive its event's retention window. **AC4 holds**: no
queued or executing unit of work carries payload content in its own arguments, in either processing
mode, for an original dispatch, a retry or a replay.

### (ii) Does AC5 hold for the failed-job surface? Yes — and here are the three conditions it rests on

The **arguments** half is settled by ADR-020 Decision 7, confirmed above at rows 4 and 5. The
**exception** half holds today, but conditionally, and the conditions are worth stating because two
of them are properties of code we own and one is an environment setting.

**Condition 1 — no query binds *plaintext* payload content.** Laravel's
`QueryException::formatMessage()` builds its message as
`$previous->getMessage().' (Connection: …, SQL: '.Str::replaceArray('?', $bindings, $sql).')'`
(`vendor/laravel/framework/src/Illuminate/Database/QueryException.php`). **Bindings are interpolated
into the message.** That message is what reaches `failed_jobs.exception`, Horizon's copy of it, and
the log. It holds today only because `webhook_events.body`, `.headers` and `dispatched_payloads.body`
all carry `encrypted*` casts: the cast runs at attribute-set time and `Model::performInsert()` binds
`$this->getAttributes()`, so the bound value is already ciphertext. **This is a property of the
casts, not of the exception handling**, and it is why ADR-021 Decision 1 makes the `encrypted` cast
binding for every secret #10 adds rather than merely conventional.

**Condition 2 — the delivery path catches its own transport errors.**
`DeliverToDestination::send()` wraps the HTTP call and the attempt update in `catch (Throwable $e)`,
so a Guzzle exception never fails the job. That matters specifically because Guzzle's
`RequestException` message includes a truncated **response**-body summary — the destination's bytes,
not ours, but it is the nearest thing to content that could reach this surface, and it does not,
because the job never fails on it.

**Condition 3 — stack traces do not carry payload as an argument.** `(string) $e` appends
`getTraceAsString()`, which renders arrays as `Array`, objects as `Object(Class)` and truncates
string arguments to `zend.exception_string_param_max_len`; `zend.exception_ignore_args` can remove
arguments entirely. Independently of the ini settings, **no frame on the delivery path passes payload
bytes as a direct scalar argument** — `Http::send($method, $url, ['body' => …])` passes an array, and
`DeliveryUnit`'s constructor (which does take the payload as a `string` parameter) has returned long
before anything downstream can throw. This is the one condition that depends on the environment as
well as on our code, so it is stated as a property rather than a guarantee.

**Recommendation, and what `plan-10` does with it.** Conditions 1 and 2 are pinned by tests in
`plan-10` § *Test strategy*: that a `QueryException` on a payload-bearing insert carries ciphertext
rather than plaintext, and that a failed secret write produces no plaintext secret in its message.
Condition 3 is recorded, not tested — a test for it would assert a PHP ini default.

### Finding A — an encrypted copy of payload content can reach the log, and that store is governed by nothing

`IngestController` calls `report($e)` when the capture transaction throws. If that exception is a
`QueryException` on the `webhook_events` insert, its message carries the **interpolated ciphertext**
of the body and the headers — up to the ~35%-inflated envelope of a 50 MiB body — into
`storage/logs`, a store with **no retention pass, no encryption of its own, and no entry in AC3's
enumeration**.

- **It does not breach AC1**: what lands there is ciphertext, so the copy carries the at-rest floor.
- **It does not breach AC2**: nothing plaintext is durable.
- **It does not breach AC8 on the plain reading**: AC8 forbids a log line naming *a field's value*,
  and a whole-body ciphertext blob names none.
- **It sits outside AC3's enumeration**, which says payload content exists at rest "in exactly the
  two stores the retention system governs … **and nowhere else**".

**Ruled as a design change in service of AC3 and AC8 rather than returned as a requirement
question**, because the mitigation is protective on either reading of AC3 and costs nothing: the
capture-failure report is wrapped so what is reported names the `ingest_id`, the proxy and the
SQLSTATE, and **not** the interpolated statement. Recorded at `plan-10` § *Technical rulings* 8, with
a test. **Flagged to the Product Manager for awareness rather than as a gate**: if you read AC3 as
already excluding an encrypted log copy, nothing changes; if you read it as needing an amendment,
that is yours. The mitigation lands either way.

### Finding B — the prune-window ordering is now a **three**-way constraint, and it warrants the test

ADR-020 recorded that `queue:prune-failed`'s hard-coded `24 * 7` hours in `routes/console.php` must
stay **below** the resolved retention window, or a failure record could outlive the erase meant to
destroy the content it once held. **Horizon adds a third number**: `config/horizon.php`'s
`trim.failed`/`trim.recent_failed`/`trim.monitored` are `10080` minutes, an independent 7-day
retention that `queue:prune-failed` does not touch.

Two of the three are **literals in different files** and the third, `retention.days`, is
**env-overridable** (`RETENTION_DAYS`, default 30). Nothing today makes them fail loudly if someone
sets `RETENTION_DAYS=3`.

**Answering the question as asked — yes, it now warrants a test**, and `plan-10` § *Test strategy*
carries one asserting all three together. The reason it is worth the test now when ADR-020 judged it
not worth one then is that ADR-020's ground was "after Decision 7 there is no payload in that store
for an inversion to expose", and that ground is exactly condition 1 above — a property of the
`encrypted` casts that a future column could quietly fail to have. AC2 makes the ordering a
requirement rather than a coincidence, and the test is three lines.

### Nothing here contradicts a criterion, and nothing returns to the Product Manager as a gate

AC1, AC2, AC3 and AC4 hold as written. AC5 holds under the three named conditions, two of which are
now pinned by test. Finding A is recorded for the Product Manager's awareness with the mitigation
already ruled; Finding B is a test. **D2 item 3 is discharged by the table above**, and follow-up
**F1** from `docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md` is closed with it.
