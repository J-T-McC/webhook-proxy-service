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
- **Completion notes:** Implemented both halves of review-06 Minor 8 in one commit. `RetryPolicy`
  gained `configuredAttemptLimitFor()`/`configuredStrategyFor()` (the ADR-018 Decision 2 mode gate,
  the only place either retry column is read to decide what governs a proxy); `attemptLimitFor()`/
  `strategyFor()` now route through them, with the existing `[1, max_attempt_limit]` clamp and
  `positiveConfigInt()` guards unchanged and applied after the gate. `delayBefore()`/
  `worstCaseSpan()` are byte-for-byte unchanged. `ProxyController::store()`/`update()` now omit
  both retry keys from the write array entirely unless the submitted mode is Enhanced
  (`$data['mode'] === ProxyMode::Enhanced->value`) — a Simple-mode save never writes either column,
  not a value, not NULL; an Enhanced-mode save writes exactly what was submitted, `?? null` still
  clearing to the unconfigured sentinel. `prohibited_if:mode,simple` is unchanged in both Form
  Requests — only their docblocks were rewritten, from the retired T30-era "clears on switch"
  framing to ADR-018 Decision 3's purpose (a Simple-mode save can never change a dormant policy it
  cannot see). `PipelineFactory` was not touched.

  Added 9 new cases to `tests/Unit/Services/RetryPolicyTest.php`: the two `configured*For()`
  null/value cases; the headline Simple-with-dormant-policy-vs-Simple-never-configured
  identical-resolution case (AC14(a)/AC21, asserted over both proxies); Enhanced-resolves-columns;
  NULL-Enhanced-resolves-default; the post-gate clamp in both modes.

  **Judgment call — collateral fixes required to keep every commit green (plan/reality gap the
  Task Planner's per-file audit did not catch):** the ADR-018 mode gate is a genuine behaviour
  change to `attemptLimitFor()`/`strategyFor()`, and four pre-existing tests outside T1's own Files
  list constructed proxies with a raw retry column set but no explicit `mode` (defaulting to the
  factory's `Simple`), asserting the column value was honored — now correctly gated to `null` for
  those proxies. Left broken, these would have made this commit fail the standing "every commit
  leaves `sail test` green" invariant. Fixed by adding `'mode' => ProxyMode::Enhanced` (or the
  update-array equivalent) to each fixture, which is the correct fix under ADR-018, not a
  workaround: `tests/Unit/Actions/DeliveryStatusTransitionTest.php` (the raw
  `Proxy::query()->update(['retry_attempt_limit' => ...])` fixture); `tests/Unit/Http/Resources/DeliveryResourceTest.php`
  (`test_attempt_limit_reflects_the_proxys_effective_policy`,
  `test_attempt_limit_still_resolves_when_the_proxy_has_been_soft_deleted`);
  `tests/Feature/Proxies/ProxyUpdateTest.php` (`test_switching_from_enhanced_to_simple_clears_the_retry_policy_to_null`,
  renamed to `test_switching_from_enhanced_to_simple_preserves_the_retry_policy` and inverted — a
  second, previously-unnamed instance of the same #6-era assumption review-06 Minor 8(c) named once
  in `RetryPolicyFormAcceptanceTest.php`); and
  `tests/Feature/Proxies/RetryPolicyFormAcceptanceTest.php`'s own named test
  (`test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null`), renamed to
  `test_switching_enhanced_to_simple_on_update_preserves_stored_values` per review-06 Minor 8(c) and
  T2's own Acceptance Criteria — landed here (not deferred to T2) purely to keep this commit's tree
  green; T2 adds the rest of its named cases on top without re-touching this one. No production
  defect found in any of the four — all were test fixtures assuming the retired invariant.

  Verified: `./vendor/bin/sail test --filter RetryPolicyTest` (40 passed, 73 assertions); full suite
  `./vendor/bin/sail test --parallel` (730 passed, 2660 assertions — up from the pre-existing
  baseline only by T1's own 9 new cases, net of the 4 fixture fixes above); `composer lint` (Pint,
  clean); `composer types:check` (PHPStan level 7, 0 errors).

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
- **Completion notes:** The rename/inversion of
  `test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null` →
  `test_switching_enhanced_to_simple_on_update_preserves_stored_values` was already landed under
  T1's commit (necessary there to keep that commit's tree green against its own production change —
  see T1's completion notes). This task adds the remaining new cases to
  `tests/Feature/Proxies/RetryPolicyFormAcceptanceTest.php`:
  `test_re_saving_an_already_simple_proxy_holding_a_dormant_policy_leaves_it_untouched` (AC14(b)(iv)
  — a Simple proxy already holding 4/fixed, saved again as Simple with only its name changed, keeps
  4/fixed exactly, no overwrite, no clear); `test_upgrading_resubmits_the_preserved_values_and_they_persist_unedited`
  (the AC14 lead-sentence round trip — Simple 4/fixed → `mode = enhanced` resubmitting the SAME
  4/fixed, as the Edit payload would per Amendment A — persists 4/fixed with nothing re-entered, at
  the controller/persistence layer; T9 covers the client-side normalisation that makes this the
  real submitted shape); `test_upgrading_while_tuning_in_the_same_save_persists_the_tuned_value`
  (AC14(b)(iii) — Simple 4/fixed → `mode = enhanced` with `retry_attempt_limit = 9` in the same save
  persists 9, not 4); `test_an_enhanced_save_with_null_retry_fields_still_clears_to_the_unconfigured_sentinel`
  (PRD-06 AC2's unconfigured meaning is not collateral damage under ADR-018). The two `prohibited_if`
  cases (store and update) and `test_mode_gates_only_the_retry_policy_pair_nothing_else` already
  existed, pass unmodified, and needed no change — pinning plan §Technical ruling 2 and AC20 parity
  as required.

  Verified: `./vendor/bin/sail test --filter RetryPolicyFormAcceptanceTest` (12 passed, 78
  assertions); full suite `./vendor/bin/sail test --parallel` (734 passed, 2673 assertions);
  `composer lint` (Pint, clean); `composer types:check` (PHPStan level 7, 0 errors).

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
- **Completion notes:** Added `tests/Feature/Retry/ModeGatedRetryInheritanceAcceptanceTest.php` (3
  tests) proving `DeliverToDestination::settleDelivery()` and `DeliveryResource.attempt_limit`
  inherit T1's gate with no code of their own — no production defect found; `PipelineFactory` not
  touched. Covered: a Simple proxy holding a dormant `retry_attempt_limit = 2` schedules attempt 2
  (not terminalized at the dormant column) and terminalizes only at the system default (5),
  proving `DeliverToDestination` resolves through the gate; the SAME proxy's
  `DeliveryResource.attempt_limit` renders 5, not 2; a mid-flight downgrade (Enhanced limit 8,
  three real attempts made, FIFO line held `awaiting_retry`) terminalizes immediately on the next
  failure once Simple (using a lowered `default_attempt_limit` config value in-test to keep the
  case to 4 real attempts rather than 8 — the `>=` mechanism under test is independent of the
  specific numbers), emitting `DeliveryExhausted` exactly once and releasing the FIFO line
  (`Settled`, advancer nudged); a mid-flight upgrade (Simple at attempt 1 under a lowered default
  of 2, upgraded to Enhanced limit 4 before attempt 2) continues retrying past the old boundary to
  the new configured limit, terminalizing exactly once at attempt 4; `RetryPolicy::worstCaseSpan()`
  (proxy-free by construction) is asserted identical before and after several further mode/limit
  switches.

  Verified: `./vendor/bin/sail test --filter ModeGatedRetryInheritanceAcceptanceTest` (3 passed, 24
  assertions); full suite `./vendor/bin/sail test --parallel` (737 passed, 2697 assertions);
  `composer lint` (Pint, clean); `composer types:check` (PHPStan level 7, 0 errors).

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
- **Completion notes:** Added `tests/Feature/Proxies/ModeSwitchSafetyAcceptanceTest.php` (11 tests)
  proving plan §Architecture E's guarantees hold via existing, mode-independent machinery — no
  production code needed anywhere in this task; `PipelineFactory` not touched. Covered:
  Simple→Enhanced composes `CaptureDispatchedStep` for the next event only, never retroactively for
  one already dispatched; Enhanced→Simple stops new output and deletes none of the existing row; a
  redelivery of the SAME `ingest_id` straddling a switch in either direction never duplicates a row
  (`updateOrCreate`'s UNIQUE `webhook_event_id` key) and never errors — a re-run under a mode that no
  longer composes the step is a structural no-op; a downgrade with one FIFO line held
  `awaiting_retry` and a second event still pending, where the held retry then SUCCEEDS (the
  success-branch complement to T3's terminal-failure case) — the line settles and advances to the
  still-pending sibling with nothing lost, duplicated, or stranded; an event's dispatched output
  captured under Enhanced is still erased by the normal `PurgeExpiredPayloads` pass after a downgrade
  (retention expiry remains the only eraser, AC20); a replay on a now-Simple proxy neither writes nor
  deletes the event's existing dispatched-output row (byte-identical `body` before/after); switching
  mode mutates only the `mode` column — proxy id, `created_at`, destination id, and `ingestUrl()` are
  all unchanged across a real `update` request; no separate mode-change route exists
  (`proxies.mode`/`toggle-mode`/`switch-mode` all absent), the same `store`/`update` endpoints carry
  it (AC2); `simple` is the actual DATABASE column default (a bare `new Proxy(...)->save()` with no
  `mode` key set, not the factory, which always sets it explicitly) for a proxy created without an
  explicit choice (AC3); a team member without `UpdateProxy`/ownership is 403'd attempting to change
  mode and the proxy's mode is verified unchanged, while the creator (who holds it) can; and the
  `TeamPermission` case list is pinned verbatim (14 cases, unchanged) — no new permission exists
  (AC5).

  Verified: `./vendor/bin/sail test --filter ModeSwitchSafetyAcceptanceTest` (11 passed, 45
  assertions); full suite `./vendor/bin/sail test --parallel` (748 passed, 2742 assertions);
  `composer lint` (Pint, clean); `composer types:check` (PHPStan level 7, 0 errors).

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
- **Completion notes:** `ProxyResource::toArray()`'s two retry keys now resolve through
  `app(RetryPolicy::class)->configuredAttemptLimitFor()`/`configuredStrategyFor()` (T1's gate)
  instead of the raw `$this->retry_attempt_limit`/`retry_backoff_strategy` columns — an Enhanced
  proxy still emits its column values, a Simple proxy emits `null` for both, always, regardless of
  any dormant value. New `App\Http\Resources\ProxyFormResource extends ProxyResource`, with a
  docblock naming Amendment A, AC14(b)'s four binding conditions, and the single-caller rule,
  overrides both keys back to the raw persisted columns (`$this->retry_attempt_limit`/
  `retry_backoff_strategy` directly) irrespective of mode. `ProxyController::edit()` now returns
  `ProxyFormResource::make(...)` — its only change; `create()`/`index()`/`show()` are untouched.
  Confirmed by grep that `ProxyEventController`'s events index/detail (which also embed
  `ProxyResource`) automatically inherit the same suppression with no code of their own.

  **Test file choice:** new `tests/Feature/Proxies/ProxyRetryFieldPresentationAcceptanceTest.php`
  (not an extension of `RetryPolicyFormAcceptanceTest.php`). Rationale: that file's existing scope
  is store/update persistence semantics (plus one pre-existing Enhanced-proxy presentation test
  that needed no change); T5's concern — read-surface suppression across Index/Show/Edit/events,
  plus the single-caller invariant on a brand-new resource class — is a distinct axis, and keeping
  it in its own file avoids diluting the persistence file's focus as #7 (and any future read-surface
  work) grows it. Added 5 tests: the headline Simple-with-dormant-vs-Simple-never-configured
  identical-suppression case on Index **and** Show (asserted over both proxies, so "identically" is
  the assertion, not an inference); the same dormant proxy's Edit payload emitting the raw 8/`fixed`;
  the events index/detail pages (`ProxyEventController`) also suppressing the dormant values, proving
  the inherited-gate property AC14(b) requires; a reflection-based static check that
  `ProxyFormResource` has exactly one caller in `app/` (`ProxyController.php`), so a second caller
  anywhere in the codebase fails this test immediately rather than waiting on review; and a sanity
  pin that `ProxyFormResource extends ProxyResource`.

  **Additional stale-assumption test fixed (the one the plan named, no others found):** grepped the
  whole suite for `retry_attempt_limit`/`retry_backoff_strategy` usage (18 files) and specifically for
  any `assertInertia` assertion against a Simple proxy's raw retry values on Index/Show — only
  `ProxyIndexShowTest.php::test_index_and_show_expose_retry_policy_fields` (line 143) matched, exactly
  as the plan predicted; every other hit either asserts DB/model state directly (store/update tests)
  or already uses an Enhanced proxy (`RetryPolicyFormAcceptanceTest`'s own presentation test,
  unmodified). Renamed to
  `test_index_and_show_suppress_a_simple_proxys_dormant_retry_policy_fields` and rewritten to assert
  `null`/`null` for the same Simple proxy holding 4/`fixed`, per AC14(b) — no second unnamed instance
  found this time (unlike T1's `RetryPolicyFormAcceptanceTest`/`ProxyUpdateTest` pair).

  Verified: `./vendor/bin/sail test --filter "ProxyRetryFieldPresentationAcceptanceTest|ProxyIndexShowTest|RetryPolicyFormAcceptanceTest"`
  (27 passed, 271 assertions); full suite `./vendor/bin/sail test --parallel` (753 passed, 2808
  assertions — up from 748/2742 by T5's 5 new tests, the renamed test carrying no count change);
  `composer lint` (Pint, clean); `composer types:check` (PHPStan level 7, 0 errors).

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
- **Completion notes:** `ProxyDetail`/`ProxyListItem`'s `retry_attempt_limit`/`retry_backoff_strategy`
  docblocks (`resources/js/types/proxies.ts`) now read "the per-proxy override in force; null
  whenever the system default governs, including a Simple proxy holding a dormant policy" (AC14(b);
  ADR-018 Decision 4), retiring the stale "null = unconfigured" framing that predates T5's mode gate.
  New `ProxyFormProxy` interface (mirrors `ProxyFormResource`'s shape exactly — `id`, `name`, `mode`,
  `processing_mode`, `response_status`, `response_body`, both retry fields, `destinations`) carries a
  docblock naming Amendment A and pointing at `ProxyDetail`/`ProxyListItem` as the suppressed
  counterpart; each cross-references the other via `{@link}`. `Edit.vue`'s local `EditProxy` interface
  is deleted — its prop type and the `defineOptions` layout callback's `proxy` param both switch to
  the imported `ProxyFormProxy`; the now-unused `DestinationRow`/`ProcessingMode`/`ProxyMode`/
  `ProxyResponseStatus`/`RetryBackoffStrategy` imports are dropped from `Edit.vue` (all now folded
  into `ProxyFormProxy`'s own definition). No other file references `EditProxy`. No runtime code
  changed — the `:initial="{...}"` binding passed to `ProxyForm` is untouched, unpacking the same
  fields from `props.proxy` as before.

  **Gate note (environment, not a defect in this diff):** `pnpm lint:check` (bare `eslint .`) fails
  repo-wide — 39,750 errors, entirely inside `.claude/worktrees/agent-a510df03e0c9cf676/vendor/**`
  and `vite.config.ts`, a separate git worktree another concurrently-running agent has checked out
  under this repo's own directory tree (its own vendor copy, not excluded by this project's
  `eslint.config.js` `ignores: ['vendor', ...]`, which only matches a top-level `vendor` dir, not a
  nested one three levels down). Confirmed via a scoped `npx eslint resources/js/pages/proxies/Edit.vue
  resources/js/types/proxies.ts` — zero errors — that this diff introduces nothing new; the failure
  predates and is unrelated to T6, and editing the shared `eslint.config.js` ignore list is out of
  this task's scope (and would touch a file another agent may rely on mid-session). `pnpm format:check`
  needed one real fix in-scope: `Edit.vue`'s hand-edited `defineOptions`/prop-type block needed
  `./node_modules/.bin/prettier --write resources/js/pages/proxies/Edit.vue` (multi-line object type
  reformatting) — now clean.

  Verified: `pnpm types:check` (clean); `pnpm format:check` (clean, after the one Prettier fix above);
  `pnpm lint:check` scoped to the two touched files via `npx eslint` (clean) — full-repo invocation
  blocked by the unrelated concurrent-worktree pollution described above; full backend suite
  `./vendor/bin/sail test --parallel` re-run as a safety check though this task is frontend-only (753
  passed, 2808 assertions, unchanged from T5).

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
- **Completion notes:** `ProxyForm.vue`'s Mode `SelectTrigger` now carries
  `aria-describedby="mode-help mode-error"` and `:aria-invalid="form.errors.mode ? 'true' : undefined"`,
  matching the `processing_mode` trigger's wiring exactly; the help paragraph gained `id="mode-help"`
  and the error is now wrapped in `span#mode-error` (previously a bare `InputError`). The help copy
  was replaced verbatim with design-07 Screen 1's corrected text: "Enhanced mode stores the payload
  actually dispatched, separately from the payload received, and lets this proxy configure its own
  retry attempts and backoff strategy below. Automatic retry, payload capture, retention, and replay
  apply to every proxy regardless of Mode." — naming both AC6 capabilities in present tense, the
  mode-independent guarantees, no roadmap numbers, no mapping implication. The `Select` itself is
  unchanged in shape — still exactly two `SelectItem`s (`simple`/`enhanced`, AC23).

  **Manual verification (Flow A):** seeded a throwaway user via
  `./vendor/bin/sail tinker --execute '...'` (`User::factory()->create()`, its default personal team),
  logged in via the `playwright` skill (headless Chromium, real `/login` form submission, no session
  faking), and navigated to `/{team-slug}/proxies/create` after a fresh `pnpm run build`. Read the
  Mode help text before selecting anything — rendered verbatim as above. Inspected the built page's
  DOM: Mode's `SelectTrigger` (`#mode`) outerHTML shows `aria-describedby="mode-help mode-error"` and
  no `aria-invalid` attribute (no error present, matching `:undefined`'s omission behaviour); the
  Processing trigger (`#processing_mode`) shows the identical pattern
  (`aria-describedby="processing-help processing-error"`) — confirmed parity attribute-for-attribute.
  Throwaway user retained for T8–T10's manual checks in the same session; will be cleaned up after T10.

  Verified: `pnpm lint:check` (clean, repo-wide — the T6-documented `.claude/worktrees/` pollution is
  gone, confirmed by a full, unscoped `eslint .` run); `pnpm types:check` (clean); `pnpm format:check`
  (clean, after one Prettier auto-fix on the edited block); `pnpm run build` (succeeds,
  `ProxyForm-*.js` chunk emitted); `composer lint` (Pint, clean); `composer types:check` (PHPStan
  level 7, 0 errors); full backend suite `./vendor/bin/sail test --parallel` (759 passed, 2820
  assertions, unchanged — this task is frontend-only).

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
- **Completion notes:** Added the conditional downgrade `Alert` to `ProxyForm.vue`, directly below the
  Mode field and above Processing, wrapped in `<div aria-live="polite">` so its appearance is announced
  to assistive technology. `isDowngrading` computed as
  `props.initial.mode === 'enhanced' && form.mode === 'simple'` exactly per plan/design. Reused the
  `TeamInvitationAlert.vue`/design-06-FIFO-note blue-tinted `Alert` styling precedent verbatim
  (`border-blue-200 bg-blue-50 text-blue-900 dark:...`) with the `Info` icon from `@lucide/vue` — no
  new dependency or icon. `AlertTitle` ("Switching to Simple mode") is used for the first time in the
  app (design-07's stated first application use); `AlertDescription` wraps a three-item `ul`, each
  bullet verbatim from design-07 Screen 1. The third bullet interpolates `defaultAttemptLimit` (=
  `RETRY_DEFAULT_ATTEMPT_LIMIT`, the underlying const) and `defaultBackoffStrategy` (=
  `proxyRetryBackoffStrategyLabel(null)`) from `@/data/proxyRetryBackoffStrategies` — the same module
  Show's Retry policy card display helpers read from — rather than a second hand-written "5 attempts,
  exponential" literal (`proxyRetryAttemptLimitDisplay(null)` was not used for the attempt-limit half
  because it appends its own "(default)" annotation, which would read redundantly inside "the system
  default (5 (default) attempts, ...)"; the plan's Files/AC text explicitly allows "or the underlying
  const" for this reason). No confirm click, checkbox, or modal — the disclosure sits inline and never
  gates `form.processing`/Save.

  **Manual verification (Flows B and C):** reused the T7 throwaway user/team and seeded two proxies via
  `sail tinker` — an Enhanced proxy (id 8, "T8 Enhanced Proxy") and a Simple proxy (id 9, "T8 Simple
  Proxy") — then re-ran `pnpm run build` before checking. Via the `playwright` skill (headless
  Chromium, real login):
  - **Flow C (downgrade):** opened Edit on the Enhanced proxy — no `[role="alert"]` present initially.
    Selected Mode = Simple — the disclosure appeared immediately with `AlertTitle` text "Switching to
    Simple mode" and exactly 3 `li` bullets, verbatim to design-07 (confirmed the interpolated third
    bullet rendered "the system default (5 attempts, Exponential) governs meanwhile"). The Save button
    (`button[type="submit"]`) was NOT disabled and no checkbox existed inside the alert — not a gate.
    Switched Mode back to Enhanced before saving: the disclosure disappeared immediately (alert count
    0) and a network listener confirmed no non-GET request was fired to `/proxies/8` during the
    switch-back — nothing was ever sent to the server.
  - **Flow B (upgrade):** opened Edit on the Simple proxy — no alert present. Selected Mode = Enhanced
    — alert count remained 0 throughout; the disclosure never appears on an upgrade, as specified.

  Verified: `pnpm lint:check` (clean, repo-wide); `pnpm types:check` (clean); `pnpm format:check`
  (clean, after one Prettier auto-fix); `pnpm run build` (succeeds); `composer lint` (Pint, clean);
  `composer types:check` (PHPStan level 7, 0 errors); full backend suite
  `./vendor/bin/sail test --parallel` (759 passed, 2820 assertions, unchanged — frontend-only task).

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
- **Completion notes:** `ProxyForm.vue`'s existing `form.transform()` now sends `null` for
  `retry_attempt_limit`/`retry_backoff_strategy` whenever `data.mode === 'simple'`, in addition to the
  existing blank/sentinel-based normalisation — so a Simple-mode submission carries `null` for both
  regardless of the fields' in-memory state (e.g. a dormant value seeded from `props.initial` at mount,
  which `watch(isEnhanced, ...)` never clears since it only fires on an in-session change). An
  Enhanced-mode submission is unaffected — its existing blank-string/sentinel → `null` normalisation
  logic is unchanged. A code comment at the transform explains the plan Risk 4 failure mode this
  closes and that this is a normalisation, not a gate — T1's server-side omission rule governs
  regardless of what the client sends.

  **Manual verification (plan Risk 4):** seeded a Simple proxy holding a dormant retry policy (id 10,
  `retry_attempt_limit = 4`, `retry_backoff_strategy = 'fixed'`) in the T7/T8 throwaway team via `sail
  tinker`, then re-ran `pnpm run build`. Via the `playwright` skill: opened Edit on proxy 10 — confirmed
  the Retry policy fieldset (`#retry_attempt_limit`) does not render (mode is Simple, `design-06` Flow
  F gating unchanged). Clicked **Save changes** without touching Mode; captured the actual PUT request
  body via a Playwright request listener:
  `{"name":"T9 Dormant Simple Proxy","mode":"simple","processing_mode":"async","response_status":null,
  "response_body":null,"retry_attempt_limit":null,"retry_backoff_strategy":null,"destinations":[]}` —
  both retry fields `null` despite the dormant `4`/`fixed` sitting in unrendered form state at mount.
  The response was `303` (Inertia's normal redirect-on-success), not `422` — the save succeeded.
  Re-queried the database directly via `sail tinker`: `retry_attempt_limit = 4`,
  `retry_backoff_strategy = 'fixed'`, `mode = 'simple'` — the persisted dormant values are byte-for-byte
  unchanged after the save, confirming both that the client normalisation prevented the 422 T2's
  `prohibited_if` test proves would otherwise fire, and that the server-side omission rule (T1) is what
  actually preserved the values (the client sent `null`, not the dormant `4`/`fixed`, and T1's Simple
  write-path omits both keys entirely regardless).

  Verified: `pnpm lint:check` (clean, repo-wide); `pnpm types:check` (clean); `pnpm format:check`
  (clean, no reformat needed this time); `pnpm run build` (succeeds); `composer lint` (Pint, clean);
  `composer types:check` (PHPStan level 7, 0 errors); full backend suite
  `./vendor/bin/sail test --parallel` (759 passed, 2820 assertions, unchanged — frontend-only task).

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
- **Completion notes:** `Show.vue` gained a new `modeSummary` computed (present-tense Simple/Enhanced
  copy verbatim from design-07 Screen 2(a)) and a single muted `<p class="text-sm text-muted-foreground">`
  directly below the existing header row (name + Mode badge + Processing badge). No new `Card` —
  the header row and this caption were wrapped in one `flex flex-col gap-1` container so the caption
  sits immediately under the row it describes without touching the row's own layout or the action
  buttons; nothing else in the header changed (Amendment B). The copy references — never restates —
  the Retry policy card ("See Retry policy below for what governs this proxy's retries" / "...lets you
  configure its retry attempts and backoff below").

  The stale rationale comment on `retryAttemptsDisplay`/`retryBackoffDisplay` (previously: "A
  simple-mode proxy's columns are always NULL (per-proxy configurability is enhanced-only, T30)") was
  rewritten to cite the actual mechanism: `ProxyResource`'s T5 suppression, gated server-side by T1's
  `RetryPolicy::configuredAttemptLimitFor()`/`configuredStrategyFor()` mode gate (ADR-018 Decision 4)
  — retiring the retired T30-era framing (T30 was reversed by ADR-018 Decision 3; the columns are no
  longer nulled on a Simple save, they're suppressed at the read surface instead). The two computeds
  themselves are byte-for-byte unchanged in code, as required.

  **Manual verification (Flow E):** seeded four proxies in the T7–T9 throwaway team via `sail tinker`
  — Simple/never-configured (id 11), Simple/holding-a-dormant-policy (id 10, reused from T9, 4/fixed),
  Enhanced/unconfigured (id 12), Enhanced/configured (id 13, 8/fixed) — then re-ran `pnpm run build`.
  Via the `playwright` skill, visited each Show page and read the mode-summary caption and the Retry
  policy card's Attempts/Backoff values directly from the rendered DOM:
  - Simple, never configured → Simple copy; `5 (default)` / `Exponential (default)`.
  - Simple, holding a dormant policy → **identical** Simple copy; **identical** `5 (default)` /
    `Exponential (default)` — confirming no leak of the dormant 4/fixed.
  - Enhanced, unconfigured → Enhanced copy; `5 (default)` / `Exponential (default)`.
  - Enhanced, configured → Enhanced copy; `8` / `Fixed interval`.

  All four match design-07's States table exactly. Cleaned up afterward: all seeded proxies
  (`forceDelete()`, destinations first), the `team_members` pivot row, the throwaway team, and the
  throwaway user, via a final `sail tinker --execute` call — the shared local dev database is left as
  it was found.

  Verified: `pnpm lint:check` (clean, repo-wide); `pnpm types:check` (clean); `pnpm format:check`
  (clean, no reformat needed); `pnpm run build` (succeeds); `composer lint` (Pint, clean);
  `composer types:check` (PHPStan level 7, 0 errors); full backend suite
  `./vendor/bin/sail test --parallel` (759 passed, 2820 assertions, unchanged — frontend-only task).

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
- **Completion notes:** Added `RetryPolicy::sweepGraceSeconds(): int`, returning
  `positiveConfigInt('sweep_grace_seconds')` — the same guarded accessor pattern as every other
  `retry.*` key, with its own docblock naming review-06 Minor 9 and the failure mode it closes (a
  blank/zero env silently making the sweep cutoff `now()`). `SweepDueRetries::handle()` now calls
  `app(RetryPolicy::class)->sweepGraceSeconds()` instead of reading
  `config('retry.sweep_grace_seconds')` directly; its class docblock's `{@see}` reference was updated
  to point at the new accessor. No other production code touched; `PipelineFactory` not touched.

  Added 5 cases to `tests/Unit/Services/RetryPolicyTest.php`: the configured-value pass-through case;
  zero-raises; negative-raises; blank-env-raises (`putenv('RETRY_SWEEP_GRACE_SECONDS=')` +
  re-`require`ing `config/retry.php`, mirroring the existing `default_attempt_limit`/
  `exponential_multiplier` blank-env cases); non-numeric-env-raises. Added 1 regression case to
  `tests/Unit/Actions/SweepDueRetriesTest.php`
  (`test_a_zero_sweep_grace_seconds_throws_instead_of_sweeping_every_retrying_delivery`) — a
  `retrying` delivery present, grace forced to `0` via `Config::set`, asserts the sweep throws
  `RuntimeException` and never dispatches `RetryDelivery`, proving the previously-legal explicit `0`
  now fails loudly (deliberate, plan §Technical ruling 7) rather than re-dispatching every `retrying`
  delivery. The four pre-existing `SweepDueRetriesTest` cases (overdue/not-yet-due/terminal/double-fire)
  needed no change — they already read `config('retry.sweep_grace_seconds')` for their own grace-window
  arithmetic, which is unaffected by the accessor swap in production code.

  Confirmed by grep (`grep -rn "config('retry\.\|config(\"retry\." app/`) that `RetryPolicy.php` is now
  the only file in `app/` reading `config('retry.*')` — `SweepDueRetries.php` no longer appears; the
  remaining hits are all inside `RetryPolicy.php` itself (docblocks and the `positiveConfigInt()` read).

  Verified: `./vendor/bin/sail test --filter "RetryPolicyTest|SweepDueRetriesTest"` (51 passed, 89
  assertions); full suite `./vendor/bin/sail test --parallel` (759 passed, 2816 assertions — up from
  753/2808 by this task's 6 new tests); `composer lint` (Pint, clean — one auto-fix applied to
  `RetryPolicy.php`: import ordering and a fully-qualified `{@see}` reference resolved to an import);
  `composer types:check` (PHPStan level 7, 0 errors).

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
- **Completion notes:** `DeliveryResource::toArray()` now emits `created_at` (`$this->created_at`,
  serialized verbatim, no cast change — the column already exists) alongside the existing fields; the
  class docblock names review-06 Minor 5/rider 2 and points at `events/Show.vue` as the consumer.
  `events/Show.vue`'s `deliveryGroups` computed no longer derives the group's "{time}" label from the
  earliest attempt's `started_at`, nor group ordering from the highest `Delivery.id` — both now read
  `Delivery.created_at` directly (any row in a group is created together, ahead of dispatch, by the
  same `DeliverStep` batch, so any row's `created_at` is the group's own creation time); the
  surrounding docblock comment was rewritten to describe the new derivation and name the defect it
  closes (a FIFO replay queued behind a held line with zero attempts degrading to a bare "Replay").
  `groupLabel()` itself is unchanged — it already handled `group.time` being `null` gracefully.
  `resources/js/types/proxies.ts`'s `Delivery` interface gained `created_at: string | null`, with its
  docblock updated to include it in the pre-#6 legacy-fallback null list and to name rider 2. The
  pinning assertion at `tests/Feature/ProxyEvents/ReadSurfaceRevealAcceptanceTest.php:95`
  (`->missing('event.deliveries.0.created_at')`) is inverted to `->has(...)`, with its surrounding
  comment rewritten from "the one derived-data gap" to noting the gap is now closed. `PipelineFactory`
  not touched; no migration (the column already existed — serialization only).

  **Collateral fix required to keep the shape internally consistent (judgment call, same pattern as
  T1's fixture fixes):** `WebhookEventResource`'s legacy-fallback derivation (`legacyDeliveries()`,
  for a pre-#6 event with zero real `deliveries` rows) builds a `DeliveryResource`-shaped array by
  hand and previously left out `created_at` entirely — since a legacy row has no real `Delivery`
  model to read it from. Left alone, this would leave the two `DeliveryResource`-shaped payloads
  (real vs. legacy-derived) with different key sets, and the TypeScript `Delivery.created_at: string
  | null` field would be `undefined` (not `null`) at runtime for a legacy row. Added `'created_at' =>
  null` to the derived array (the field #6 cannot know about, same treatment as `id`/`dispatch_uuid`/
  `next_attempt_at`/`attempt_limit`), and extended its docblock's "left null" list to name it. This
  file is outside T12's stated Files list but is `DeliveryResource`'s direct shape sibling — not
  touching it would have left a dead-shape inconsistency, not a passing-but-incomplete diff. No
  behaviour change for a legacy row: `dispatch_uuid` is still `null` for every legacy row, so they
  still group into a single synthetic "Original delivery" group (kind is hardcoded `Original`), which
  never reads `group.time` for its label — the addition is presentation-shape parity only, not a
  behaviour change PRD-07 scopes.

  Extended `tests/Unit/Http/Resources/DeliveryResourceTest.php::test_it_maps_the_expected_fields` with
  a `created_at` assertion (`equalTo`, since both sides read the same Carbon-cast attribute).
  Extended `tests/Unit/Http/Resources/WebhookEventResourceTest.php::test_legacy_fallback_derives_the_expected_status`
  (all 3 data-provider cases) with `assertNull($derived['created_at'])`. No new test methods — per the
  task's Testing section, this task extends existing assertions and inverts the named pin; it does not
  add a new acceptance test file.

  **Manual verification (required — no frontend test harness):** ran `pnpm run build` first (fresh
  bundle, per review-06 M-3). Seeded via `sail tinker` (cleaned up after): a FIFO proxy, one event, and
  three delivery groups on it — an `original` group (oldest `created_at`); a `replay` group B with one
  succeeded attempt, `created_at` 10 minutes old, inserted *second* (so it received the *higher*
  `Delivery.id`); and a `replay` group C with **zero attempts** (`status = Pending`, simulating a FIFO
  replay still queued behind a held line), `created_at` set to "now" (the newest), inserted *first* (so
  it received the *lower* `Delivery.id` — deliberately inverted from creation order, to prove ordering
  follows `created_at` and not `id`). Logged in via Playwright (headless Chromium) as a fresh team
  owner, opened `/{team}/proxies/{proxy}/events/{event}`, and read the three group `<h3>` label texts
  in DOM order. Observed: `["Original delivery", "Replay — Aug 26, 2026, 12:24 AM", "Replay — Aug 26,
  2026, 12:14 AM"]` — group C (zero attempts, lower id, newest `created_at`) rendered a real time label
  ("12:24 AM") rather than a bare "Replay", and appeared *before* group B (has an attempt, higher id,
  older `created_at`) — confirming both the label fix and that ordering is driven by `created_at`, not
  `Delivery.id` (the pre-fix mechanism would have ranked B before C, since B's id is higher). Test data
  and the temporary team/user were deleted afterward via `sail tinker`.

  Verified: `./vendor/bin/sail test --filter "DeliveryResourceTest|WebhookEventResourceTest|ReadSurfaceRevealAcceptanceTest"`
  (25 passed, 164 assertions); full suite `./vendor/bin/sail test --parallel` (759 passed, 2820
  assertions — up from 759/2816 by this task's 4 new assertions on existing tests, no new test
  methods); `composer lint` (Pint, clean); `composer types:check` (PHPStan level 7, 0 errors); `pnpm
  types:check` (clean); `pnpm format:check` (clean); scoped `npx eslint resources/js/pages/proxies/events/Show.vue
  resources/js/types/proxies.ts` (clean — full-repo `pnpm lint:check` remains blocked by the unrelated
  concurrent-worktree pollution documented in T6); `pnpm run build` (succeeded, fresh bundle used for
  the manual check above).

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
- **Completion notes:** **Full gate run — all green:** `./vendor/bin/sail test --parallel` (759
  passed, 2820 assertions — unchanged from T12's baseline, no rot); `composer lint` (Pint, clean);
  `composer types:check` (PHPStan level 7, 0 errors); `pnpm lint:check` (clean, repo-wide — the
  T6-documented concurrent-worktree pollution is gone); `pnpm types:check` (clean); `pnpm
  format:check` (clean); `pnpm run build` (succeeded against a fresh rebuild — see the emitted
  chunk list, including `ProxyForm-*.js`/`Show-*.js`/`Edit-*.js`).

  **Docs cross-check:** grepped `app/`, `resources/js/`, and `tests/` for the literal retired
  phrase ("a simple-mode proxy's columns are always NULL") and for every citation of "ADR-015
  Decision 3" to find any instance still asserting its superseded persistence invariant ("Simple-
  mode proxies always hold NULL/NULL … the controller clears"). T10's `Show.vue:101-105` fix was
  already in place and correctly cites the T5/T1 suppression mechanism and ADR-018 Decision 4. No
  further instance of the retired invariant was found anywhere in the diff or the pre-existing
  tree: `RetryBackoffStrategy.php:6`, `DeliveryResource.php:14`, `RetryPolicy.php:13`,
  `ProxyResource.php`, `ProxyFormResource.php`, both Form Requests' docblocks (T1), and
  `ProxyUpdateTest.php:227` all cite ADR-015 Decision 3 only for provenance/the single-resolver
  rule (reaffirmed, unchanged) or already cite ADR-018 for the persistence-invariant supersession.
  `ProxyForm.vue:159`'s "A Simple-mode submission ALWAYS sends null" comment describes the T9
  client-side normalisation of the outgoing request, not persisted-column state, and is accurate
  as written. No corrections were required beyond T10's own.

  **AC4 positive proof:** `git diff --stat f72153f..HEAD -- database/migrations/` returns empty —
  no migration file appears anywhere in the #7 diff (27 files changed, 1999 insertions(+), 189
  deletions(-), confirmed by `git diff --name-only f72153f..HEAD`).

  **Load-bearing invariants — all verified by inspection:**
  - *Three-reader invariant:* `grep -rn "retry_attempt_limit\|retry_backoff_strategy" app/`
    confirms the only two raw-column reads (`$proxy->retry_attempt_limit` /
    `$proxy->retry_backoff_strategy`) are inside `RetryPolicy::configuredAttemptLimitFor()`/
    `configuredStrategyFor()` (`RetryPolicy.php:48,59`), plus `ProxyFormResource::toArray()`
    (`ProxyFormResource.php:36-37`) — exactly three. Every other hit is a `$fillable`/`@property`/
    cast declaration (`Proxy.php`), a validation-rule key on request input (Form Requests), a
    `$data[...]` read from validated request input in the controller (not the model column), or
    `ProxyResource` delegating to `RetryPolicy` rather than reading the column itself. No fourth
    reader exists.
  - *`ProxyFormResource` single-caller invariant:* `grep -rn "ProxyFormResource" app/
    resources/js/` shows exactly one instantiation, `ProxyController::edit()`
    (`ProxyController.php:146`) — confirmed inside the `edit()` method body, not `create()`/
    `index()`/`show()`.
  - *`RetryPolicy` sole reader of all seven `retry.*` keys:* `grep -rn "config('retry\.\|config(\"retry\." app/`
    shows the only executable read is `config("retry.{$key}")` inside
    `RetryPolicy::positiveConfigInt()`; `SweepDueRetries.php` contains no `config(` call at all
    (T11 already routed it through `sweepGraceSeconds()`). All seven keys in `config/retry.php`
    (`default_attempt_limit`, `max_attempt_limit`, `exponential_base_seconds`,
    `exponential_multiplier`, `exponential_max_delay_seconds`, `fixed_interval_seconds`,
    `sweep_grace_seconds`) are read exclusively via `positiveConfigInt()` call sites inside
    `RetryPolicy.php`.
  - *`PipelineFactory` untouched:* `git diff f72153f..HEAD -- app/Pipeline/PipelineFactory.php`
    returns zero diff lines — not the enum branches, not the reserved `#8`/`#9`/`#12` comments.
  - *Excluded Senior-Developer items absent from the diff:* `git diff f72153f..HEAD --
    app/Actions/DeliverToDestination.php` and `-- bootstrap/app.php` both return zero diff lines.
    Confirmed both fixes already exist on `main` independently of #7 —
    `bootstrap/app.php:36` calls `trustProxies(...)`, and `DeliverToDestination.php:197`'s comment
    confirms the trashed-inclusive soft-delete handling is in place.

  No defects found; no corrections beyond re-confirming T10's prior fix were needed. #7 is ready
  for hand-off to the Reviewer.

  Verified: `./vendor/bin/sail test --parallel` (759 passed, 2820 assertions); `composer lint`
  (Pint, clean); `composer types:check` (PHPStan level 7, 0 errors); `pnpm lint:check` (clean);
  `pnpm types:check` (clean); `pnpm format:check` (clean); `pnpm run build` (succeeds).

---

## T14 — Revision A: the Simple → Enhanced re-seed (review-07 Finding 1, Major; plan §Milestones M7, §Technical ruling 4)
- **Description:** Rework task for review-07's one blocking Major, re-ruled by the Project Owner
  on 2026-08-26 (plan Revision A): `ProxyForm.vue`'s `watch(isEnhanced, …)` gains a symmetric
  Simple → Enhanced arm that re-seeds both retry fields from the mount seed (`props.initial`),
  using the same null-normalisation `useForm(…)`'s initialiser already uses. Unconditional — no
  "if blank", no "if a seed exists", no `props.method === 'post'` guard. The Enhanced → Simple arm
  (clearing) is untouched. One file, one watcher, per plan §Milestones M7.
- **Dependencies:** T1–T13 (all landed)
- **Files:** `resources/js/pages/proxies/ProxyForm.vue` only.
- **Acceptance Criteria (plan §Technical ruling 4, cases 4(b)/4(d)/4(e)/4(f)/4(g)(1), plus design-07
  Flow B step 3):** see manual-verification results below — all six pass.
- **Testing:** no automated frontend harness exists (deferred backlog item; Risk 8). Frontend
  triad (`pnpm lint:check`/`types:check`/`format:check`) + `pnpm run build`, then the six required
  manual-verification steps from plan §Test strategy → Revision A, executed live against a fresh
  build via the `playwright` skill, headless, with a real login. Backend suite run unmodified to
  confirm no server-side regression.
- **Completion notes:** Landed the ruling-4(b) arm exactly as specified — the `else` branch added
  to the existing `watch(isEnhanced, …)` in `resources/js/pages/proxies/ProxyForm.vue`:

  ```js
  const isEnhanced = computed(() => form.mode === 'enhanced');
  watch(isEnhanced, (enhanced) => {
      if (!enhanced) {
          form.retry_attempt_limit = '';
          form.retry_backoff_strategy = '';
      } else {
          form.retry_attempt_limit =
              props.initial.retryAttemptLimit?.toString() ?? '';
          form.retry_backoff_strategy = props.initial.retryBackoffStrategy ?? '';
      }
  });
  ```

  The surrounding comment was rewritten to name the two kinds of value (mount-seeded persisted vs.
  in-session typed) and point at plan §Technical ruling 4/Revision A. No `{ immediate: true }`, no
  `onMounted` re-seed — the watcher still does not fire on mount (4(c)). No other file touched.

  **Six required manual-verification steps** (plan §Test strategy → Revision A), run headless via
  the `playwright` skill against a freshly built bundle (`pnpm run build` immediately prior),
  fixtures seeded via `sail tinker` (`createQuietly()`, a throwaway user/team/proxies) and removed
  afterwards:

  1. **4(b) / Finding 1's reproduction.** Enhanced proxy persisting `4`/`fixed` → Edit (fieldset
     reads `4` / `Fixed interval`) → Mode Simple (disclosure appears, 1 `[role="alert"]`) → Mode
     Enhanced again → fieldset re-rendered `4` / `Fixed interval`, **not blank**. Save → observed
     PUT body: `{"name":"M7 Enhanced 4-fixed","mode":"enhanced","processing_mode":"async",
     "response_status":null,"response_body":null,"retry_attempt_limit":4,
     "retry_backoff_strategy":"fixed","destinations":[]}`. Post-save DB state:
     `{"mode":"enhanced","limit":4,"strategy":"fixed"}` — the persisted policy survived the
     abandoned downgrade. **Pass.**
  2. **4(d).** Same proxy, reloaded fresh (still `4`/`fixed`); typed `9` into Attempts; toggled
     Simple → Enhanced without saving. Fieldset read **`4`**, the saved value — not `9`, not
     blank. **Pass.**
  3. **4(e).** An Enhanced proxy with NULL columns: initial render — Attempts empty, Backoff
     `Default (Exponential)`. Toggled Simple → Enhanced: Attempts still empty, Backoff still
     `Default (Exponential)` (no materialised default). Save → observed PUT body carried
     `"retry_attempt_limit":null,"retry_backoff_strategy":null`; post-save DB state confirmed
     `{"limit":null,"strategy":null}`, not `5`/`exponential`. **Pass.**
  4. **4(f).** Create page: toggled Enhanced → Simple → Enhanced repeatedly. Attempts/Backoff read
     empty/`Default (Exponential)` at every step — no `props.method === 'post'` branch needed or
     added. **Pass.**
  5. **4(g)(1).** A Simple proxy holding a dormant `8`/`fixed`: retry fieldset not rendered.
     Saved **without touching Mode**. Observed PUT body: `{"name":"M7 Simple dormant
     8-fixed","mode":"simple",…,"retry_attempt_limit":null,"retry_backoff_strategy":null,
     "destinations":[]}`; HTTP response status **303** (a normal Inertia redirect, not a 422).
     Post-save DB state: `{"mode":"simple","limit":8,"strategy":"fixed"}` — the dormant policy
     was preserved, T9's normalisation and the server's omission rule both intact. **Pass.**
  6. **design-07 Flow B step 3** (folds in review-07 Finding 3's coverage gap). The same
     Simple-with-dormant-`8`/`fixed` proxy, reloaded (still `8`/`fixed` per the E save above):
     selected Mode = Enhanced. Fieldset immediately pre-filled **`8` / `Fixed interval`**, nothing
     re-entered. **Pass.**

  **Ruling 4(g)'s five must-not-change properties — reconfirmed after the edit:** (1) T9's submit
  normalisation (`form.transform`, keyed off `data.mode`) is untouched — case 5 above is direct
  proof it still sends `null`/`null` on a genuine Simple-mode save regardless of field state. (2)
  `prohibited_if:mode,simple` — untouched in both Form Requests; case 5's 303 (not 422) confirms
  the client normalisation still keeps it satisfiable. (3) No controller, resource, Form Request,
  resolver, or migration touched — `git diff` for this task is one file. (4) `ProxyResource` /
  read-surface suppression untouched — no diff outside `ProxyForm.vue`. (5) The downgrade
  disclosure — same render condition, same copy, still non-dismissible, still between Mode and
  Save — untouched; case 1 confirms it still appears/disappears correctly across the round trip.

  Gates: `./vendor/bin/sail test --parallel` — **759 passed, 2820 assertions**, unmodified from
  T13's baseline (no backend file touched); `composer lint` clean; `composer types:check` (PHPStan
  L7) 0 errors; `pnpm lint:check`/`pnpm types:check`/`pnpm format:check` all clean; `pnpm run
  build` succeeded (996 ms) immediately before the manual-verification pass. Seeded fixtures
  (3 proxies, 1 user, 1 personal team) removed after verification; the shared dev database was
  confirmed clean by re-running the full suite (still 759/2820) after cleanup.

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
