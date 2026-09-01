# Task Plan: Destination validation (#18)

- **Status:** **Approved by the Task Planner, 2026-08-31.** No Owner gate at this stage; the Reviewer
  catches drift.
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-18-destination-validation.md` (Approved, 2026-08-31)
- **PRD:** `docs/product/prd-18-destination-validation.md` (Approved, Project Owner, 2026-08-31)
- **Design:** `docs/design/design-18-destination-validation.md` — reference only; the Designer gate
  was dropped by Owner ruling on 2026-08-31. Use it as the screen inventory for M5.
- **Authorities:** ADR-027 (Accepted, Project Owner, 2026-08-31) · Q-18-01 (Answered) · Q-18-02
  (Closed — AC23 narrowed to this application's own layers)
- **Approved by / date:** Task Planner, 2026-08-31

> **Scope and conventions.** Read this index for orientation, then only the milestone file you are
> working in. Every task states its files, the acceptance criteria it satisfies, a runnable verify
> step and its tests. A task is done only when `composer lint`, `composer types:check` and
> `./vendor/bin/sail test` all pass and completion notes are recorded. Commit per task on
> `docs/prd-18-destination-validation`; push, PR and merge stay Owner-gated.

## Binding constraints — these are not negotiable at task level

1. **The gate is four points, not two.** Delivery-row creation, the worker, the retry sweep and the
   replay controller. Pause's own enforcement points are per-proxy and are deliberately **not**
   reused — see Q-18-01 answer 1. Do not add a per-destination test to `ProcessIngestedWebhook`'s
   pause guard or to `AdvanceProxyFifoQueue`'s claim guard.
2. **Skipped means no row, not a skipped row.** A non-validated destination produces no `deliveries`
   row and therefore no `delivery_attempts` record. Never write a row and mark it skipped.
3. **The validation send never carries the destination's stored credential** (AC17) and never routes
   through `DeliverToDestination` or the delivery pipeline.
4. **The validation link is never displayed inside the product** (AC24). Not in a flash message, not
   in a response payload, not in a test fixture committed to the repo, not in a log line.
5. **Address refusal applies to validation sends only** (AC40). Ordinary delivery is untouched.
6. **First-party before custom** (Owner standing preference). `URL::temporarySignedRoute` and the
   `signed` middleware carry the link; the `RateLimiter` facade carries the limits.

## Milestones

| # | Subject | Tasks | File | State |
|---|---|---|---|---|
| M1 | Validation state on `destinations` | T1–T3 | `m01-validation-state.md` | **Done, 2026-08-31** |
| M2 | The four enforcement points | T4–T7 | `m02-enforcement.md` | **Done, 2026-08-31** |
| M3 | The guarded challenge send | T8–T11 | `m03-challenge-send.md` | **Done, 2026-08-31** |
| M4 | The approval surface | T12–T14 | `m04-approval-surface.md` | **Done, 2026-08-31** |
| M5 | Member-facing UI | T15–T20 | `m05-member-ui.md` | T15–T18 **Done, 2026-08-31**; T19–T20 **Not started** |

T19 and T20 were added after the first review pass: review-18 finding 6 found PRD-18 AC35 —
the outcome of the most recent validation send — implemented by nothing and traced by no task in
this plan. That is a coverage defect in the task plan, not implementer drift, so it is repaired
here. The Project Owner approved the schema change it needs on 2026-08-31 and chose to implement
AC35 rather than defer it. M5 reopens for these two tasks only; T15–T18 stand as shipped.

Ordering is dependency-driven. M1 first because everything reads the state. M2 next and it is a
no-op on landing, since T3 backfills every existing destination to validated — the gate is live
before anything can be unvalidated, which is what makes M3 safe to add. M4 needs M3's nonce. M5 is
last and is the only milestone that reads design-18.

## Handoff

- **Inputs:** plan-18, PRD-18 Acceptance Criteria, ADR-027, `docs/standards/planning.md`
- **Outputs:** this task plan
- **Dependencies:** none outstanding
- **Outstanding Questions:** none
- **Next Agent:** Senior Developer, at M5/T19 — the rework pass. T1–T18 are shipped; review-18
  findings 1–5 and 7–10 are reworked in `315b7f4`. T19 and T20 close finding 6, after which the
  Reviewer re-reviews the whole item.
