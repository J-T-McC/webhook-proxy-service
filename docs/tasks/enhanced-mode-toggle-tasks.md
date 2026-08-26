# Task Plan: Enhanced-mode toggle — item #7

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-07-enhanced-mode-toggle.md` (Approved — Principal Engineer
  self-certified in full, 2026-08-25; no outstanding Owner-approval flags — the one flag #7 ever
  carried, **ADR-018**, was Accepted by the Project Owner before this plan was written)
- **PRD:** `docs/product/prd-07-enhanced-mode-toggle.md` (Approved, Project Owner, 2026-08-21;
  amended 2026-08-25 — **Amendment A**: AC14(b) scoped to read surfaces, AC12's closing sentence
  split by surface kind; **Amendment B**: the Show page presents Mode in its header, not a
  "Details card". 25 acceptance criteria, numbering frozen.) · **Design:**
  `docs/design/design-07-enhanced-mode-toggle.md` (Approved, Product Manager, 2026-08-25, with the
  approval note governing over the spec body; both required corrections landed by the Designer the
  same day) · **ADR:** `docs/architecture/adr-018-mode-gated-resolution-of-enhanced-configuration.md`
  (Accepted, Project Owner, 2026-08-25; partially supersedes ADR-015 Decision 3)
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this stage —
  the Reviewer catches drift against the plan/PRD-07/ADR-018 at review time)

> **Scope / conventions.** Every task traces to plan-07 and PRD-07's ACs (AC1–AC25) or a named
> plan/ADR decision. Sequencing follows the plan's own milestones verbatim (**M1–M6**), each mapped
> to a contiguous task range below: **M1 the resolution gate + the persistence rule** (T1–T4, incl.
> review-06 Minor 8(a)+(b) landed together, and the consumer-inheritance and switch-safety
> acceptance-test coverage the plan's Test Strategy names for this concern) → **M2 read-surface
> suppression and the Edit carve-out** (T5–T6) → **M3 the form surface** (T7–T9) → **M4 the Show
> surface** (T10) → **M5 riders** (T11–T12) → **M6 quality sweep** (T13). No task depends on a later
> task.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan L7) green, and
> `./vendor/bin/sail test` green with its own tests included (`CLAUDE.md`,
> `docs/standards/planning.md`). Frontend tasks (T6–T10, T12) additionally require `pnpm lint:check`,
> `pnpm types:check`, `pnpm format:check`, and — because this feature changes rendered UI —
> **`pnpm run build`** before any manual/live check (review-06 M-3: a stale checked-in bundle
> verifies nothing).
>
> **No new dependency, no stack change, no data-model change.** No column, table, index, enum
> value, or migration (plan §Data Model — "no change, none"). No new route, permission, or gate. No
> new npm dependency, icon, or `ui/*` primitive — `AlertTitle` is an already-generated,
> currently-unused primitive; this feature is its first application use, not an addition.
>
> **There is no frontend test harness in this project** (deferred backlog item, `docs/status.md`
> "Backlog follow-ups"). Every task that touches design-07's Flows A–E, or the
> dormant-carrying-Simple-proxy save (plan Risk 4), states an explicit **manual verification**
> section with concrete steps and expected outcomes, to be filled into the Senior Developer's
> completion notes — mirroring the walking-skeleton T27 / retry-replay T32–T37 precedent. A fresh
> `pnpm run build` is required immediately before every such check.
>
> **review-06 Minor 8's (a) and (b) land in ONE task (T1), never in sequence.** The Principal
> Engineer states this twice in plan-07 (Implementation Notes and the Next Agent line): stopping
> `ProxyController`'s clearing without mode-gating `RetryPolicy`'s resolver lets a dormant value
> govern a Simple proxy — a direct PRD-06 AC2 / PRD-07 AC14(a) violation. A partial commit between
> them is not a green tree.
>
> **review-06 Minor 5 (rider 2) lands in ONE task (T12): the field, its consumer, and the inverted
> pin, together.** Landing `DeliveryResource.created_at` alone would leave both the events-detail
> replay-group label/ordering defect and a dead field — the plan is explicit that these are one
> task, not a field followed later by its use.
>
> **Load-bearing invariants carried through every task below (binding, ADR-018 Decisions 1–5):**
> - Nothing may resolve retry behaviour, or present what governs a proxy, from a persisted column
>   without first establishing `mode === Enhanced` (AC14(a)). After #7 the two retry columns are
>   read in exactly three places: `RetryPolicy::configuredAttemptLimitFor()`,
>   `RetryPolicy::configuredStrategyFor()`, and `ProxyFormResource::toArray()`. A fourth reader is a
>   defect.
> - A Simple-mode save never writes either retry column — not a value, not NULL.
> - No response other than the Edit page's may carry a dormant retry value (AC14(b)).
> - `mode` is never snapshotted, serialized into a job, or inferred from an artefact (a
>   `dispatched_payloads` row, a non-NULL retry column, a delivery's history) in either direction.
> - `RetryPolicy` is the only reader of `config('retry.*')` — all seven keys after T11 (rider 1).
> - **`PipelineFactory` is not edited by any task in this list.** Not the enum branches, not the
>   reserved `#8`/`#9`/`#12` comments. If a diff touches it, something has gone wrong (plan
>   Implementation Notes).
>
> **Scope discipline — do NOT build in this feature (plan §Explicitly out of scope / PRD Out of
> Scope):** payload mapping/reshaping (#8, AC19); any new storage/retention behaviour, retention
> window, GC, or at-rest-floor change (AC20); any retry/replay semantic, value, default, or cap
> change (AC21); any processing-mode (Async/FIFO) behaviour change (AC22); a third mode or
> per-capability sub-toggles (AC23); any notification, analytics, or audit surface for mode changes
> (AC24); any numeric target (AC25). **Two items are explicitly excluded from this task list and
> must NOT appear in any task below** — both are already routed to the Senior Developer on a
> separate branch off `main` (flat path per `CLAUDE.md`), per plan §"Explicitly out of scope for
> this plan": the `DeliverToDestination.php:197` soft-delete `TypeError`, and the missing
> `trustProxies()` configuration in `bootstrap/app.php`. Neither is scheduled here, and the Reviewer
> should expect neither in #7's diff.
>
> **Inspection-only, no automated gate (plan §Test strategy):** AC4, AC7, AC8, AC15, AC18,
> AC19, AC22–AC25 each assert the *absence* of something (no backfill, no new mode gate, no
> entitlement, no conflation of the two axes, no new step, no mapping, no processing change, no
> third mode, no audit surface, no numeric target) and are satisfied by construction — none gets
> its own task. AC4 gets one cheap positive proof (no migration file in the diff), folded into T13.

---

## M1 — The resolution gate + the persistence rule

## T1 — Mode-gated resolution + persistence-by-omission (review-06 Minor 8(a)+(b), ONE task — binding) (AC6(b), AC14(a), AC14(b)(iv), AC21; ADR-018 Decisions 2–3; plan §Architecture A/B, §Technical ruling 2)
- **Description:** `RetryPolicy` (`app/Services/RetryPolicy.php`) gains
  `configuredAttemptLimitFor(Proxy): ?int` and `configuredStrategyFor(Proxy): ?RetryBackoffStrategy` —
  each establishes `mode === ProxyMode::Enhanced` before reading its column, returning `null`
  otherwise. `attemptLimitFor()`/`strategyFor()` are rewritten to route through them:
  `configuredAttemptLimitFor($proxy) ?? positiveConfigInt('default_attempt_limit')`, then the
  existing `[1, max_attempt_limit]` clamp (unchanged, applied **after** the gate);
  `configuredStrategyFor($proxy) ?? RetryBackoffStrategy::Exponential`. `delayBefore()` and
  `worstCaseSpan()` are untouched — `delayBefore()` inherits the gate through `strategyFor()` with no
  separate branch. The class docblock is rewritten to name the new invariant (plan §Services &
  Actions). `ProxyController::store()`/`update()` (`app/Http/Controllers/ProxyController.php:59-103,
  143-203`) stop writing the two retry columns at all when the submitted mode is Simple — the update
  array omits both keys, adding them only when `$data['mode'] === ProxyMode::Enhanced->value`; a save
  whose mode is Enhanced writes exactly what was submitted (a value sets it, `null` clears to the
  existing PRD-06 AC2 unconfigured meaning). `prohibited_if:mode,simple` is **KEPT verbatim** on both
  fields in `StoreProxyRequest`/`UpdateProxyRequest` — review-06 Minor 8(a)'s proposed relaxation is
  declined (plan §Technical ruling 2); only the two Form Requests' docblocks change, from the retired
  T30-era "how a switch clears stored values" framing to the ADR-018 Decision 3 purpose ("a
  Simple-mode save can never change a dormant policy").
- **Dependencies:** none
- **Files:** `app/Services/RetryPolicy.php`, `app/Http/Controllers/ProxyController.php`,
  `app/Http/Requests/StoreProxyRequest.php` (docblock only), `app/Http/Requests/UpdateProxyRequest.php`
  (docblock only)
- **Acceptance Criteria:**
  - `configuredAttemptLimitFor()`/`configuredStrategyFor()` return `null` for a Simple proxy whatever
    the columns hold, and the column value for an Enhanced one.
  - A Simple proxy with `retry_attempt_limit = 8`/`retry_backoff_strategy = fixed` resolves **5 /
    exponential** via `attemptLimitFor()`/`strategyFor()`, behaving identically to a Simple proxy that
    never had a policy (one test asserts this over both proxies, so "identical" is the assertion, not
    an inference — AC14(a), AC21).
  - An Enhanced proxy with the same columns resolves **8 / fixed**; an Enhanced proxy with NULL
    columns resolves 5 / exponential (the pre-existing unconfigured meaning survives).
  - The clamp still applies after the gate: an Enhanced proxy with a column above
    `max_attempt_limit` clamps to the cap; a Simple proxy with the same column still resolves the
    default.
  - The existing config-sanity `RuntimeException`s (`positiveConfigInt()`) still fire for every
    guarded key, unchanged.
  - `worstCaseSpan()` is byte-for-byte unchanged and its existing AC18 guard-test assertion still
    passes.
  - A `mode = simple` store/update never writes either retry column — not a value, not NULL (a proxy
    already holding persisted values keeps them verbatim after a Simple-mode save).
  - A `mode = enhanced` save writes exactly what was submitted; NULL fields still clear to the
    unconfigured sentinel.
  - `prohibited_if:mode,simple` still rejects a Simple-mode submission carrying either retry field
    (store and update) — unchanged validation behaviour.
- **Testing:** extend `tests/Unit/Services/RetryPolicyTest.php` with: the headline
  Simple-with-columns-vs-Simple-never-configured identical-resolution case; the Enhanced-resolves-
  columns case; the NULL-Enhanced-resolves-default case; the post-gate clamp case in both modes;
  `configuredAttemptLimitFor()`/`configuredStrategyFor()` null/value cases; confirmation the existing
  config-sanity and `worstCaseSpan()` tests are unaffected. No feature-level HTTP test in this task —
  T2 covers the controller/endpoint persistence behaviour end to end.
- **Completion notes:** _pending_

## T2 — Persistence acceptance tests: preservation, restoration, and the retained boundary (AC14, AC14(b)(iv); review-06 Minor 8(c); plan §Test strategy "Persistence")
- **Description:** Prove T1's persistence rule end to end through the real `store()`/`update()`
  endpoints. Rename and invert the #6-era test whose name now asserts the wrong outcome, and add the
  round-trip, tuning, and `prohibited_if` cases the plan's Test strategy names.
- **Dependencies:** T1
- **Files:** `tests/Feature/Proxies/RetryPolicyFormAcceptanceTest.php`
- **Acceptance Criteria:**
  - `test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null` (line 138) is renamed
    to `test_switching_enhanced_to_simple_on_update_preserves_stored_values` and rewritten to assert
    the fresh proxy still holds its prior values (4 / fixed) after the save — not NULL.
  - A Simple proxy already holding a dormant policy, saved again as Simple with no mode change, still
    holds it (no overwrite, no clear — AC14(b)(iv)).
  - The upgrade round trip, end to end (AC14 lead sentence): a Simple proxy holding 4 / fixed → a save
    with `mode = enhanced` and no retry fields resubmitted (the values reach the save from the Edit
    payload, per Amendment A) persists 4 / fixed with nothing re-entered.
  - An upgrade save that tunes in the same save (`mode = enhanced`, `retry_attempt_limit = 9`)
    persists 9 (AC14(b)(iii)).
  - An Enhanced save with NULL retry fields still clears to the unconfigured sentinel (PRD-06 AC2's
    meaning is not collateral damage).
  - `prohibited_if` still bites: a `mode = simple` submission carrying `retry_attempt_limit` returns
    422 on that field, both store and update — pinning plan §Technical ruling 2's decision **and**
    documenting ruling 3's client-side coupling as intentional (plan Risk 4).
  - The existing `test_mode_gates_only_the_retry_policy_pair_nothing_else` (AC20 parity — unrelated
    fields still settable under `mode = simple`) stays green, unmodified.
- **Testing:** the cases above, in the existing acceptance test file named above.
- **Completion notes:** _pending_

## T3 — Consumers inherit the gate without branching — acceptance tests (AC6(b), AC14(a); plan §Architecture A "why `DeliverToDestination` and `DeliveryResource` inherit... without branching")
- **Description:** Prove `DeliverToDestination::settleDelivery()` (`app/Actions/DeliverToDestination.php:198,216`)
  and `DeliveryResource.attempt_limit` (`app/Http/Resources/DeliveryResource.php:40`) resolve through
  T1's gate with no code of their own — the property the plan states neither consumer needs a mode
  branch to get right. Includes the mid-flight downgrade/upgrade cases the plan's Test strategy names.
- **Dependencies:** T1
- **Files:** `tests/Feature/Retry/ModeGatedRetryInheritanceAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - A Simple proxy holding `retry_attempt_limit = 2` whose delivery fails schedules attempt 2 (not
    terminalized) and terminalizes at the system default 5 — proving `DeliverToDestination` resolved
    through the gate, not the raw column. This is the test that would have caught the defect ADR-018
    exists to prevent.
  - The same proxy's `DeliveryResource.attempt_limit` renders 5, not 2 — the resource inherits the
    gate with no code of its own.
  - Mid-flight downgrade: an Enhanced proxy with limit 8, three attempts made, downgraded to Simple
    mid-schedule — the next failure terminalizes immediately (`>=`, not `==`), emits
    `DeliveryExhausted` exactly once, and releases a FIFO line if held.
  - Mid-flight upgrade: a Simple proxy at attempt 4 upgraded to Enhanced with limit 8 continues
    retrying to 8; the `worstCaseSpan()` clamp bound is unaffected regardless of how many times a
    proxy switches.
- **Testing:** `Http::fake()`, `Queue::fake()`, `travel()` — mirroring the patterns in
  `docs/tasks/retry-replay-tasks.md` T38–T40.
- **Completion notes:** _pending_

## T4 — Switch-safety and downgrade-lifecycle acceptance tests, and the permission gate (AC1, AC2, AC3, AC5, AC9, AC10, AC11, AC13, AC17; plan §Architecture E "no code, and why")
- **Description:** Plan §Architecture E states these guarantees hold via existing, mode-independent
  mechanisms (exactly-once settlement, the FIFO claim/lease/reaper machinery, the ADR-012 retention
  holds, live mode-reads with no snapshot) and require no new production code. This task is the proof,
  run against the mode-toggle surface #7 actually ships, not an implementation.
- **Dependencies:** T1
- **Files:** `tests/Feature/Proxies/ModeSwitchSafetyAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - A proxy switched Simple → Enhanced composes `CaptureDispatchedStep` for its next event and not
    for one already dispatched; Enhanced → Simple stops producing new dispatched-output rows and
    deletes none (AC6(a), AC9, AC13).
  - A queue redelivery straddling a switch produces exactly one `dispatched_payloads` row and no
    error (the `updateOrCreate` idempotency).
  - A downgrade with events queued / claimed under FIFO / awaiting a scheduled retry / mid-replay
    loses, errors, duplicates, and strands **nothing**; the FIFO line advances (AC10, AC17).
  - An expired event's dispatched output produced under Enhanced is erased by the normal expiry pass
    **after** the proxy was downgraded — retention expiry is still the only eraser and the downgrade
    added none (AC13, AC20).
  - A replay on a now-Simple proxy neither writes nor deletes the event's existing dispatched-output
    row (AC13, AC11).
  - Switching mode does not recreate the proxy, its destinations, its ingest URL, or its history
    (AC1); it is reachable at create and at edit through the same single attribute, with no separate
    workflow (AC2); `simple` remains the default for a proxy created without an explicit choice (AC3).
  - A team member without `proxy:update` cannot change a proxy's mode (403/404 per the existing scope
    rules); a member who can update, can; **no new permission exists** — asserted by pinning the
    `TeamPermission` case list unchanged (AC5).
- **Testing:** the cases above; `Http::fake()`, `Queue::fake()`, `travel()`.
- **Completion notes:** _pending_

---

## M2 — Read-surface suppression and the Edit carve-out

## T5 — Read-surface suppression + the Edit carve-out (AC12, AC14(b), AC16; Amendment A; ADR-018 Decision 4; plan §Architecture C)
- **Description:** `ProxyResource`'s two retry keys (`app/Http/Resources/ProxyResource.php:49-50`)
  stop reading the raw columns and resolve instead through
  `RetryPolicy::configuredAttemptLimitFor()`/`configuredStrategyFor()` (T1) — an Enhanced proxy emits
  its column values; a Simple proxy emits `null` for both, **always**, regardless of any dormant
  value. New `App\Http\Resources\ProxyFormResource` (extends `ProxyResource`) overrides both keys with
  the raw persisted columns irrespective of mode — the single Amendment-A carve-out, with a docblock
  naming Amendment A, AC14(b)'s four binding conditions, and the rule that no other caller may use it.
  `ProxyController::edit()` (`app/Http/Controllers/ProxyController.php:127-134`) is the **only**
  caller, returning `ProxyFormResource::make(...)` instead of `ProxyResource::make(...)`;
  `create()`/`index()`/`show()` are untouched.
- **Dependencies:** T1
- **Files:** `app/Http/Resources/ProxyResource.php`, `app/Http/Resources/ProxyFormResource.php` (new),
  `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - A Simple proxy holding a dormant policy (e.g. 8 / fixed) emits `null` for both fields on Index
    **and** Show, identically to a Simple proxy that never had a policy.
  - The same proxy's Edit payload (via `ProxyFormResource`) emits the raw 8 / fixed.
  - An Enhanced proxy with configured values emits those values on Index, Show, **and** Edit (the
    existing `test_proxy_resource_emits_both_fields_on_index_show_and_edit` in
    `RetryPolicyFormAcceptanceTest.php:160` continues to pass unmodified — it already uses an
    Enhanced proxy).
  - No non-Edit response (events list/detail, delivery payloads) carries a proxy retry-column key at
    all.
  - `ProxyFormResource` has exactly one caller (`edit()`) — a second caller is a review finding, not a
    refactor.
  - **Existing-test update required, named explicitly:**
    `tests/Feature/Proxies/ProxyIndexShowTest.php::test_index_and_show_expose_retry_policy_fields`
    (line 143) seeds a **default-mode (Simple)** proxy with `retry_attempt_limit = 4`/`fixed` and
    currently asserts those raw values are emitted verbatim on Index/Show — that assertion is now
    wrong under AC14(b) and must be rewritten to assert `null`/`null` for that Simple proxy (the
    dormant-suppression case). Its sibling, `test_unconfigured_retry_policy_fields_are_null` (line
    169), already asserts `null`/`null` and needs no change.
- **Testing:** new feature-test cases per the bullets above — extend
  `RetryPolicyFormAcceptanceTest.php` or add a new
  `tests/Feature/Proxies/ProxyRetryFieldPresentationAcceptanceTest.php` (Senior Developer's call
  within these constraints, per the T22/T45 house convention in `docs/tasks/retry-replay-tasks.md`)
  — plus the required `ProxyIndexShowTest.php` rewrite named above.
- **Completion notes:** _pending_

## T6 — Frontend types for the carve-out (Amendment A; plan §Services & Actions "Frontend")
- **Description:** `ProxyDetail`/`ProxyListItem` (`resources/js/types/proxies.ts`) keep their two
  retry fields but gain a corrected docblock — "the per-proxy override in force; `null` whenever the
  system default governs, including a Simple proxy holding a dormant policy." A new `ProxyFormProxy`
  interface carries the raw values with its own docblock naming Amendment A. `Edit.vue`'s local
  `EditProxy`/prop type switches to `ProxyFormProxy`.
- **Dependencies:** T5
- **Files:** `resources/js/types/proxies.ts`, `resources/js/pages/proxies/Edit.vue`
- **Acceptance Criteria:** `pnpm types:check` passes with the new/corrected types; `Edit.vue`'s prop
  type is `ProxyFormProxy`, not `ProxyDetail`/`ProxyListItem`; no runtime behaviour change — this is a
  typing-only task, since `Edit.vue` already receives `ProxyFormResource`'s payload at runtime once
  T5's endpoint change is live (TypeScript types are a compile-time check, not a runtime gate).
- **Testing:** `pnpm types:check`, `pnpm lint:check`, `pnpm format:check`. No behavioural test — this
  task changes type declarations only, not executable logic.
- **Completion notes:** _pending_

---

## M3 — The form surface

## T7 — Mode field: first-class field pattern + corrected help text (AC12, AC15; design-07 Screen 1 baseline; plan §Architecture F)
- **Description:** Bring the Mode `SelectTrigger` (`resources/js/pages/proxies/ProxyForm.vue:187-203`)
  to parity with `processing_mode`/`response_status`: `aria-describedby="mode-help mode-error"` and
  `:aria-invalid="form.errors.mode ? 'true' : undefined"` on the trigger; the help paragraph gains
  `id="mode-help"`; the error is wrapped in `span#mode-error`. Replace the help copy with design-07's
  replacement text verbatim, naming both AC6 capabilities in present tense plus the mode-independent
  guarantees (automatic retry, payload capture, retention, and replay apply to every proxy regardless
  of Mode), no roadmap numbers, no mapping implication. The `Select` itself is untouched in shape —
  still exactly two items (AC23).
- **Dependencies:** none
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:** the Mode field's DOM wiring matches the a11y pattern used by
  `processing_mode`; the help text reads design-07's replacement copy (or an equivalent within its
  binding constraint) and no longer names only retry configurability; the `Select` still has exactly
  two `SelectItem`s.
- **Testing:** `pnpm lint:check`, `pnpm types:check`, `pnpm format:check`, then **`pnpm run build`**
  (required before any live check). **Manual verification** (no frontend test harness): Flow A — open
  Create, read the corrected help text before choosing a mode; inspect the built page's DOM for
  `aria-describedby`/`aria-invalid` parity with the Processing field. Document the steps and outcome
  in the completion notes.
- **Completion notes:** _pending_

## T8 — Downgrade disclosure (AC13, AC14(c); design-07 Screen 1 "Downgrade disclosure"; plan §Architecture F)
- **Description:** Conditional `Alert`/`AlertTitle`/`AlertDescription` (`Info` icon), wrapped
  `aria-live="polite"`, rendered iff `props.initial.mode === 'enhanced' && form.mode === 'simple'`
  (`isDowngrading` computed). Sits between the Mode field and the form's submit action; never
  dismissible, never collapsed behind a "learn more". Three bullets, verbatim per design-07:
  enhanced-only steps stop for future events; stored dispatched outputs are kept and expire on their
  normal 30-day schedule; saved retry configuration is kept but goes dormant, reactivating with its
  previous values on a return to Enhanced. The third bullet's parenthetical defaults are interpolated
  from the same source the Show card's display helper uses
  (`proxyRetryAttemptLimitDisplay(null)`/`proxyRetryBackoffStrategyLabel(null)` or the underlying
  const) — never a second hand-written literal.
- **Dependencies:** T7
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:** never true at Create (`Create.vue` always passes `initial.mode: 'simple'`);
  never true for an already-Simple proxy that stays Simple; true regardless of whether a retry policy
  exists; disappears immediately if the member switches back to Enhanced before submitting, with
  nothing sent to the server; **not a gate** — no confirm click, no checkbox, no modal.
- **Testing:** `pnpm` triad, then `pnpm run build`. **Manual verification**: Flow C (downgrade — the
  disclosure appears with all three points and does not block Save) and Flow B (upgrade — the
  disclosure never appears). Document outcomes in the completion notes.
- **Completion notes:** _pending_

## T9 — Ruling-3 submit normalisation, closing plan Risk 4 (Amendment A; plan §Technical ruling 3)
- **Description:** `ProxyForm.vue`'s existing `form.transform()` sends `null` for both retry fields
  whenever `data.mode === 'simple'`, regardless of the fields' in-memory state. Required because the
  Edit form's initial state is now seeded from the persisted values whatever the proxy's mode
  (T5/T6), while `design-06`'s `watch(isEnhanced, …)` clears fields only on an in-session change,
  never on mount. Without this, opening Edit on a Simple proxy holding a dormant policy and saving
  without touching Mode would submit the dormant values alongside `mode: simple` and be 422'd by
  `prohibited_if` on a field the form does not render, with no way to fix it. This is a
  normalisation, not a gate — the server never trusts it; T1's omission rule is authoritative
  regardless of what a Simple submission carries.
- **Dependencies:** T8
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:** a Simple-mode submission always carries `null` for both retry fields in the
  request payload, whatever the form's field state; an Enhanced-mode submission is unaffected.
- **Testing:** `pnpm` triad, then `pnpm run build`. **Manual verification (plan Risk 4, the
  dormant-carrying-Simple-proxy save)**: open Edit on a Simple proxy holding a dormant retry policy
  (e.g. seeded via T2's fixtures or a prior Enhanced save), save without touching Mode — the save
  succeeds (no 422) and the persisted values are unchanged. Document the steps and outcome in the
  completion notes. (T2's `prohibited_if`-still-bites test already proves the server-side rule fires
  when the client does *not* normalise — this task's manual check proves the client mitigation
  actually prevents hitting that path from the real form.)
- **Completion notes:** _pending_

---

## M4 — The Show surface

## T10 — Mode-summary caption + the Retry-policy-card comment (AC16; Amendment B; design-07 Screen 2(a); plan §Architecture C/F)
- **Description:** One muted `<p>` directly below the existing header row (name + Mode badge +
  Processing badge, `resources/js/pages/proxies/Show.vue:141-148`), present-tense Simple/Enhanced
  copy per design-07 verbatim, referencing — not restating — the Retry policy card. **No new card**
  (Amendment B). Also rewrite the stale rationale comment on `retryAttemptsDisplay`/
  `retryBackoffDisplay` (`Show.vue:101-105`, "a simple-mode proxy's columns are always NULL" — retired
  by ADR-018 Decision 3) to cite the server-side gate (T5); the two computeds themselves are
  **unchanged in code**.
- **Dependencies:** T5
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:** the caption renders under the header for both modes with design-07's copy;
  no new `Card` is added; the Retry policy card (`Show.vue:269-`) reads identically — `5 (default)` /
  `Exponential (default)` plus the simple-mode note — for a Simple proxy whether or not it holds a
  dormant policy; the rewritten comment no longer claims the columns are always NULL for a Simple
  proxy.
- **Testing:** `pnpm` triad, then `pnpm run build`. **Manual verification**: Flow E — Simple (never
  configured) and Simple (holding a dormant policy) render identical copy and identical card values;
  Enhanced unconfigured/configured render the Enhanced copy and correct values. Document outcomes in
  the completion notes.
- **Completion notes:** _pending_

---

## M5 — Riders

## T11 — Rider 1: guarded `RetryPolicy::sweepGraceSeconds()` (review-06 Minor 9; plan §Riders 1, §Technical ruling 7)
- **Description:** `app/Actions/SweepDueRetries.php:33` reads `config('retry.sweep_grace_seconds')`
  directly — the only `retry.*` key read outside `RetryPolicy` and the only one of the seven with no
  `positiveConfigInt()` guard, so a blank env silently coerces to `0`, making the sweep cutoff `now()`
  and re-dispatching `RetryDelivery` for every `retrying` delivery on every tick. Add
  `RetryPolicy::sweepGraceSeconds(): int` returning `positiveConfigInt('sweep_grace_seconds')`;
  `SweepDueRetries` calls it instead of reading config directly. A previously-legal explicit `0` now
  throws (deliberate, consistent with the six sibling keys — plan §Technical ruling 7); no environment
  sets the key today.
- **Dependencies:** T1
- **Files:** `app/Services/RetryPolicy.php`, `app/Actions/SweepDueRetries.php`
- **Acceptance Criteria:** a blank or explicit-zero `RETRY_SWEEP_GRACE_SECONDS` raises the named
  `RuntimeException` instead of sweeping every `retrying` delivery; the existing overdue /
  not-yet-due / terminal sweep cases keep passing through the new accessor; `RetryPolicy` is now the
  only reader of all seven `retry.*` keys.
- **Testing:** extend `tests/Unit/Services/RetryPolicyTest.php` with the blank/zero-raises case, and
  the existing `SweepDueRetries` test suite with the accessor-swap regression (unaffected cases keep
  passing).
- **Completion notes:** _pending_

## T12 — Rider 2: `DeliveryResource.created_at` — field, consumer, and the pin, together (review-06 Minor 5, ONE task — binding; plan §Riders 2)
- **Description:** `DeliveryResource` (`app/Http/Resources/DeliveryResource.php`) gains a real
  `created_at` (the column already exists on `deliveries` — serialization only, no data-model
  change). The events-detail replay-group label and ordering
  (`resources/js/pages/proxies/events/Show.vue`, currently deriving the label from the earliest
  attempt's `started_at` and the order from the group's highest `Delivery.id`) switch to use the new
  `created_at` directly. The pinning assertion `->missing('event.deliveries.0.created_at')`
  (`tests/Feature/ProxyEvents/ReadSurfaceRevealAcceptanceTest.php:95`) is inverted to assert presence.
  Landing the field alone would leave both the label/ordering defect (a FIFO replay queued behind a
  held line with zero attempts degrades to a bare "Replay" with no time — exactly the scenario the
  feature exists to make visible) and a dead field — the plan requires all three in one task.
- **Dependencies:** none
- **Files:** `app/Http/Resources/DeliveryResource.php`, `resources/js/types/proxies.ts`
  (`Delivery.created_at`), `resources/js/pages/proxies/events/Show.vue`,
  `tests/Feature/ProxyEvents/ReadSurfaceRevealAcceptanceTest.php`
- **Acceptance Criteria:** `DeliveryResource` emits `created_at`; the events-detail replay-group label
  reads it directly (no more earliest-attempt derivation) and group ordering (newest-first) sorts by
  it (no more highest-`Delivery.id` derivation); a FIFO replay queued with zero attempts still shows a
  real time label; the inverted assertion at `ReadSurfaceRevealAcceptanceTest.php:95` passes.
- **Testing:** extend `tests/Unit/Http/Resources/DeliveryResourceTest.php` with the new field, and
  invert the named assertion in `ReadSurfaceRevealAcceptanceTest.php`. **Manual verification** (no
  frontend test harness): open an event detail page with a FIFO replay queued behind a held line
  (zero attempts yet) — the replay group shows a real time label, not a bare "Replay"; replay groups
  sort newest-first by `created_at`. `pnpm run build` required before this check.
- **Completion notes:** _pending_

---

## M6 — Quality sweep

## T13 — Quality sweep and docs cross-check (plan §Milestones M6)
- **Description:** Final gate before handoff to Review. Full backend suite (parallel), Pint, PHPStan
  L7, frontend triad, and a fresh `pnpm run build` (a stale checked-in bundle proved nothing at
  review-06 M-3 — rebuild before any live check performed under this task). Docs cross-check: grep
  for and correct any remaining docblock citing "a simple-mode proxy's columns are always NULL" or
  ADR-015 Decision 3's persistence invariant, so each now points at ADR-018. Cheap positive proof of
  AC4: the #7 diff contains no migration file.
- **Dependencies:** T1, T2, T3, T4, T5, T6, T7, T8, T9, T10, T11, T12
- **Files:** none new — verification only; any stale docblock found is corrected in place.
- **Acceptance Criteria:** `./vendor/bin/sail test --parallel` fully green; `composer lint` clean;
  `composer types:check` (PHPStan L7) 0 errors; `pnpm lint:check`/`pnpm types:check`/
  `pnpm format:check` clean; `pnpm run build` succeeds against a freshly rebuilt bundle; no docblock
  in the diff still cites the retired NULL-columns rationale; the #7 diff contains no new migration
  file.
- **Testing:** the full-suite run itself, plus the grep-based docs audit.
- **Completion notes:** _pending_

---

## Handoff
- **Inputs:** `docs/plans/plan-07-enhanced-mode-toggle.md` (Approved, PE self-certified in full,
  2026-08-25); `docs/product/prd-07-enhanced-mode-toggle.md` (Approved, Owner, 2026-08-21, incl.
  Amendments A and B); `docs/design/design-07-enhanced-mode-toggle.md` (Approved, Product Manager,
  2026-08-25, corrections landed); `docs/architecture/adr-018-mode-gated-resolution-of-enhanced-configuration.md`
  (Accepted, Project Owner, 2026-08-25); the annotated ADR-015; `docs/reviews/review-06-retry-replay.md`
  (Minors 5, 8, 9 and Ruling 2 — the three obligations #6 routed to #7); `docs/tasks/retry-replay-tasks.md`
  (house format/granularity precedent); `docs/standards/planning.md`; the current codebase
  (`RetryPolicy`, `ProxyController`, `ProxyResource`, `DeliveryResource`, `Store/UpdateProxyRequest`,
  `PipelineFactory`, `DeliverToDestination`, `SweepDueRetries`, `Proxy`, `config/retry.php`,
  `ProxyForm.vue`, `Create.vue`, `Edit.vue`, `proxies/Show.vue`, `proxies/events/Show.vue`,
  `types/proxies.ts`, `data/proxyRetryBackoffStrategies.ts`, `components/ui/alert/*`, and the
  retry/proxy test suites — read directly to confirm exact file/line references and existing test
  names/fixtures cited above).
- **Outputs:** this task plan (`docs/tasks/enhanced-mode-toggle-tasks.md`).
- **Dependencies:** none new; within stack (Eloquent, Inertia, existing frontend primitives — no new
  package, icon, or `ui/*` component).
- **Outstanding Questions:** none. Every task above traces to an explicit plan section (Architecture
  A–F, Services & Actions, Validation, a named Technical ruling, or the Riders/Test-strategy
  sections) or a named ADR-018 decision; no design ambiguity was found requiring a question doc to
  the Principal Engineer or Product Manager. One judgment call is deliberately left to the Senior
  Developer within stated constraints, per house convention (not prescribed in a task's Description):
  T5's exact new-test-file location (extend `RetryPolicyFormAcceptanceTest.php` vs. a new dedicated
  file).
- **Next Agent:** Senior Developer — implement T1–T13 in order, one feature-branch commit per
  completed task (or per logical part of a large task), leaving `composer lint`, `composer
  types:check`, and `./vendor/bin/sail test` (plus `pnpm lint:check`/`types:check`/`format:check` and,
  for frontend tasks, a fresh `pnpm run build` before any manual check) green at every commit, per
  `docs/standards/planning.md`. Feature branch: `feat/item-07-enhanced-mode-toggle`.

### Certification (Task Planner, 2026-08-25)
I have verified plan-07 is Approved (Principal Engineer self-certified in full) with no outstanding
Owner-approval flags — the one flag #7 ever carried, ADR-018, was Accepted by the Project Owner
before the plan was written — so no gate blocks Task Planning. I have read the plan in full, PRD-07
incl. Amendments A and B, the approved design spec (with its approval note governing), ADR-018 and
the annotated ADR-015, review-06's Minors 5/8/9 and Ruling 2, and the affected code on
`feat/item-07-enhanced-mode-toggle` at `9cdc3a4` (`RetryPolicy`, `ProxyController`, `ProxyResource`,
`DeliveryResource`, the Form Requests, `ProxyForm.vue`, `Show.vue`, `events/Show.vue`,
`ProxyIndexShowTest.php`, `RetryPolicyFormAcceptanceTest.php`, `ReadSurfaceRevealAcceptanceTest.php`,
`ProxyFactory.php`) to confirm every file path, line reference, and existing-test name cited above is
accurate against the real tree, not assumed. Every task traces to an explicit plan section or a named
ADR-018 decision; no task invents scope beyond what plan-07 authorizes.

All four binding handoff constraints are honoured:
1. **Review-06 Minor 8(a) and 8(b) land in one task, T1** — the resolver gate and the controller
   omission are the same task, not sequenced across two.
2. **Neither Senior-Developer item appears anywhere in this list** — the `DeliverToDestination.php:197`
   soft-delete `TypeError` and the missing `trustProxies()` config are named only in the front-matter
   scope-discipline block, as explicitly out of scope, routed elsewhere.
3. **Every task touching design-07's Flows A–E or the dormant-carrying-Simple-proxy save (T7–T10,
   T12) carries an explicit manual-verification section with concrete steps, gated behind a required
   `pnpm run build`** — there is no frontend test harness in this project.
4. **M5 carries exactly the two riders the plan schedules there, in the order and shape the plan
   specifies** — T11 (`sweepGraceSeconds()`) and T12 (`DeliveryResource.created_at`, the consumer
   switch, and the inverted pin, together as one task).

Tasks are ordered so no task depends on a later one. I record `Approved by: Task Planner` per the
delegated task-plan gate in `CLAUDE.md` — no Owner approval is required at this stage; the Reviewer
catches drift against the plan/PRD-07/ADR-018 at review time.
