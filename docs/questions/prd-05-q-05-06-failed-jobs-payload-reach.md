# Question Q-05-06: Does PRD-05 AC6's reach extend to `failed_jobs`?

- **Status:** **RESOLVED — requirement scope answered by the Product Manager as the Owner's
- **Owner outcome (2026-08-25):** **E1 ACCEPTED** — the residual plaintext exposure stands
  until #10 ships, on the ADR-010 Amendment B precedent, with a scheduled `queue:prune-failed`
  added to bound the failed-job store's retention. **PRD-05 Amendment B RATIFIED** and applied
  to `docs/product/prd-05-payload-storage-retention.md`. Follow-ups F1/F2 stand with the
  Principal Engineer; D2 gates #10's PRD.
  proxy (2026-08-25).** **AC6 is scoped to the payload stores the retention system governs;
  it does not reach `failed_jobs`.** Three things follow and are named below, none of them
  optional: a **PRD-05 Amendment B** the Owner must ratify (it narrows the reach of an
  Owner-approved criterion), a **binding carry onto roadmap #10** (deferred concern **D2**),
  and **one escalation to the Project Owner** (**E1** — explicit acceptance of the residual
  plaintext exposure until #10 ships, matching the ADR-010 Amendment B precedent). Two
  technical follow-ups are routed to the **Principal Engineer** (**F1**, **F2**); neither is
  designed here.
- **Raised by:** Reviewer — `docs/reviews/review-05-payload-storage-retention.md`, **finding 3
  (Minor)**, routed to the **Product Manager** first ("is this in AC6's scope?"), then to the
  Principal Engineer if a technical answer follows. Carried forward unchanged in the
  2026-08-05 re-review and recorded against feature #5 in `docs/status.md`.
- **Owner (must answer):** **Product Manager** for the requirement-scope half (this doc, and it
  is answered here). **Project Owner** for **E1** only. **Principal Engineer** for **F1**/**F2**.
- **Raised:** 2026-08-05 · **Written up:** 2026-08-25 · **Resolved (scope half):** 2026-08-25
- **Gates:** **Nothing currently in flight.** #5 is Done and merged (PR #6, `ed421f1`); #6 is
  Done and merged (PR #8, `e1c2894`). This does **not** gate #7, which is in UX Design. It
  **does** gate the #10 PRD: #10 may not enter Requirements without D2 on its face.
- **Source:** PRD-05 **AC6** (as amended by Amendment A), AC12, AC15, AC22, deferred concern
  D1; `docs/architecture/adr-012-payload-retention-and-garbage-collection.md`;
  `docs/architecture/adr-014-captured-entity-erasure-and-header-encryption.md` (Decisions 3, 5,
  7; Impact); `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md`; ADR-015 /
  ADR-016 / ADR-017 (#6); `docs/product/roadmap.md` item 10 and Open Question V3;
  `docs/product/prd-03-decoupled-upstream-response.md` + ADR-010 **Amendment B** (the
  precedent for E1); `config/queue.php:16,38-45,123-127`; `app/Actions/DeliverToDestination.php`;
  `app/Actions/DeliverStep.php:48-72`; `app/Pipeline/DeliveryUnit.php`;
  `app/Actions/RetryDelivery.php`; `app/Http/Controllers/ProxyEventReplayController.php`;
  `routes/console.php`.

## Context

### The mechanism — verified against the code, not taken from the review

The Reviewer's description holds, and I re-derived each step rather than accepting it.

1. On an **Async** proxy, `DeliverStep` builds one `DeliveryUnit` per destination carrying
   `payload` and `headers` **verbatim** (`DeliverStep.php:49-59`) and queues it —
   `DeliverToDestination::dispatch($unit)` (`:63`). `DeliveryUnit` is a plain object with
   `public readonly string $payload` and `public readonly array $headers`
   (`DeliveryUnit.php:52-62`); it holds bytes, not a reference.
2. `DeliverToDestination` declares `public int $tries = 1` (`:62`). Any exception that escapes
   `handle()` therefore fails the job on its first run, with no retry.
3. A failed job is written to `failed_jobs` — `config/queue.php:123-127`, driver
   `database-uuids`, on the app's own database connection — with the serialized job payload,
   i.e. the whole `DeliveryUnit`, **in plaintext**. The two payload columns that carry the
   at-rest floor (`webhook_events.body`, `webhook_events.headers`) are encrypted by Eloquent
   casts (ADR-014 Decision 3); a serialized queue payload passes through no cast.
4. **Nothing erases that row.** `PurgeExpiredPayloads` writes only `webhook_events` and
   `dispatched_payloads` (ADR-012). `routes/console.php` schedules four things — invitation
   cleanup, the FIFO sweeper, the retry sweeper, `payloads:purge-expired` — and **no**
   `queue:prune-failed`. `failed_jobs` rows persist indefinitely and have no retention of their
   own.
5. So for an event whose payload retention has already erased and marked cleaned, a plaintext
   copy of that event's body and headers can remain readable in `failed_jobs` — through a
   genuine **system path** (direct DB access, any future failed-job admin surface,
   `queue:retry`, an export).

Two boundaries worth stating precisely, because they are what make this a *Minor*:

- **AC6's "as a side effect of the pass" clause is not violated.** The pass creates no copy.
  The copy pre-exists the pass and is created by the dispatch mechanism.
- **This is pre-existing from #4 / ADR-011**, not introduced or worsened by #5. #5 added no
  queued job class and did not change `DeliveryUnit`.

### What #6 changed — checked, and it did widen the exposure

The task asked whether #6 widened it. It did, in **frequency and throw-surface, not in kind**.

- **#6 added no new payload-carrying job class.** This is the important negative and it holds.
  `RetryDelivery` is dispatched **by reference** — its arguments are `int $deliveryId, int
  $attemptNumber` only (ADR-015 Decision 5; `RetryDelivery.php:38,48`), and it rebuilds the
  `DeliveryUnit` in-process from the stored row. Replay is likewise by reference —
  `ProxyEventReplayController` dispatches `ProcessIngestedWebhook::dispatch($event->ingest_id,
  $dispatchUuid)` or `AdvanceProxyFifoQueue::dispatch($proxy->id)` (`:91-95`). Neither carries
  payload bytes in its own job arguments. Retries of an Async delivery therefore run
  `DeliverToDestination::run()` **inline** inside `RetryDelivery` (`RetryDelivery.php:78`), so a
  throw during a retry fails the *by-reference* job and writes **no** payload to `failed_jobs`.
- **But replay multiplies the payload-carrying dispatches.** Before #6, one received event
  produced exactly one Async fan-out (one payload-carrying job per destination, once). After
  #6, every manual replay of that event runs the same pipeline again and queues a fresh set of
  payload-carrying `DeliverToDestination` jobs (attempt 1 of each new `deliveries` row). One
  event can now generate payload-carrying queued jobs repeatedly, for as long as it is
  retained.
- **And #6 widened the set of uncaught-throw paths inside the payload-carrying job.** Before
  #6 the only rethrow was the non-race `QueryException` on attempt-row creation
  (`DeliverToDestination.php:90-96`) — the path the Reviewer cited. #6 added
  `settleDelivery()`, called at `:170` **outside** the `try/catch` that guards the outbound send
  (`:140-168`). It contains `Delivery::query()->findOrFail(...)` (`:185`), the
  `RetryPolicy::attemptLimitFor()` / `delayBefore()` reads (`:198,216`) — which since review-06
  Major 2 **throw `RuntimeException`** on a non-positive `retry.*` config value
  (`RetryPolicy.php:119-133`) — two compare-and-set updates, the FIFO completion CAS, and a
  delayed dispatch. Any of those throwing produces a `failed_jobs` row carrying the plaintext
  unit. The blast radius of a single mis-set `retry.*` env value is now "every Async delivery
  fails **after** sending, and each one durably records its payload in plaintext".

Net: the exposure is real, is wider after #6 than it was after #5, and will widen again at #8
(mapping) and #9 (multi-format ingestion), which add more payload-shaped work to the same path.

### Why this lands on #10's doorstep

Roadmap #10 — *Sensitive data handling* — reads: "**Stored payloads are encrypted**, known and
user-defined sensitive fields are visually obfuscated, and incoming webhooks can be verified
with a token at an MVP level." Its build-ahead note says encryption and field obfuscation apply
to the raw+dispatched payloads defined at #5.

ADR-014 already moved the at-rest floor from one column to **three across two tables**
(`webhook_events.body`, `webhook_events.headers`, `dispatched_payloads.body`) and bound
`APP_PREVIOUS_KEYS` to all three. A durable plaintext `failed_jobs` row is the one remaining
at-rest copy of the same content with **none** of that protection and **none** of the lifecycle.
It sits squarely in #10's path, and #10 will inherit whatever is decided here — which is
exactly why the Reviewer recorded it.

One further reason the requirement must be stated **backend-agnostically**: roadmap **V3**
(scalable queue/streaming choice beyond Redis) is still open, and the default connection today
is `database` (`config/queue.php:16`), so the `jobs` table holds the same plaintext unit while a
job is merely *queued* (transient — the row is deleted on success). A requirement written
against "the `failed_jobs` table" would be silently voided by a queue-backend change. It must be
written against *any durable at-rest copy of payload content*, wherever the dispatch mechanism
puts it.

## Question

**Does PRD-05 AC6's guarantee — "After the expiry pass, none of that event's payload content is
retrievable through *any* user-facing or system path" — reach `failed_jobs` (and equivalent
queue-infrastructure copies), or is AC6 scoped to the payload stores retention actually
governs?**

Both readings are defensible on the text, and they lead to materially different work:

- The **broad** reading takes "any … system path" literally. `failed_jobs` is a system path;
  the guarantee is unmet; #5 as merged does not satisfy its own AC6.
- The **narrow** reading takes AC6 as a criterion on the **retention lifecycle** — it enumerates
  its subject matter ("the raw body; the captured inbound **headers** (AC22); and any stored
  dispatched output body for the same event (AC12)") and its qualifier ("as a side effect of the
  pass"), and binds only the stores retention owns.

## Options with their costs

**Option A — Broad reading. AC6 reaches `failed_jobs`; #5 is non-compliant until it is fixed.**
- *Cost:* declares an Owner-approved, reviewed, merged feature retroactively non-compliant on a
  mechanism that predates it (#4/ADR-011) and that neither plan-05 nor ADR-012/013/014 was ever
  asked to address. Opens rework attributed to #5 for a defect #5 did not create. Makes AC6
  binding over queue infrastructure whose lifecycle retention does not own — a retention pass
  cannot guarantee erasure in a store it neither writes nor controls, and ADR-012's hold set
  (H0–H4) has no vocabulary for it. Forces technical work now, ahead of #7, for an exposure that
  #10 must address comprehensively in any case — and a narrow "scrub `failed_jobs`" fix landed
  now would likely be re-done at #10.
- *Benefit:* the exposure is closed sooner and no one has to trust a carry-forward.

**Option B — Narrow reading. AC6 is scoped to the payload stores retention governs; the
exposure is a **sensitive-data-handling** requirement carried to #10 with a named destination.**
- *Cost:* narrows the reach of an Owner-approved criterion, so it needs an Amendment and Owner
  ratification — it cannot be a quiet reword. Leaves a real plaintext exposure standing until
  #10 ships, which the Owner must accept explicitly (**E1**). Depends on the carry actually
  being honoured at #10, which is why D2 is written as a gate on #10's PRD rather than a note.
- *Benefit:* puts the requirement where the capability lives. #10 already owns "stored payloads
  are encrypted", already owns the key policy, rotation and re-encryption tooling PRD-05 §Out of
  Scope assigns to it, and is the only place the *complete* inventory of at-rest payload copies
  can be closed in one pass rather than one table at a time. Keeps #5's and #6's records
  accurate.

**Option C — Split: AC6 stays broad in principle, with an explicit, time-boxed carve-out for
queue infrastructure until #10.**
- *Cost:* the same Amendment and the same Owner acceptance as B, plus a criterion that is
  simultaneously asserted and suspended — the worst documentation outcome, and one
  `docs/standards/documentation.md` discourages. It also leaves #5's AC6 formally unmet in the
  review record with no path to closing it at #5.
- *Benefit:* none over B that D2 does not already deliver.

## Impact if unresolved

It stayed unresolved for twenty days and #6 shipped through it. That is the impact, concretely:
the exposure widened (replay, plus the new post-send throw paths) with no one holding the
question, and review-06 did not re-raise it. Left unresolved further, #10 enters Requirements
with "stored payloads are encrypted" reading naturally as "the three columns ADR-014 already
covers", the `failed_jobs` copy is not in anyone's inventory, and #10 ships believing the
at-rest floor is complete when it is not. #8 and #9 then add payload-shaped work to the same
queue path on top of an unstated gap.

## Downstream

- **PRD-05** — Amendment B (below). Requires Owner ratification; PRD-05 stays **Approved**, not
  reopened. *(I could not apply it in this session: the working tree is on an unrelated branch
  with another agent committing to it. The exact text is given below for application to `main`.)*
- **PRD-10 (not yet written)** — must carry **D2**. Named, mandatory, with the acceptance
  criteria stated below.
- **Principal Engineer** — follow-ups **F1** and **F2** below. Non-blocking for #7.
- **Project Owner** — escalation **E1** below.
- **review-05 finding 3** — closed by this doc as to its Product Manager half. Its Principal
  Engineer half ("then to the Principal Engineer if in scope") resolves to **F1**/**F2**, scoped
  to #10 rather than #5.
- **Q-05-05** is reserved for review-05 **finding 2** (the partial-fan-out Async hold gap →
  Principal Engineer), which is still unwritten. This doc takes **Q-05-06** so that numbering is
  not disturbed when finding 2 is written up.

## Answer

### Ruling — **Option B. AC6 is scoped to the payload stores the retention system governs. It does not reach `failed_jobs`.**

Requirement scope is mine as the Owner's proxy, and this one is derivable from the approved
documents rather than being a new business decision. The reasoning, in order of weight:

**1. AC6 is a criterion on the retention lifecycle, and it names its own subject matter.** The
criterion opens "**After the expiry pass**", defines payload content by enumeration — "the raw
body; the captured inbound **headers** (AC22); and any stored dispatched output body for the same
event (AC12)" — and qualifies its prohibition on reduced copies with "**as a side effect of the
pass**". Every one of those three named items is a store the retention pass writes. Read whole,
AC6 is the completeness guarantee *of the pass over the stores it governs*, not a system-wide
census. The "any … system path" phrase does real work inside that scope: it forecloses reading
the guarantee as UI-only, and it is what forbids a #6 replay from serving erased content — which
is exactly how ADR-017 and `RetryDelivery::terminalizeCleaned()` implement it.

**2. Retention cannot guarantee what it does not own.** The stores AC6 names have a lifecycle
retention defines: captured at ingest, retained 30 days, erased in place, marked cleaned
(AC1–AC12, AC21). A queued or failed job exists because a *dispatch* is in progress or has
failed; it is created and destroyed by the dispatch mechanism (#4/ADR-011, extended by
#6/ADR-015–017), on a schedule that has nothing to do with the retention window. Binding AC6
over it would make the retention pass responsible for a store it neither writes nor reads —
ADR-012's whole composition argument with #4 rests on GC and the dispatch mechanism sharing
**zero written tables** (ADR-012, via Q-05-04(ii)), and Option A would reverse that at a stroke.
This is the structural reason, and it is why the Reviewer rated the finding Minor rather than
Major.

**3. The precedent in this project is that a known plaintext-at-rest exposure is carried to #10
with an explicit Owner acceptance — not retrofitted into an unrelated criterion.** ADR-010
Amendment B did exactly this for inbound headers: "**inbound `headers` remain plaintext at rest
until #10** — the Owner accepts this explicitly." Amendment A later pulled that one slice forward
because it was free (headers were already in the cleanup). No equivalent is free here: nothing in
#5's pass touches queue infrastructure, so there is no zero-cost slice to pull forward. The
matching move is the ADR-010 Amendment B move — name it, bound it, carry it to #10, and get the
acceptance on the record.

**4. Option B is not "defer and forget", because D2 is written as a gate.** The failure mode I
am guarding against is the one that already happened once: a real exposure sitting in a review
finding that nobody owns. D2 below states what #10's PRD must contain, and #10 does not pass
requirement approval without it.

**What this ruling does *not* say.** It does not say the exposure is acceptable in perpetuity,
and it does not say #5 or #6 was wrong. It says the requirement lives at #10, not at #5.
AC15's at-rest floor is untouched and still forbids **#5** from creating a less-protected copy of
payload content — #5 created none.

### Amendment B to PRD-05 — text to apply

I cannot edit `docs/product/prd-05-payload-storage-retention.md` in this session (the working
tree is on an unrelated branch with another agent committing to it). The text below is what
should be applied, verbatim, on `main`. It **narrows the reach of an Owner-approved criterion**
and therefore requires **Project Owner ratification** — it must not be applied as a silent
reword (`docs/standards/documentation.md`; the same rule Amendment A was written under).

**(a) Status line — add, after the Amendment A line:**

> - **Amendment B / date:** **Product Manager ruling as Owner's proxy, 2026-08-25 — pending
>   Owner ratification.** States the **scope** of AC6's "any … system path": the criterion binds
>   the payload stores the retention system governs and does **not** reach copies of payload
>   content held by queue or transport infrastructure. Adds deferred concern **D2**, which
>   carries that exposure to roadmap **#10** as a binding requirement. Narrows no other
>   criterion and reverses nothing in Amendment A. Basis:
>   `docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md` (RESOLVED),
>   `docs/reviews/review-05-payload-storage-retention.md` finding 3.

**(b) AC6 — append this paragraph to the criterion, tagged (B). Nothing existing is reworded:**

> *(Amendment B — scope, 2026-08-25.)* **"Any user-facing or system path" is bounded by the
> stores this criterion enumerates.** AC6 binds the payload stores the **retention system
> governs**: the captured event's raw body and captured inbound headers (AC22), and the stored
> dispatched output for the same event (AC12). It does **not** extend to copies of payload
> content held by **queue or transport infrastructure** — a serialized queued job argument, a
> failed-job record, or any equivalent artefact of the dispatch mechanism (#4/ADR-011;
> #6/ADR-015, ADR-016, ADR-017). Such copies exist because a dispatch is in progress or has
> failed; they are created and destroyed by the dispatch mechanism on its own schedule, not by
> retention, and a retention pass cannot guarantee erasure in a store it neither writes nor
> reads (the zero-shared-written-tables property ADR-012's composition with #4 depends on).
> Those copies are **not** thereby accepted as permanent plaintext: bounding and protecting them
> is a **sensitive-data-handling** requirement, carried to roadmap **#10** by deferred concern
> **D2**, which #10's PRD must discharge explicitly. AC15 is unchanged and still forbids **#5**
> from creating a less-protected copy of payload content.

**(c) § Deferred concerns — add D2 alongside D1:**

> - **D2 — Payload content in queue and failed-job infrastructure — DEFERRED to roadmap #10
>   (named destination, gating #10's PRD; not open-ended).** On an Async proxy, the per-destination
>   unit of work carries the event's body and headers **verbatim** and is serialized into the
>   queue backend; a unit whose job throws is written durably to the failed-job store in
>   **plaintext**, with no at-rest encryption and no retention of its own. Payload content for an
>   event that retention has already erased and marked cleaned can therefore remain readable
>   through a system path. **Pre-existing from #4/ADR-011**, widened by #6 (manual replay re-queues
>   payload-carrying units for an event repeatedly, and the post-send settlement path added new
>   uncaught-throw routes inside a `$tries = 1` job); **not** created by #5, and not addressable by
>   a retention pass (AC6 as scoped by Amendment B).
>
>   **Settlement:** #5 asserts **no** requirement over queue or transport infrastructure and adds
>   no scrubbing, encryption, or pruning of it. The requirement moves to **#10**, whose approved
>   roadmap line already reads "**stored payloads are encrypted**" — a durable plaintext copy of a
>   stored payload is within the plain meaning of that line, so this is a scoping of #10, not an
>   addition to it. Confirmation of that reading belongs to #10's PRD approval.
>
>   **What #10's PRD must carry** (it does not pass requirement approval without these):
>   1. An acceptance criterion that **every durable at-rest copy of payload content the system
>      creates** carries at least the AC15 protection floor — stated **backend-agnostically**
>      ("any durable at-rest copy, wherever the dispatch mechanism places it"), never against a
>      named table, because roadmap **V3** may change the queue backend.
>   2. An acceptance criterion that **no durable plaintext copy of payload content outlives its
>      event's retention window**, or that no durable plaintext copy exists at all — whichever
>      the Owner rules at #10.
>   3. An **inventory** of every at-rest location holding payload content at the time #10 is
>      written, so no location is missed. Today's known set: `webhook_events.body`,
>      `webhook_events.headers`, `dispatched_payloads.body` (all encrypted per ADR-014), plus the
>      queue backend's job payloads (transient) and the failed-job store (durable) — both
>      plaintext. Producing the authoritative, complete inventory is a **Principal Engineer**
>      task (follow-up F1 in Q-05-06), not the Product Manager's.
>   4. A statement that any mitigation must **preserve failure diagnosability** (an operator must
>      still be able to see that a delivery failed and why), must **not** weaken the
>      at-least-once/idempotency behaviour established at #4 (ADR-011 Decision 4) and #6 (ADR-015
>      Decision 2), and must **not** reintroduce payload content into logs
>      (`docs/standards/coding.md` → *Never log*). Mechanism is the Principal Engineer's.
>   5. A cross-reference to `docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md` and
>      `docs/reviews/review-05-payload-storage-retention.md` finding 3, so the origin is not lost.
>
>   **Not a requirement at #5:** no scrubbing, no encryption of queued units, no retention pass
>   over failed jobs, no numeric target. No #5 acceptance criterion depends on any of this.
>
>   **Owner acceptance (E1) — ACCEPTED, Project Owner, 2026-08-25:** the residual exposure stands from now until #10 ships.
>   The Project Owner is asked to accept that explicitly, on the ADR-010 Amendment B precedent
>   ("inbound headers remain plaintext at rest until #10 — the Owner accepts this explicitly").
>   If the Owner declines, the mitigation is scheduled ahead of #10 as its own work item — it
>   does **not** reopen #5.

**(d) § Open Questions — add:**

> - **Q-05-06 (Product Manager, requirement scope) — RESOLVED 2026-08-25.** Doc:
>   `docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md`. Does AC6's "any … system path"
>   reach the failed-job store? **No** — AC6 binds the payload stores retention governs
>   (Amendment B). The exposure is carried to #10 as deferred concern **D2**, with Principal
>   Engineer follow-ups F1/F2 and Owner escalation E1 recorded in the question doc. Raised by
>   `docs/reviews/review-05-payload-storage-retention.md` finding 3 (2026-08-05).

### E1 — escalated to the **Project Owner** (one ruling needed)

`CLAUDE.md` reserves security decisions to the Owner, and this is one — but only this half of
it. The scope question is answered above and is not being punted.

> **What I need ruled:** do you accept that a plaintext copy of payload content can persist in
> the failed-job store, indefinitely and outside the retention window, **from now until #10
> ships** — on the same footing as the plaintext-headers exposure you accepted explicitly at
> ADR-010 Amendment B?
>
> **If yes:** D2 stands as written, #10 discharges it, and nothing is scheduled before #10.
> **If no:** the mitigation becomes a work item scheduled ahead of #10 — routed to the Principal
> Engineer (F2) for shaping, not to #5. Either way #5 is not reopened and #7 is not blocked.
>
> **What it costs to say yes:** the exposure is currently narrow — it requires a delivery job to
> throw outside its own transport `try/catch`, which today means a database error on attempt-row
> creation, a missing `deliveries` row, or a mis-set `retry.*` config value. It is not narrow in
> that last case: a single bad `retry.*` env value makes **every** Async delivery throw *after*
> the send, so a bad deploy could durably record a large fraction of one day's payloads in
> plaintext at once. That is the scenario worth weighing.
>
> **What it costs to say no:** work ahead of #10 on a mechanism #10 will revisit anyway, and a
> mitigation designed before the full inventory (F1) exists.

You are also asked to **ratify Amendment B**, which narrows the reach of a criterion you
approved. I have written it as a scoping, not a weakening — the guarantee's subject matter is
unchanged and the gap is carried, not dropped — but the call is yours.

### Routed to the **Principal Engineer** — two named follow-ups (neither designed here)

**F1 — Produce the authoritative inventory of durable at-rest payload-content copies.** Every
location where the body or the captured headers of an event exist at rest outside the three
encrypted columns ADR-014 covers — the failed-job store, the queue backend's own job storage
while a job is queued or delayed, and anything else (cache, batch, telemetry) the current stack
puts them in. Deliverable: an enumeration #10's PRD can carry as D2 item 3, and a statement of
which entries are **transient** (destroyed by the mechanism on success) versus **durable**
(persist until something removes them). This is an enumeration, not a design. It should be
produced **before** #10's PRD is written, and is otherwise non-blocking.

**F2 — Assess what a mitigation would cost, and when it should land.** Whether an approach
exists that satisfies D2 items 1, 2 and 4 without disturbing #4/#6's dispatch guarantees, and
whether it is small enough to be worth landing ahead of #10 (relevant if the Owner declines
E1). The Reviewer's routing note listed candidate directions — failed-job payload scrubbing,
encryption at rest for queued units, a retention pass over failed jobs — and I am naming them
only as the space to be assessed, **not** as a recommendation; the choice, and any ADR, is
yours. Constraints from the requirement side are D2 item 4 (diagnosability, idempotency, never
log payload content) and nothing else.

Neither F1 nor F2 gates feature #7, which is in UX Design and touches none of this.
