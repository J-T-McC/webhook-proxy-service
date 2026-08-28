# Q-10-02: The authoritative at-rest payload-copy inventory (D2 item 3 / F1), and whether AC5 holds for the failed-job surface

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Product Manager, while drafting `docs/product/prd-10-sensitive-data-handling.md`
- **Directed To:** Principal Engineer
- **Required By:** Before #10's technical design. **Non-blocking for requirement approval** — PRD-10
  can be approved by the Project Owner with this open, in the same way PRD-05 was approved with
  Q-05-03 open.
- **Priority:** Medium
- **Status:** Open
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

*(Unanswered.)*
