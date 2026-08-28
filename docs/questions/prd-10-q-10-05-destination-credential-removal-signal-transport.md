# Q-10-05: The transport for Screen 3's "Remove credential" signal

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Task Planner, while breaking down `docs/plans/plan-10-sensitive-data-handling.md`
  § *Milestones* M7 into `docs/tasks/sensitive-data-handling-tasks.md`
- **Directed To:** **Principal Engineer**
- **Required By:** before the Screen 3 "Remove credential" task (T31) is implemented. **Non-blocking
  for the rest of M7 and for the feature as a whole** — every other M7 task, and every later
  milestone, is buildable without this answer.
- **Priority:** Low
- **Status:** OPEN
- **Raised:** 2026-08-27

## Question

`design-10`'s amendment gate (`## Approval record — amendment gate (2026-08-27)`, correction **B3**)
requires that Screen 3's new **Remove credential** control be carried to the server as a distinct,
explicit signal — never collapsible into the ordinary "field left blank" case that
§ *Interactions*' write-only rule already protects ("a present-but-empty secret field must not submit
as 'clear the secret'"). The design spec states this requirement and then declines to pick a
mechanism:

> "**Clicking Remove credential and leaving a Replace field blank must never be indistinguishable to
> the form**, however the two are eventually carried to the server — that transport is the
> **Principal Engineer's** call, not specified here; what this spec fixes is that the two states have
> to stay distinguishable end to end..."

**`plan-10` does not answer this**, because the Remove-credential control did not exist when the plan
was certified — `plan-10` § *Out of Scope* only records that `design-10` "designs none" and that
"whatever `Q-10-03` answers is additive," and Q-10-03 itself was answered by the Designer in the same
amendment pass that produced correction B3, after `plan-10`'s four Owner-approval flags had already
been ruled. So there is a decision `design-10` explicitly assigns to the Principal Engineer that no
approved technical-design artifact has made.

**This is not something the Task Planner can decide.** Picking a wire shape — a sentinel value on
`credential_secret`, a sibling boolean per destination row (e.g. `destinations.*.remove_credential`),
or something else — is a new request/validation interface, which is design work the technical-plan
gate covers, not task breakdown. Writing a task against a guessed shape risks either inventing an
interface `docs/standards/planning.md`'s "AC-trace" rule and this role's "no pseudo-code beyond the
plan" boundary don't allow, or leaving T31 too vague to be independently verifiable — the same
"a task that cannot be made small and verifiable signals a plan problem" trigger that routes to you
rather than to a written-anyway task.

**Two shapes seem obviously compatible with everything already approved**, offered so the answer can
be quick rather than because either is presumed:

1. **A sibling flag per row**, e.g. `destinations.*.remove_credential: boolean`, validated
   `prohibited_if:destinations.*.credential_secret,filled` (removal and a fresh secret in the same
   submission would be a contradiction) and consumed by whichever action rebuilds
   `credential_header_name`/`credential_secret`/`credential_set_at` to their unset state. This keeps
   `credential_secret` meaning exactly one thing ("a new value, or absent") and needs no sentinel
   string.
2. **A reserved sentinel value on the existing field** (e.g. `credential_secret: ""` explicitly sent,
   distinguished from the field being **absent** from the payload at all — Inertia's
   `form.transform()` can send `null` or an empty string on purpose only when Remove was clicked, and
   omit the key entirely otherwise). This adds no new field but relies on "key present vs. key absent"
   being reliably distinguishable end to end, including through whatever Inertia/Axios serialization
   this app already uses for the rest of the form.

Either is small, testable, and touches only `StoreProxyRequest`/`UpdateProxyRequest` and
`ProxyController`'s persistence step (per `plan-10` § *Services & Actions*, the components already
scheduled to change in M7) — nothing else in the plan moves either way.

## Context

- `design-10` is approved, including its amendment and the amendment gate's four required
  corrections B1–B4 (`docs/design/design-10-sensitive-data-handling.md`
  § *Approval record — amendment gate (2026-08-27)*). B3 is the correction this question answers.
- `docs/status.md` item #10 already flags B3 as landed on the design side and names its consumer:
  "B3 concerns Screen 3, which belongs to M7's territory, and `plan-10` already treats the removal
  affordance as additive, so the Principal Engineer decides where it lands." This question is that
  decision being asked for, not a re-litigation of whether the control should exist (it should —
  Q-10-03 already settled that) or where it lands (M7, per that same note and per
  `docs/tasks/sensitive-data-handling-tasks.md` T31).
- Every other M7 task builds against interfaces `plan-10` already names (`OutboundHeaders`,
  `DeliveryUnitResolver`, `StoreProxyRequest`/`UpdateProxyRequest`'s existing credential rules) and is
  unaffected by this question either way.

## Impact if unresolved

Low and fully contained. T31 (Screen 3's Remove-credential control) stays blocked; every other task in
`docs/tasks/sensitive-data-handling-tasks.md` — including the rest of M7, all of M8a/M8b, and the M9
hardening sweep — is unaffected and proceeds. The feature can ship its whole signing and verification
surface with the credential removal affordance following later as the additive change `plan-10` and
Q-10-03 both already expect.

## Answer

_Pending — Principal Engineer._
