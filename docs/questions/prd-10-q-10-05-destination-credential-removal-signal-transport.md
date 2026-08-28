# Q-10-05: The transport for Screen 3's "Remove credential" signal

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Task Planner, while breaking down `docs/plans/plan-10-sensitive-data-handling.md`
  § *Milestones* M7 into `docs/tasks/sensitive-data-handling-tasks.md`
- **Directed To:** **Principal Engineer**
- **Required By:** before the Screen 3 "Remove credential" task (T31) is implemented. **Non-blocking
  for the rest of M7 and for the feature as a whole** — every other M7 task, and every later
  milestone, is buildable without this answer.
- **Priority:** Low
- **Status:** **RESOLVED — Principal Engineer, 2026-08-27.** Shape 1 is ruled: a sibling boolean
  `destinations.*.remove_credential` per destination row, with `credential_secret` keeping exactly
  one meaning ("a new value, or absent"). The reserved-sentinel shape is rejected on its failure
  direction, not on its ergonomics — see § Answer. Recorded in
  `docs/plans/plan-10-sensitive-data-handling.md` § *Revision A* (technical ruling 15, with the
  validation, persistence and test additions). **No ADR** — the reasoning is in § Answer and repeated
  in the plan's ruling.
- **Raised:** 2026-08-27
- **Resolved:** 2026-08-27

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

**Principal Engineer, 2026-08-27.** Shape 1 — the sibling boolean — is ruled, in the exact form set
out below. This is the transport `design-10`'s correction B3 assigns to me, and it is an ordinary
request-interface choice inside a plan whose data model and security posture the Project Owner has
already approved; it therefore lands as an addition to `plan-10` rather than as an ADR or a new gate.
The reasoning is here; `plan-10` § *Revision A* carries the same ruling as technical ruling 15 so an
implementer finds it in the plan rather than in a question document.

### The ruling — one boolean per destination row

**`destinations.*.remove_credential`**, a real JSON boolean, submitted on the existing proxy
create/update routes alongside the row's `id`, `url`, `http_method`, `credential_header_name` and
`credential_secret`. Absent or `false` means no removal; `true` means "clear this destination's
credential on save".

`credential_secret` keeps **exactly one meaning** and gains no second one: *a new value, or absent.*
Absent still means "leave unchanged", which is the write-only contract `plan-10` § *Validation*
already certifies and which `design-10` § *Interactions* requires. Nothing about a blank, an empty
string or a missing key acquires the power to destroy a stored secret.

**Validation**, added to both `StoreProxyRequest` and `UpdateProxyRequest`:

- `destinations.*.remove_credential` — `sometimes`, `boolean`.
- `destinations.*.credential_secret` — gains `prohibited_if:destinations.*.remove_credential,true`.

The wildcard-sibling reference is the same mechanism the plan's already-certified
`required_with:destinations.*.credential_secret` rule on `credential_header_name` relies on, so this
introduces no new validation capability. The guard exists so a malformed client gets a deterministic
422 rather than an outcome chosen by a precedence rule: whichever precedence were chosen, one of the
member's two explicit acts would vanish without feedback, and that is the class of silence B3 exists
to prevent.

**Persistence.** On a submitted row where `remove_credential` is `true` and the row reconciles to an
existing destination by `id`, the update writes NULL to all three credential columns —
`credential_header_name`, `credential_secret`, `credential_set_at` — and ignores any submitted
`credential_header_name` for that row. All three move together so the row cannot come to rest holding
a header name with no secret. The result is byte-identical to a destination that never had a
credential, which is precisely the post-save state B3 added to Screen 3's states table.

**A row with no `id`** — a destination added in this session — has nothing to remove, so
`remove_credential` is a no-op on the create path and on newly-created rows in the update path. The
rule is declared on both requests all the same, because `ProxyForm.vue` is one component serving
Create and Edit and submits one row shape; a rule present on one request and absent from the other is
the kind of divergence that is discovered by a 422 in production rather than by reading.

**One client-side normalisation, and why it is not a design decision.** After the member clicks
Remove credential, `design-10`'s states table puts the row into the unconfigured presentation — "same
as an unconfigured row", with a blank Secret input. A member may then type a new secret into it.
Typing a value into an unconfigured row has always meant "set this secret", so the row's later act
supersedes the staged removal: `form.transform()` sends `remove_credential: false` whenever that
row's `credential_secret` is non-empty. This authors no copy, adds no control and invents no state —
it is the approved states table read forwards. Its effect is that the 422 above is unreachable from
this application's own UI and remains purely a guard against a malformed request.

### Why not the reserved sentinel, which is the more interesting half of the question

The sentinel shape is rejected on **failure direction**, not on ergonomics or on aesthetics.

**It encodes a deliberate member action in the absence of data.** Absence is the default state of
every layer a request passes through. A proxy, a middleware, a serializer, a normaliser, a `??`
coalesce, an `array_filter`, a `$request->only()` — each of them can turn "present and null" into
"absent" or the reverse, and none of them errors when it does. A positive `true` is a value: nothing
on the path invents one, nothing silently removes one, PHPStan sees it and the validator sees it.

**One of those layers already conflates the two, in code this feature is scheduled to touch.**
`ProxyController::destinationRows()` normalises each submitted row with `isset()`
(`isset($row['id']) && is_numeric(...)`, and the same shape for `url` and `http_method`), and
`isset()` is `false` for an explicit `null`. Under the sentinel shape the removal signal is destroyed
by the house normaliser that every destination row already passes through, and reviving it means
replacing `isset()` with `array_key_exists()` for that one key — a distinction whose loss produces no
error, no warning and no test failure, only a save that quietly did nothing.

**The distinction does survive validation, which is what makes it a trap rather than an obvious
mistake.** Read in vendor: `Validator::validated()` walks `getRules()` and skips any key whose
`data_get($this->getData(), $key, $missingValue)` returns the sentinel object, and
`ValidationData::initializeAndGatherData()` expands `destinations.*.credential_secret` into concrete
per-row keys against the submitted rows. So an explicitly-null `credential_secret` survives into
`validated()` and an absent one does not. The distinction is therefore real at the validation layer
and destroyed at the very next one. A property that holds at three layers out of four is worse than
one that holds nowhere, because it tests green in isolation.

**The two shapes fail in opposite directions, and only one of them fails safe.** If a
`remove_credential` boolean is lost anywhere on the path, the outcome is that the credential is *kept*
— the member sees it still set, and clicks Remove again. If the absent-versus-empty distinction is
collapsed anywhere on the path, the outcome is the one `design-10` names in the sentence this question
quotes: every abandoned Replace becomes an unintended removal, silently, on a field whose value cannot
be recovered because we never had it in the clear anywhere but in transit. Given a choice between a
signal whose corruption costs a second click and one whose corruption destroys a secret, the first is
the only defensible call for a control whose entire purpose is to make removal explicit.

**A note on serialization, stated as a reason not to depend on the distinction rather than as a
verified claim.** `@inertiajs/core` is not installed in the working tree I read, so I am not
asserting its current behaviour. The point stands without it: the proxy form's payload shape is not
this feature's to freeze. The moment any caller sends this form as `FormData` — a file field, a
`forceFormData`, a future upload — "absent" and "empty" are represented in a format that has no
reliable way to keep them apart, and nothing about that change would look like it was touching
credential removal. A boolean survives every serialization this form could plausibly acquire.

### What this does not change, walked so it reads as considered rather than overlooked

- **No data-model change.** No column, no index, no migration, no cast, no default.
  `remove_credential` is a request field consumed into the three `destinations` columns the Owner
  approved at `plan-10` flag 1. It is never persisted and never read back.
- **No new endpoint, no new route, no new permission.** Removal rides the existing proxy update the
  member already holds `update` on; `design-10` fixes it as a save-time change, not an action.
- **No change to any existing ruling in `plan-10`.** Ruling 14's `SecretStore` discipline is
  untouched — the destination credential is not in `proxy_secrets` and does not rotate (ADR-021,
  AC29 excludes it). Technical ruling 7's old-input scrub is untouched: `remove_credential` is a
  boolean flag, not a secret, and needs no scrubbing, while `destinations.*.credential_secret`
  remains scrubbed exactly as ruled.
- **No confirmation dialog**, per Q-10-03's answer and `docs/standards/design.md` — nothing stored is
  exposed and the credential can be re-entered.
- **No ADR touched and none amended.** ADR-021 decides how secrets are stored; the destination
  credential's storage is unchanged by this ruling. ADR-023 decides the outbound header contract; a
  destination with no credential contributes no credential header, which is the contract it already
  describes for AC30's optional case.

### Why this is not an ADR, and not an Owner gate

Walked against `CLAUDE.md`'s major-decision list item by item: **no new dependency** (nothing is
added); **no stack change** (`docs/stack/stack.md` untouched); **no data-model change** (above);
**not irreversible** (the field is a request key; changing it later costs one form, two requests and
one controller branch, with no stored data in its shape); **security-sensitive — but in the
protective direction** (it adds no store, no egress, no reveal, no permission and no plaintext
surface, and the security-relevant property it establishes is precisely that a blank field can never
destroy a secret). What remains is an interface choice inside an approved plan, of the same kind as
the `required_with` and `prohibited_if` rules the plan already certifies without a gate. Manufacturing
an ADR for it would dilute the four this feature genuinely needed. The precedent is `plan-11`
§ *Revision A*, where a post-certification Principal Engineer ruling on a downstream question document
landed as a plan revision under the delegated plan gate, with no Owner ruling sought and none
required.

### Nothing here routes upstream

No acceptance criterion is touched, reinterpreted or relied on beyond its text: AC30 makes the
credential optional, AC33 governs its surface, and B3 states the distinguishability requirement this
answer satisfies. No copy is authored, no control is invented and no state is added to Screen 3
beyond the ones the Designer's amendment already specifies. **PRD-10 settles everything this answer
turns on**, so nothing goes back to the Product Manager and nothing goes back to the Designer.
