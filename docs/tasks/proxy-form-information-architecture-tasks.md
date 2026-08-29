# Task Plan: Proxy create/edit form — information architecture restructure

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Design:** `docs/design/design-17-proxy-form-information-architecture.md` (**Approved**, Product
  Manager, delegated design gate, 2026-08-29, with five required corrections C1–C5 — all landed. No
  question remains open.)
- **Technical Plan:** none, and none is owed. This is a frontend-only information-architecture
  restructure commissioned directly by the Project Owner (2026-08-28); it adds no field, no data, no
  endpoint and no behaviour, so no Principal Engineer plan is required. `docs/architecture/adr-026-
  inbound-verification-removal-and-minimal-outbound-header-strip.md` `## Amendment A` records that the
  Owner-directed Principal Engineer sign-off gate stays lapsed for this line of work generally; the
  design gate above is the last gate before task planning.
- **PRD:** none, and none is owed (design-17's own header and `## Approval record`).
- **Questions:** none open. `design-17`'s four Open Questions are all closed (two moot by the ADR-026
  re-basing, one ruled moot by the Product Manager 2026-08-29, one resolved by PRD-16's withdrawal).
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this stage —
  the Reviewer catches drift against `design-17` at review time).

> **Scope / conventions.** This is a **single-file task plan, not split into `docs/tasks/<slug>/`
> with per-milestone files.** `docs/tasks/README.md`'s "new task plans are written split from the
> start" rule exists to keep a reader from loading hundreds of kilobytes of shipped-milestone history
> to find one unstarted task; that problem does not exist here. This plan is eight tasks, touching
> substantially one file (`resources/js/pages/proxies/ProxyForm.vue`), built in one continuous,
> linearly-dependent sequence with no independently-shippable milestone boundary — there is no
> "M1 done, M2 not started yet" state a future reader would ever need to skip past. Splitting it into
> an `index.md` plus one or two milestone files would be pure overhead over reading this file directly.
>
> **Every task traces to `design-17` alone** — there is no PRD and no technical plan to also trace to.
> Each task cites the design section(s) it implements.
>
> **This is a frontend-only restructure. No task in this plan touches a backend file** — no migration,
> no controller, no request-validation rule, no resource, no route. `design-17` is explicit that every
> field keeps its current `v-model` binding, validation and submit behaviour (`## Interactions`,
> `## Screens & States`), and this plan holds that line: every task's Acceptance Criteria include "no
> behaviour change" checks alongside the structural/copy changes, not as an afterthought.
>
> **Files in scope:** `resources/js/pages/proxies/ProxyForm.vue` (every task below edits this file),
> `resources/js/pages/proxies/Create.vue` and `resources/js/pages/proxies/Edit.vue` (in scope for
> manual verification only — confirmed during task planning that neither needs a code change; both are
> thin wrappers that pass `initial`/props straight into `ProxyForm.vue`, and `design-17`'s own
> `## Create vs. Edit divergence` section is explicit that nothing here is Create-only or Edit-only),
> and `resources/js/components/DestinationRows.vue` (in scope for verification only — **T6** confirms
> its diff is empty; the design wraps it in a new `Card` from the outside but changes nothing inside
> it).
>
> **Existing automated tests do not assert on this form's copy or DOM structure — confirmed, not
> assumed.** Searched during task planning: `tests/Feature/Proxies/SecretAbsenceSweepTest.php`,
> `ProxyUpdateTest.php`, `ProxyControllerPagePropsTest.php`, `CredentialRemovalTest.php`,
> `ProxyRetryFieldPresentationAcceptanceTest.php` and `ProxyIndexShowTest.php` are the only test files
> that reference `ProxyForm`/`ProxyFormResource`/`DestinationRows` by name, and every one of them
> operates at the Inertia-props/persisted-data layer, not against rendered markup or copy strings.
> There is no Dusk/browser test suite in this project (`tests/Browser/` does not exist) and no frontend
> unit-test harness (`vitest`/`@vue/test-utils` are not installed — the same absence item #10's own
> `docs/tasks/sensitive-data-handling/m05-…` (T12) recorded). **No task in this plan is therefore
> expected to need an existing test file updated.** T8 exists to prove that claim by running the full
> suite, not to assume it — if it turns out wrong, T8 is where the fix lands, recorded in its
> completion notes.
>
> **Verification is manual plus the standing gates, matching item #10's own precedent for its frontend
> tasks** (no frontend test harness exists to write automated assertions against). Every task below
> that touches `ProxyForm.vue` requires `pnpm lint:check`, `pnpm format:check` and `pnpm types:check`
> green, plus a manual pass in a **production build** — `pnpm build` **on the host, not inside the Sail
> container** (rolldown's native binding is missing there). Backend gates (`composer lint`,
> `composer types:check`, `./vendor/bin/sail test`) are required to stay green throughout precisely
> because no backend file changes; a regression there would mean something broke that shouldn't have
> been touched at all.
>
> **Accessibility obligations carried through every task below, named once here and pinned again at
> the task that lands each one — none is discharged by prose alone:**
> 1. **`id="destinations-help"` must survive as every destination row URL input's
>    `aria-describedby` target.** `DestinationRows.vue` is not edited by this plan (**T6**), so this is
>    a verification obligation, not a code change — but it is named as an explicit Acceptance Criterion
>    at T6 and again at the T7 sweep, not left to be assumed true because the file wasn't touched.
> 2. **Every Tooltip trigger is a real, keyboard-focusable `button` with a discernible `aria-label`,
>    never a bare hover-only element.** `design-17` `## Accessibility` and note **N1** warn explicitly
>    against copying `ReplayDialog.vue`'s pattern (`TooltipTrigger` wrapping a bare `span`, not
>    focusable). The correct precedent already in this codebase is `resources/js/pages/teams/Edit.vue`
>    (`TooltipTrigger as-child` wrapping a real `Button`). Each task that introduces a tooltip (**T2**,
>    **T3**, **T4**, **T5**) states this as its own Acceptance Criterion; **T7** re-checks all four
>    together.
> 3. **Every new `fieldset` gets a `legend`.** "Mode and processing" (**T3**, correction C1) joins
>    "Retry policy", "Sensitive fields" and "Destinations", none of which this plan removes or leaves
>    unlabelled.
> 4. **No field's `aria-describedby` is left pointing at an id this plan deletes.** Two cases exist:
>    Name's help paragraph is cut outright (**T1**) and Backoff strategy's help paragraph is cut and
>    moved to a tooltip (**T4**); both tasks include updating the field's `aria-describedby` as an
>    Acceptance Criterion, and **T7** re-checks both.
>
> **N2 — Response's move is the one change of substance and must survive intact** (`design-17`'s own
> non-blocking note). **T2** carries it; it is not an incidental reflow to be quietly dropped or
> merged into a smaller diff.

---

## T1 — Details card: own `Card`, cut Name's help copy (`## Grouping proposal`; `## Copy rewrite pass` → Details)

- **Description:** Wrap the Name field in its own `Card`, headed `<h2 class="text-base
  font-semibold">Details</h2>` — Details holds exactly one field and needs no nested `fieldset`, the
  same precedent `## Grouping proposal` sets. Per the Details copy-rewrite row, cut the "A name to
  recognise this proxy." help paragraph entirely — the label plus the existing placeholder
  (`Stripe → billing services`) already say everything a developer needs; Name gets no tooltip (it is
  one of the four fields `## Rule: form copy vs. tooltip vs. cut` names as having nothing
  background-only to say). Remove the now-orphaned `id="name-help"` paragraph and update the Name
  `Input`'s `aria-describedby` so it no longer references the removed id.
- **Dependencies:** none
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - The form's first `Card` is headed "Details" (`h2`, `text-base font-semibold`) and contains only
    the Name field, with no nested `fieldset`.
  - The "A name to recognise this proxy." help paragraph is removed from the template entirely — not
    moved to a tooltip anywhere.
  - The Name `Input`'s `aria-describedby` no longer includes `name-help`; it still includes
    `name-error` so `InputError` stays announced.
  - Name's `v-model="form.name"`, placeholder, disabled-while-processing state and validation display
    are byte-for-byte unchanged.
- **Testing:** `pnpm lint:check`, `pnpm format:check`, `pnpm types:check` green. Manual verification in
  a host-built production bundle (`pnpm build` on host, not in Sail): the Details card renders with
  only Name inside it; submitting the form with Name blank still shows the existing validation error
  and still moves focus to Name (unchanged `onError` handler in `submit()`).
- **Completion notes:** Done (2026-08-29). Wrapped the Name field in a new first `Card`
  (`gap-6 p-6`) headed `<h2 class="text-base font-semibold">Details</h2>`, containing only Name, no
  nested `fieldset`. Removed the `id="name-help"` paragraph ("A name to recognise this proxy.")
  entirely from the template — not relocated to a tooltip. Updated Name's `Input` `aria-describedby`
  from `"name-help name-error"` to `"name-error"`. `v-model="form.name"`, placeholder, `:disabled`,
  and validation/error wiring are untouched. Also introduced the outer `<div class="space-y-6">`
  stacking wrapper around all cards (needed to keep the file well-formed once Details became its
  own `Card` distinct from the rest of the still-unrestructured form) and moved the Actions row
  (Submit/Cancel) to sit outside the card stack, at the form's end, inside a `<div class="mt-6
  flex items-center gap-3">` — anticipates the T7 AC on final structure; re-verified at T7 once
  Response/Delivery/Sensitive fields/Destinations are each split into their own cards too.
  Everything other than Details/Actions is temporarily grouped into a second, as-yet-unheaded
  `Card` pending T2–T6.
  Verification: no Playwright/browser access available to this agent (dev-team sub-agents don't
  carry that tool); verified structurally instead by reading the rendered template and confirming
  the `aria-describedby` change matches the AC, plus `pnpm build` (host) succeeding with no
  compile errors. A real browser pass (Tab to Name, submit blank, confirm validation error and
  focus land on Name) is left for the Reviewer/QA gate — recorded here rather than claimed.
  Gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, `pnpm lint:check` — 0 errors in
  `ProxyForm.vue` itself (confirmed via scoped `npx eslint resources/js/pages/proxies/ProxyForm.vue`;
  the full `lint:check` run reports unrelated errors from stale `.claude/worktrees/agent-*`
  checkouts, per this project's known lint gotcha). `composer lint` and `composer types:check` both
  green (0 diff to any backend file, as expected — this is a frontend-only task).

## T2 — Response card: own `Card`, moved second, copy rewrite, Body tooltip (`## Grouping proposal`; `## Copy rewrite pass` → Response; N2)

- **Description:** Create the form's second `Card`, headed "Response" (`h2`, no nested `fieldset` —
  same single-field-group precedent as Details), and move the Response status code and Response body
  fields into it from their current position between the Retry policy `fieldset` and the Sensitive
  fields `fieldset`. **This is the design's one substantive regrouping (N2) — it must land as an actual
  relocation, not a reflow that keeps Response where it is today.** Apply the Response copy-rewrite
  row: relabel "Response status code" → "Status code" and trim its help to exactly "Sent immediately,
  before delivery — independent of destination outcome." (the status-option specifics stay cut, not
  duplicated in a tooltip — they are already stated by the `Select` items themselves); relabel
  "Response body" → "Body" and trim its help to exactly "Optional. Disabled when Status code is 204.".
  Add a Tooltip on Body carrying "Useful for a verification challenge echo some senders require during
  setup.", triggered by a keyboard-focusable `button` holding the already-imported `Info` icon, with a
  discernible `aria-label` (e.g. "More about the response body"), its content linked to the trigger via
  `aria-describedby` (`## Accessibility`; do not follow `ReplayDialog.vue`'s bare-`span`-trigger
  pattern — see N1 and this plan's header note 2).
- **Dependencies:** T1 (positioned directly after the Details `Card` built there)
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - The second `Card` in the form is headed "Response" and contains exactly Status code and Body,
    positioned directly after Details and before Delivery.
  - Status code's label reads "Status code"; its help paragraph reads exactly "Sent immediately, before
    delivery — independent of destination outcome."; it carries no tooltip.
  - Body's label reads "Body"; its help paragraph reads exactly "Optional. Disabled when Status code is
    204.".
  - Body has a Tooltip whose trigger is a real, keyboard-focusable `button` (Tab-reachable, opens on
    focus as well as hover) with a discernible `aria-label`, and whose content — "Useful for a
    verification challenge echo some senders require during setup." — is linked to the trigger via
    `aria-describedby`.
  - `statusSelect`, `selectedStatus`, `bodyDisabled`, the 204-forces-empty-body `watch`, and both
    fields' `v-model`/validation/error wiring are unchanged.
- **Testing:** pnpm gates green. Manual verification (host production build): selecting status 204
  still disables and blanks Body; the Body tooltip opens on Tab-focus and on hover and closes on
  blur/Escape; an accessibility spot-check (axe DevTools or a screen reader) confirms the tooltip's
  content is exposed as the trigger button's accessible description.
- **Completion notes:** Done (2026-08-29). Created the second `Card`, headed "Response" (`h2`,
  no nested `fieldset`), positioned directly after Details and before Delivery. Relocated the
  Status code and Body fields out of their old position (between the Retry policy `fieldset` and
  the Sensitive fields `fieldset`) into this new card — an actual move, not a reflow (N2):
  confirmed by grep that "Upstream response" no longer appears anywhere near the Retry
  policy/Sensitive fields boundary. Relabelled "Response status code" → "Status code"; help now
  reads exactly "Sent immediately, before delivery — independent of destination outcome."; no
  tooltip on Status code. Relabelled "Response body" → "Body"; help now reads exactly "Optional.
  Disabled when Status code is 204."; added a Tooltip on Body — trigger is a real `Button`
  (`variant="ghost" size="icon-sm"`) wrapping the already-imported `Info` icon, `as-child` inside
  `TooltipTrigger`/`TooltipProvider` (the `teams/Edit.vue` precedent, not `ReplayDialog.vue`'s bare
  `span`), `aria-label="More about the response body"`, content "Useful for a verification
  challenge echo some senders require during setup." reka-ui's `TooltipTrigger` sets
  `aria-describedby` to the content's id automatically while open (verified by reading
  `node_modules/reka-ui/dist/Tooltip/TooltipTrigger.js`), so no manual id-wiring was needed —
  matches the same auto-linking the `teams/Edit.vue` precedent relies on. `statusSelect`,
  `selectedStatus`, `bodyDisabled`, the 204-forces-empty-body `watch`, and both fields'
  `v-model`/validation/error wiring are byte-for-byte unchanged (only label/help text and
  container moved).
  Verification: no Playwright/browser access available to this agent; verified structurally by
  reading the rendered template (card order, exact copy strings, tooltip composition) and by
  `pnpm build` (host) succeeding. A live pass — selecting 204 still disables/blanks Body, the
  tooltip opens on Tab-focus and hover and closes on blur/Escape, and an axe/screen-reader check
  that the tooltip content is exposed as the trigger's accessible description — is left for the
  Reviewer/QA gate, not claimed here.
  Gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped `npx eslint
  resources/js/pages/proxies/ProxyForm.vue` 0 errors (one import-order violation from adding the
  Tooltip import was caught and fixed with `--fix` before commit). `composer lint`/`composer
  types:check` green, 0 diff to any backend file.

## T3 — Delivery card shell + "Mode and processing" fieldset (correction C1; `## Copy rewrite pass` → Delivery)

- **Description:** Create the form's third `Card`, headed "Delivery" (`h2`). Inside it, wrap Mode, the
  downgrade disclosure `Alert`, and Processing in a new `fieldset` with `legend` text **"Mode and
  processing"** (correction C1 — the Delivery card holds **two** `fieldset`/`legend` groups, not one).
  Relocate the existing Retry policy `fieldset` into the same Delivery `Card`, as a sibling to "Mode and
  processing", still `v-if="isEnhanced"` — **its own content is untouched by this task; its copy pass
  is T4.** Apply the Mode/Processing copy-rewrite rows: trim Mode's help to exactly "Enhanced stores
  what was actually dispatched and unlocks the retry settings below." and add a Tooltip carrying
  "Automatic retry, payload capture, retention, and replay all apply regardless of Mode — this only
  affects dispatched-payload storage and the retry settings below." (same keyboard-focusable
  `Info`-button shape as T2); trim Processing's help to exactly "Async (default) delivers in parallel,
  no order guaranteed. FIFO preserves order, at lower throughput. Set independently of Mode." — kept in
  full on-form, no tooltip. The downgrade disclosure `Alert`'s own three-bullet text stays byte-for-byte
  unchanged (this project's carve-out for multi-step consequence statements — `CLAUDE.md`
  "Communication style") and keeps rendering only while `isDowngrading` is true, between Mode and
  Processing inside the new fieldset (Screen 1 order).
- **Dependencies:** T2 (positioned directly after the Response `Card`)
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - The third `Card` is headed "Delivery" and contains two `fieldset`/`legend` groups: "Mode and
    processing" (Mode, the downgrade disclosure, Processing, in that order) and "Retry policy"
    (`v-if="isEnhanced"`, content carried over unmodified from its current position).
  - Mode's label is unchanged ("Mode"); its help paragraph reads exactly "Enhanced stores what was
    actually dispatched and unlocks the retry settings below."
  - Mode has a Tooltip whose trigger is a real, keyboard-focusable `button` with a discernible
    `aria-label`, content — "Automatic retry, payload capture, retention, and replay all apply
    regardless of Mode — this only affects dispatched-payload storage and the retry settings below." —
    linked via `aria-describedby`.
  - Processing's help paragraph reads exactly "Async (default) delivers in parallel, no order
    guaranteed. FIFO preserves order, at lower throughput. Set independently of Mode." and carries no
    tooltip.
  - The downgrade `Alert`'s three-bullet text (including the interpolated `defaultAttemptLimit`/
    `defaultBackoffStrategyLower` values) is unchanged character-for-character and still renders only
    when `isDowngrading` is true.
  - `isEnhanced`, `isDowngrading`, and the Enhanced↔Simple discard-and-reseed `watch` on
    `retry_attempt_limit`/`retry_backoff_strategy` are unchanged.
- **Testing:** pnpm gates green. Manual verification (host production build): switching Mode to
  Enhanced reveals the Retry policy fieldset immediately below "Mode and processing" inside the same
  Delivery card; on an Edit form for a currently-Enhanced proxy, switching to Simple still shows the
  downgrade disclosure with its exact existing text, now positioned inside "Mode and processing"; the
  Mode tooltip opens on Tab-focus.
- **Completion notes:** Done (2026-08-29). Created the third `Card`, headed "Delivery" (`h2`),
  directly after Response. Inside it, wrapped Mode, the downgrade disclosure `Alert`, and
  Processing in a new `fieldset` legended "Mode and processing" (correction C1); relocated the
  existing Retry policy `fieldset` (still `v-if="isEnhanced"`) into the same Delivery `Card`, as a
  sibling — its own content untouched here (copy pass is T4). Mode's help now reads exactly
  "Enhanced stores what was actually dispatched and unlocks the retry settings below."; added a
  Tooltip on Mode (same `Button`/`TooltipTrigger as-child`/`TooltipProvider` shape as T2's Body
  tooltip) carrying "Automatic retry, payload capture, retention, and replay all apply regardless
  of Mode — this only affects dispatched-payload storage and the retry settings below." Processing's
  help now reads exactly "Async (default) delivers in parallel, no order guaranteed. FIFO preserves
  order, at lower throughput. Set independently of Mode." — no tooltip. The downgrade `Alert`'s
  three-bullet text, including the interpolated `defaultAttemptLimit`/`defaultBackoffStrategyLower`
  values, is unchanged character-for-character (confirmed via `git diff`: only surrounding
  indentation moved, no text inside the `<li>`s changed) and still gated on `isDowngrading`.
  `isEnhanced`, `isDowngrading`, and the Enhanced↔Simple discard-and-reseed `watch` are unchanged.
  Closed the Delivery `Card` immediately after the Retry policy `fieldset` and opened a new,
  as-yet-unheaded `Card` to hold Sensitive fields + Destinations pending T5/T6 — same "keep the file
  well-formed at every commit" approach used at T1 for the Details/rest split.
  Verification: no Playwright/browser access available to this agent; verified structurally (card
  boundaries, exact copy strings, `git diff` confirming the downgrade Alert's `<li>` text is
  byte-identical) plus `pnpm build` (host) succeeding. A live pass — switching Mode to Enhanced
  reveals Retry policy directly under "Mode and processing" in the same card; on an Edit form for a
  currently-Enhanced proxy, switching to Simple shows the downgrade disclosure inside "Mode and
  processing"; the Mode tooltip opens on Tab-focus — is left for the Reviewer/QA gate, not claimed
  here.
  Gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped `npx eslint
  resources/js/pages/proxies/ProxyForm.vue` 0 errors. `composer lint`/`composer types:check` green,
  0 diff to any backend file.

## T4 — Retry policy fieldset copy pass (correction C5; `## Copy rewrite pass` → Delivery)

- **Description:** Within the Retry policy `fieldset` relocated by T3, apply its copy-rewrite row: cut
  the fieldset help's first sentence ("Applies to automatic re-attempts after a failed delivery to a
  destination.") — redundant now that the fieldset only ever renders directly under Mode = Enhanced in
  the same card — keeping the remainder, trimmed, as exactly "Simple-mode proxies use the fixed default
  ({N} attempts, {strategy})." (interpolation unchanged). Apply correction C5: change the Attempts help
  from the hard-coded literal "Leave blank to use the default (5). Maximum 10." to "Default {N}. Max
  10." where `{N}` is an interpolation of the same `defaultAttemptLimit` constant the fieldset help
  above it already reads (`RETRY_DEFAULT_ATTEMPT_LIMIT` from `@/data/proxyRetryBackoffStrategies`) —
  **not a second hard-coded literal that can drift out of sync.** Cut the Backoff strategy help
  paragraph entirely from the form; move its content to a Tooltip — "Exponential increases the wait
  each attempt; fixed interval stays constant. Always bounded well inside the 30-day retention
  window." — triggered by a keyboard-focusable, `aria-label`led `Info`-icon button next to the "Backoff
  strategy" label (same shape as T2/T3). Update the Backoff strategy `Select`'s `aria-describedby` so
  it no longer references the removed help paragraph's id.
- **Dependencies:** T3
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - The Retry policy fieldset's own help paragraph reads exactly "Simple-mode proxies use the fixed
    default ({N} attempts, {strategy})." with both placeholders still interpolated live.
  - The Attempts help paragraph reads exactly "Default {N}. Max 10." where `{N}` renders the live
    `defaultAttemptLimit` value — verified by confirming both help strings change together if
    `RETRY_DEFAULT_ATTEMPT_LIMIT` is (hypothetically) changed, with no literal `5` remaining anywhere
    in this fieldset's copy.
  - The Backoff strategy help `<p>` is removed from the template; the Backoff strategy `Select`'s
    `aria-describedby` no longer references the removed id.
  - Backoff strategy has a Tooltip whose trigger is a real, keyboard-focusable `button` with a
    discernible `aria-label`, content exactly "Exponential increases the wait each attempt; fixed
    interval stays constant. Always bounded well inside the 30-day retention window.", linked via
    `aria-describedby`.
  - `retryStrategySelect`, the `RETRY_STRATEGY_DEFAULT` sentinel handling, and both fields'
    validation/error wiring are unchanged.
- **Testing:** pnpm gates green. Manual verification (host production build): Attempts' help and the
  fieldset help above it display the identical number; leaving Attempts blank and saving still persists
  `null` (system default), exactly as today; the Backoff strategy tooltip opens on Tab-focus.
- **Completion notes:** Done (2026-08-29). Retry policy fieldset help cut to exactly "Simple-mode
  proxies use the fixed default ({{ defaultAttemptLimit }} attempts, {{ defaultBackoffStrategyLower
  }})." — the first sentence about applying to automatic re-attempts is gone, both interpolations
  kept live. Correction C5 applied: Attempts help changed from the hard-coded "Leave blank to use
  the default (5). Maximum 10." to "Default {{ defaultAttemptLimit }}. Max 10." — reads the same
  `RETRY_DEFAULT_ATTEMPT_LIMIT`-derived constant the fieldset help above it already reads, so no
  literal `5` remains anywhere in this fieldset; confirmed via grep that no bare "5" string exists
  in the Retry policy block. Backoff strategy's help `<p id="retry-backoff-strategy-help">` is
  removed from the template entirely (confirmed via grep — the id no longer appears anywhere in the
  file); its content moved to a Tooltip next to the "Backoff strategy" label, same
  `Button`/`TooltipTrigger as-child`/`TooltipProvider` shape as T2/T3, `aria-label="More about
  Backoff strategy"`, content exactly "Exponential increases the wait each attempt; fixed interval
  stays constant. Always bounded well inside the 30-day retention window." Updated the Backoff
  strategy `Select`'s `SelectTrigger` `aria-describedby` from `"retry-backoff-strategy-help
  retry-backoff-strategy-error"` to `"retry-backoff-strategy-error"` — no reference to the removed
  id remains. `retryStrategySelect`, the `RETRY_STRATEGY_DEFAULT` sentinel handling, and both
  fields' validation/error wiring are unchanged.
  Verification: no Playwright/browser access available to this agent; verified structurally by
  reading the exact rendered copy strings and confirming via grep that the old help id is gone and
  no stale literal `5` remains, plus `pnpm build` (host) succeeding. A live pass — Attempts' help and
  the fieldset help above it showing the identical number, leaving Attempts blank still persisting
  `null`, and the Backoff strategy tooltip opening on Tab-focus — is left for the Reviewer/QA gate,
  not claimed here.
  Gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped `npx eslint
  resources/js/pages/proxies/ProxyForm.vue` 0 errors. `composer lint`/`composer types:check` green,
  0 diff to any backend file.

## T5 — Sensitive fields card, copy rewrite, "Always hidden" tooltip (`## Grouping proposal`, correction C4; `## Copy rewrite pass` → Sensitive fields)

- **Description:** Wrap the existing Sensitive fields `fieldset` in its own `Card` — no `h2`; the
  `legend` carries the heading weight, the single-`fieldset`-card rule `## Grouping proposal` names for
  Sensitive fields and Destinations (correction C4). Apply its copy-rewrite row: trim the section help
  to exactly "Hidden wherever this proxy's payloads are shown. Storage and delivery are unaffected."
  Cut the "Case and separators don't matter — password, Password and pass_word are all this same name."
  paragraph from the form entirely; move it onto the "Always hidden" label as an on-demand Tooltip
  carrying exactly "Matches password, Password, pass_word, etc. — case and separators don't matter."
  (Info-icon button, `aria-label` e.g. "More about matching for Always hidden fields", content linked
  via `aria-describedby`, same shape as T2–T4).
- **Dependencies:** T4
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - The fourth `Card` wraps the "Sensitive fields" `fieldset`/`legend` with no separate `h2` inside the
    `Card`.
  - The section help paragraph reads exactly "Hidden wherever this proxy's payloads are shown. Storage
    and delivery are unaffected."
  - The "Case and separators don't matter…" paragraph is removed from the form's template entirely —
    not left as dead, unreferenced prose anywhere in the file.
  - The "Always hidden" label has a Tooltip whose trigger is a real, keyboard-focusable `button` with a
    discernible `aria-label`, content exactly "Matches password, Password, pass_word, etc. — case and
    separators don't matter.", linked via `aria-describedby`.
  - Every default badge (all entries from `defaultSensitiveFieldNames`, rendered literally, one badge
    per entry, per item #10's correction C4 there — not this design's C4), the additions list,
    Add/Remove behaviour (`addSensitiveField`/`removeSensitiveField`), and the "no
    enable/disable-obfuscation control anywhere" invariant (item #10's N4) are unchanged.
- **Testing:** pnpm gates green. Manual verification (host production build): default badges still
  render literally and wrap correctly at 360px; adding/removing an addition still works in-session with
  no server round trip until save; the new tooltip opens on Tab-focus and its wording matches exactly.
- **Completion notes:** Done (2026-08-29). Wrapped the existing "Sensitive fields" `fieldset` in
  its own fourth `Card` — no `h2`, the `legend` carries the heading weight (correction C4). Section
  help trimmed to exactly "Hidden wherever this proxy's payloads are shown. Storage and delivery are
  unaffected." The "Case and separators don't matter…" paragraph is removed from the template
  entirely (confirmed via grep — no remaining occurrence anywhere in the file); its content moved to
  a Tooltip on the "Always hidden" label, same `Button`/`TooltipTrigger as-child`/`TooltipProvider`
  shape as T2–T4, `aria-label="More about matching for Always hidden fields"`, content exactly
  "Matches password, Password, pass_word, etc. — case and separators don't matter." All default
  badges (`defaultSensitiveFieldNames`, one per entry), the additions list, `addSensitiveField`/
  `removeSensitiveField`, and the no-enable/disable-obfuscation-control invariant are unchanged —
  only the section help paragraph, the removed paragraph, and the new tooltip were touched.
  Closed the Sensitive fields `Card` right after its `fieldset` and left `<DestinationRows>` +
  its `InputError` as bare (un-Carded) siblings for now, explicitly commented "T6 wraps this in its
  own Card" — kept T5's diff to Sensitive fields only rather than pre-empting T6's own AC.
  Verification: no Playwright/browser access available to this agent; verified structurally by
  reading the exact copy strings and confirming via grep that the cut paragraph is gone, plus
  `pnpm build` (host) succeeding. A live pass — default badges rendering literally and wrapping at
  360px, add/remove working in-session with no server round trip, and the new tooltip opening on
  Tab-focus with exact wording — is left for the Reviewer/QA gate, not claimed here.
  Gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped `npx eslint
  resources/js/pages/proxies/ProxyForm.vue` 0 errors. `composer lint`/`composer types:check` green,
  0 diff to any backend file.

## T6 — Destinations card: structural wrap only, `DestinationRows.vue` untouched (`## Grouping proposal`; correction C2)

- **Description:** Wrap `<DestinationRows>` in its own `Card` — the form's fifth and last `Card`,
  unchanged position. `resources/js/components/DestinationRows.vue` is **not edited by this task**:
  its `fieldset`/`legend`, its own help paragraph ("The webhook is delivered to every destination
  below.", ruled keep-unchanged at correction C2), its `id="destinations-help"`, and every destination
  row's `aria-describedby="destinations-help"` wiring all stay exactly as shipped. The Destinations
  Credential subsection copy (the two sentences quoted in `## Copy rewrite pass` → Destinations /
  Credential) is likewise left as-is — it is the one section `design-17` holds up as already meeting
  the standard the rest of the form is brought up to, not a section that itself needs rewriting.
- **Dependencies:** T5
- **Files:** `resources/js/pages/proxies/ProxyForm.vue` (the wrap). `DestinationRows.vue` is verified,
  not modified.
- **Acceptance Criteria:**
  - The fifth `Card` wraps `<DestinationRows>` with no `h2` inside the `Card` (same C4-style rule as
    Sensitive fields — the component's own `legend` carries the heading weight).
  - `resources/js/components/DestinationRows.vue`'s only permitted change from what T6 itself shipped
    is the `legend`'s `class` attribute at line 123, corrected by T9 from `class="text-sm font-medium"`
    to `class="text-base font-semibold"` per the Designer's 2026-08-29 amendment to `design-17`
    (`## Amendment — card-level legend heading weight ruling`, Ruling 1). No other line, attribute,
    string, or piece of markup in the file changes: `id="destinations-help"` and every destination
    row's `aria-describedby="destinations-help"` wiring stay exactly as shipped, and every copy
    string — the fieldset's help paragraph and the Credential subsection's two sentences — is
    unchanged.
  - `id="destinations-help"` is present exactly once, on the fieldset's help paragraph, and every
    destination row's URL `input` still points at it via `aria-describedby="destinations-help"` when
    that row has no error.
  - The `InputError` for `form.errors.destinations` (rendered outside `<DestinationRows>`) is
    unchanged.
- **Testing:** pnpm gates green. Manual verification (host production build): adding/removing a
  destination row, and replacing/removing a row's credential, all behave exactly as before; a
  deliberately invalid destination URL still swaps that row's `aria-describedby` to its own error id
  instead of `destinations-help`.
- **Completion notes:** Done (2026-08-29). Wrapped `<DestinationRows>` and its sibling
  `InputError` in a new fifth (last) `Card`, no `h2` — same single-`fieldset`-card rule as Sensitive
  fields. `git diff --stat resources/js/components/DestinationRows.vue` produces no output — zero
  diff, confirmed, not assumed: its own `fieldset`/`legend`, its help paragraph, `id=
  "destinations-help"`, and every row's `aria-describedby="destinations-help"` wiring (verified
  present at `DestinationRows.vue` lines 124 and 152) are all exactly as shipped. The Destinations
  Credential subsection copy was not touched, per the task's own instruction that it already meets
  the standard.
  Verification: no Playwright/browser access available to this agent; verified structurally (zero
  diff to `DestinationRows.vue`, the `destinations-help` id and its wiring still present, unchanged)
  plus `pnpm build` (host) succeeding. A live pass — add/remove a destination row and
  replace/remove a credential behaving exactly as before, and a deliberately invalid destination URL
  swapping that row's `aria-describedby` to its own error id — is left for the Reviewer/QA gate, not
  claimed here.
  Gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped `npx eslint
  resources/js/pages/proxies/ProxyForm.vue resources/js/components/DestinationRows.vue` 0 errors.
  `composer lint`/`composer types:check` green, 0 diff to any backend file.
- **Task Planner correction note (2026-08-29), added post-review:** The acceptance criterion above
  was rewritten after Review-17 Finding 1 (Major) and the Designer's amendment
  `## Amendment — card-level legend heading weight ruling` (2026-08-29); the original criterion
  required zero diff to `DestinationRows.vue`, which conflicts with the design's own heading-weight
  rule once the Designer ruled that "unchanged internals" never governed the `legend`'s presentational
  class. Separately: both this task's and T5's completion notes above describe the relevant `legend`
  as carrying "the heading weight." At the time each task closed, the shipped class on both was
  `text-sm font-medium` — unchanged from `main` and identical to the Delivery card's two subordinate
  `legend`s — which Review-17 found indistinguishable from a subordinate heading. Those completion
  notes are left exactly as the Senior Developer wrote them; this note exists only so the plan does
  not now read as though that class already satisfied the design's heading-weight rule when T5 and T6
  closed. T9 makes the class change the design actually requires.

## T7 — Cross-cutting accessibility and structure verification sweep

- **Description:** A consolidated check across all five Cards and both surfaces (Create, Edit) that no
  accessibility obligation named by `design-17` — or restated in this plan's header note — was lost
  across T1–T6. This task introduces no new copy or fields; it verifies the assembled result and is
  where any gap found gets fixed before moving on to the full regression sweep.
- **Dependencies:** T1, T2, T3, T4, T5, T6
- **Files:** `resources/js/pages/proxies/ProxyForm.vue` (fixes only, if a gap is found); no new files.
- **Acceptance Criteria:**
  - Exactly five `Card`s render, stacked with `space-y-6` (`docs/standards/design.md`
    "stacked-section spacing"), in the order Details → Response → Delivery → Sensitive fields →
    Destinations, on both `proxies/Create.vue` and `proxies/Edit.vue`. Confirmed: neither page's own
    file needed a code change to get this — `Create.vue`/`Edit.vue` diffs from this plan are empty.
  - Every `fieldset` in the form has a `legend`: "Mode and processing", "Retry policy", "Sensitive
    fields", "Destinations".
  - All four Tooltip triggers introduced by T2/T3/T4/T5 (Response Body, Mode, Backoff strategy,
    Sensitive fields "Always hidden") are real `button` elements, keyboard-focusable (Tab-reachable,
    open on focus, close on blur/Escape), each with a discernible `aria-label` and its content linked
    via `aria-describedby` — none is a bare hover-only `span`/`div` (the N1 `ReplayDialog.vue`
    anti-pattern is absent from this file).
  - No field's `aria-describedby` in the final file references an id that does not exist in the
    template (spot-check Name and Backoff strategy specifically, per T1/T4).
  - The form's outer wrapper (`mx-auto w-full max-w-3xl`) and the Actions row (Submit, Cancel) are
    unchanged and sit outside all five Cards, at the form's end.
  - The form remains usable and un-clipped at 360px width (`docs/standards/design.md` baseline).
- **Testing:** `pnpm lint:check`, `pnpm format:check`, `pnpm types:check` green (these gates do not
  catch a dangling `aria-describedby` id or a non-focusable tooltip trigger — this is a manual/DOM
  check, recorded as such in completion notes, not asserted as compiler-caught). Manual pass in a
  host-built production bundle against both `/proxies/create` and an existing proxy's
  `/proxies/{id}/edit`, in both light and dark mode, tabbing through every field and every tooltip
  trigger.
- **Completion notes:** Done (2026-08-29). Swept the assembled file at the source level (no code
  change needed — no gap found):
  - Exactly five `Card`s (`grep -n "<Card class"` → 5 matches), stacked inside one `<div
    class="space-y-6">`, order Details → Response → Delivery → Sensitive fields → Destinations.
  - Every `fieldset` has a `legend`: "Mode and processing" (Delivery), "Retry policy" (Delivery,
    `v-if="isEnhanced"`), "Sensitive fields", and "Destinations" (inside `DestinationRows.vue`,
    confirmed untouched by T6).
  - All four Tooltip triggers (Response Body, Mode, Backoff strategy, Sensitive fields "Always
    hidden") use the identical shape: `TooltipTrigger as-child` wrapping a real `Button` (`variant=
    "ghost" size="icon-sm"`) with a discernible `aria-label`, `Info` icon — none is a bare
    `span`/`div` (the `ReplayDialog.vue`/N1 anti-pattern does not appear in this file, confirmed via
    grep for `TooltipTrigger` — every occurrence is immediately followed by `as-child` and a
    `<Button`). `reka-ui`'s `TooltipTrigger` sets `aria-describedby` to the content id automatically
    while open (`node_modules/reka-ui/dist/Tooltip/TooltipTrigger.js`), so all four are linked with
    no manual id-wiring, matching the `teams/Edit.vue` precedent.
  - No dangling `aria-describedby`: diffed every `id="..."` in the file against every
    `aria-describedby="..."` reference — every referenced id exists, and neither `name-help` nor
    `retry-backoff-strategy-help` (both removed) is referenced anywhere.
  - `Create.vue`/`Edit.vue`: confirmed via `git diff main..HEAD --stat` that both files carry zero
    diff across every commit in this feature — both remain thin wrappers passing `initial`/props
    straight into `ProxyForm.vue`, exactly as the plan's header predicted.
  - Outer wrapper (`mx-auto w-full max-w-3xl`) and the Actions row (`<div class="mt-6 flex
    items-center gap-3">`) sit outside the `space-y-6` card stack, at the form's end, unchanged from
    T1 onward.
  No fix was needed at this task; every obligation named in the plan's header notes and restated in
  this task's own ACs was already satisfied by T1–T6.
  Verification: no Playwright/browser access available to this agent, so the two AC items that are
  inherently browser-only — actual Tab-focus/open-on-focus/close-on-Escape behaviour of the four
  tooltips, and un-clipped rendering at 360px width in both light and dark mode on both
  `/proxies/create` and an existing proxy's `/proxies/{id}/edit` — are **not verified here** and are
  explicitly left for the Reviewer/QA gate. Everything else in this task's ACs is a static/source
  check, completed and recorded above, not a claim about runtime behaviour I could not observe.
  Gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped `npx eslint
  resources/js/pages/proxies/ProxyForm.vue resources/js/components/DestinationRows.vue` 0 errors,
  `pnpm build` (host) succeeds. `composer lint`/`composer types:check` green, 0 diff to any backend
  file. No source file changed by this task — completion notes only.

## T8 — Full regression sweep and gates

- **Description:** Confirm the restructure changed no data, validation or submit behaviour, and leave
  the whole tree green. This plan's header already states, and this task proves, that no existing test
  file needs updating: none of `SecretAbsenceSweepTest.php`, `ProxyUpdateTest.php`,
  `ProxyControllerPagePropsTest.php`, `CredentialRemovalTest.php`,
  `ProxyRetryFieldPresentationAcceptanceTest.php` or `ProxyIndexShowTest.php` — the only existing tests
  that reference `ProxyForm`/`ProxyFormResource`/`DestinationRows` by name — assert on rendered copy or
  markup structure.
- **Dependencies:** T7
- **Files:** none expected. If the finding above is wrong, the affected test file, updated in this
  task and recorded in its completion notes — not deferred to a follow-up.
- **Acceptance Criteria:**
  - `composer lint` and `composer types:check` are green with zero diff to any backend file (this
    feature touches no backend file).
  - `./vendor/bin/sail test` (full suite) is green, with the same pass count as before this feature
    started.
  - `pnpm lint:check`, `pnpm format:check`, `pnpm types:check` are all green on the final diff.
  - Manual end-to-end pass on both Create and Edit: submitting a valid form still redirects/persists
    exactly as before; submitting an invalid form still moves focus to the first
    `[aria-invalid="true"]` field; the Enhanced/Simple retry discard-and-reseed, the
    204-forces-empty-body watcher, sensitive-field add/remove, and every destination row's
    add/remove/credential Replace/Remove flow all still work exactly as shipped.
  - If any existing test is found to assert on copy or structure this feature changed (contrary to the
    finding above), it is updated here, and the update — which test, what changed, why — is recorded in
    this task's completion notes.
- **Testing:** as listed above; this task is itself the final verification step. No task follows it.
- **Completion notes:** Done (2026-08-29). Full regression sweep, no source file changed by this
  task.
  - `composer lint`: passed. `composer types:check` (PHPStan level 7): passed, 0 errors. Confirmed
    via `git diff main..HEAD --stat` (excluding `.vue`/task-doc files) that zero backend files
    changed across the entire feature branch — this restructure touched no PHP file at any point.
  - `./vendor/bin/sail test --parallel` (full suite): **1019/1019 passed, 4818 assertions**, in
    8.5s. This is the branch's own baseline (no prior suite count was recorded for this feature to
    diff against, since it added no test and no backend file) — recorded here as the number the
    Reviewer can diff future changes against.
  - `pnpm types:check` (vue-tsc): 0 errors. `pnpm format:check`: clean. Scoped `npx eslint
    resources/js/pages/proxies/ProxyForm.vue resources/js/components/DestinationRows.vue`: 0
    errors. Confirmed the full `pnpm lint:check` run's errors are all rooted outside
    `resources/js`/`app` (stale `.claude/worktrees/agent-*` checkouts per this project's known
    gotcha) by grepping its output for `ProxyForm`/`DestinationRows` — no match.
  - `pnpm build` (host, not Sail): succeeded, no compile errors.
  - **The plan's central claim — verified true, not assumed:** grepped all six named test files
    (`SecretAbsenceSweepTest.php`, `ProxyUpdateTest.php`, `ProxyControllerPagePropsTest.php`,
    `CredentialRemovalTest.php`, `ProxyRetryFieldPresentationAcceptanceTest.php`,
    `ProxyIndexShowTest.php`) for `assertSee`/`assertDontSee`/`->component(...)` and every literal
    copy string this feature cut or changed ("A name to recognise this proxy.", "Case and
    separators don't matter", "Leave blank to use the default", "Applies to automatic
    re-attempts", "Response status code", "Response body"). Every `->component(...)` call asserts
    only the Inertia page-component name (`proxies/Edit`/`Create`/`Index`/`Show`), never markup or
    copy; `ProxyUpdateTest`'s one `assertDontSee` checks a leaked-secret value, unrelated to this
    feature's copy. A repo-wide `grep -rl` for every changed/cut copy string across `tests/`
    returned no matches at all. **No existing test needed updating — the plan's finding holds.**
  - Manual end-to-end verification was **not performed with a browser** — no Playwright/browser
    tool is available to this agent (confirmed with the Orchestrator mid-task). What a browser pass
    still needs to check, left for the Reviewer/QA gate: submitting a valid Create/Edit form still
    redirects/persists exactly as before; submitting an invalid form still moves focus to the first
    `[aria-invalid="true"]` field; the Enhanced/Simple retry discard-and-reseed and the
    204-forces-empty-body watcher still fire (both are pure `computed`/`watch` logic, untouched by
    this restructure — verified by reading `<script setup>`, unchanged across every commit in this
    feature); sensitive-field add/remove and every destination row's add/remove/credential
    Replace/Remove flow still work (same reasoning — `DestinationRows.vue` has zero diff, and the
    sensitive-fields handlers were not touched); and the four Tooltip triggers open on Tab-focus/
    hover and close on blur/Escape (reka-ui `Tooltip` behaviour, not custom code — same primitive
    already used unmodified in `teams/Edit.vue`).
  This feature is ready for Reviewer handoff, with the browser-only checks above named explicitly
  as outstanding rather than claimed.

## T9 — Rework: card-level legend heading weight, hoist shared `TooltipProvider` (Review-17 Finding 1 Major, Finding 2 Minor; `design-17` `## Amendment — card-level legend heading weight ruling`, 2026-08-29)

- **Description:** Rework task raised directly by the review gate against the already-shipped T1–T8
  feature. Two independent fixes, both confined to files this feature already touches; neither
  changes a requirement or introduces a new component.

  1. **Heading weight (Finding 1, Major).** Apply `class="text-base font-semibold"` to exactly two
     `legend`s — the ones standing in for a card's own heading with no sibling `h2`: the Sensitive
     fields `legend` in `resources/js/pages/proxies/ProxyForm.vue` (currently at line 666, currently
     `class="text-sm font-medium"`) and the Destinations `legend` in
     `resources/js/components/DestinationRows.vue` (currently at line 123, currently
     `class="text-sm font-medium"`). This is `## Grouping proposal`'s original heading-weight rule,
     restated as an exact class by the Designer's amendment, Ruling 1. The two `legend`s nested
     inside the Delivery card — "Mode and processing" (currently line 412) and "Retry policy"
     (currently line 559) — are correctly subordinate to that card's own `h2` and are out of scope for
     this task: they keep `class="text-sm font-medium"` unchanged.
  2. **Shared `TooltipProvider` (Finding 2, Minor).** `ProxyForm.vue` currently wraps each of its
     four tooltips — Response Body help (line 363), Mode help (line 419), Backoff strategy help
     (line 602), Always hidden help (line 677) — in its own `TooltipProvider`. Replace the four
     per-tooltip `TooltipProvider`s with a single `TooltipProvider` wrapping the whole `<form>`. This
     is a structural simplification only — one provider instead of four, fewer nodes, the shape the
     primitive is designed for — and not a behaviour change: `resources/js/components/ui/tooltip/
     TooltipProvider.vue` sets `withDefaults(..., { delayDuration: 0 })`, so every provider in this
     codebase, including each of the four per-tooltip providers being replaced, already opens its
     tooltips with no delay. There was never a shared open/close delay to group between tooltips, and
     hoisting to one provider does not introduce one. Each `Tooltip`/`TooltipTrigger`/`TooltipContent`
     triple stays exactly where it is and exactly as it renders; only the `TooltipProvider` wrapping
     changes, from four instances to one.

- **Dependencies:** T8 (the shipped feature this task reworks)
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`; `resources/js/components/DestinationRows.vue`
- **Acceptance Criteria:**
  - `ProxyForm.vue`'s Sensitive fields `legend` carries `class="text-base font-semibold"`; its text
    content and every surrounding line are unchanged.
  - `DestinationRows.vue`'s Destinations `legend` carries `class="text-base font-semibold"`; this is
    the file's only change from what T6 shipped — `id="destinations-help"`, every destination row's
    `aria-describedby="destinations-help"` wiring, the fieldset's help paragraph copy, row rendering,
    add/remove handling, the Credential subsection (including both of its copy sentences), `v-model`
    bindings, and validation are all unchanged.
  - The Delivery card's two nested `legend`s ("Mode and processing", "Retry policy") remain
    `class="text-sm font-medium"`; this task makes no edit to either.
  - `ProxyForm.vue`'s four tooltips (Response Body, Mode, Backoff strategy, Always hidden) share a
    single `TooltipProvider` wrapping the `<form>`; no per-tooltip `TooltipProvider` remains. Each
    tooltip's trigger `Button`, `aria-label`, and `TooltipContent` copy are byte-identical to what
    shipped in T2–T5 — only the provider wrapping changes.
  - No other line of either file changes: no copy string, `v-model` binding, validation rule, or `id`/
    `aria-*` attribute besides what is named above is touched.
- **Testing:** Frontend gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped
  `npx eslint resources/js/pages/proxies/ProxyForm.vue resources/js/components/DestinationRows.vue`
  0 errors, and `pnpm build` (host — the sail container cannot run the Vite build). Manual
  verification (host production build) at 360px: Sensitive fields' and Destinations' headings read at
  the same size and weight as Details, Response and Delivery's `h2`s, and visibly distinct from the
  two Delivery-nested sub-headings; each of the four tooltips still opens on Tab-focus and on hover
  and closes on blur/Escape.

  No backend gate re-run: agreed with the Designer's amendment on this point — nothing in this task
  touches PHP, a migration, a controller, a request/resource class, or any backend-tested behavior, so
  `composer lint`, `composer types:check`, and the backend test suite have no file in this task's
  scope to exercise.

  One addition beyond the amendment's own recommendation: the amendment's "`pnpm format:check` and a
  360px visual re-check are sufficient" was written for the legend-class edit alone (Finding 1).
  This task also carries Finding 2's `TooltipProvider` hoist, which restructures a template — moving
  a wrapping element, not editing a utility class — so `pnpm types:check` and the scoped `eslint` run
  above are required in addition to `pnpm format:check`, not skipped. `pnpm build` is included because
  a hoisted `TooltipProvider` is exactly the kind of structural change a type-check and lint pass do
  not fully exercise at runtime.
- **Completion notes:** Done (2026-08-29).
  - **Heading weight (Finding 1).** Changed exactly two `legend`s to `class="text-base
    font-semibold"`: the Sensitive fields `legend` in `ProxyForm.vue` (now at line 688) and the
    Destinations `legend` in `DestinationRows.vue` (line 123). `DestinationRows.vue`'s diff is the
    single class edit only — confirmed via `git diff` — `id="destinations-help"`, every row's
    `aria-describedby="destinations-help"`, the help paragraph copy, row rendering, add/remove
    handling, the Credential subsection and its two copy sentences, `v-model` bindings and
    validation are all untouched. The Delivery card's two nested `legend`s ("Mode and processing"
    line 420, "Retry policy" line 579) were not touched and remain `class="text-sm font-medium"`.
  - **Shared `TooltipProvider` (Finding 2).** Removed the four per-tooltip `TooltipProvider`s
    (previously wrapping Response Body, Mode, Backoff strategy, and Always hidden individually) and
    added one `TooltipProvider` wrapping the `<form>`'s content, from immediately inside the opening
    `<form>` tag to immediately before its closing tag. Each `Tooltip`/`TooltipTrigger`/
    `TooltipContent` triple, its trigger `Button`, `aria-label`, and `TooltipContent` copy are
    unchanged — confirmed by diffing the file with all lines' leading whitespace stripped: the only
    content-level changes are the `TooltipProvider` add/removes and the one legend class edit above;
    every other line in the diff is Prettier re-wrapping at a shifted indentation depth (the whole
    card stack now sits one level deeper inside the added `TooltipProvider`), not a copy, binding,
    or attribute change.
  - Ran `pnpm format` once to let Prettier re-flow the reindented template, then verified
    `pnpm format:check` clean afterward — no hand-adjusted indentation.
  - Gates: `pnpm types:check` 0 errors; `pnpm format:check` clean; scoped `npx eslint
    resources/js/pages/proxies/ProxyForm.vue resources/js/components/DestinationRows.vue` 0 errors;
    `pnpm build` (host) succeeds. No backend file changed — no backend gate re-run, per this task's
    own Testing note.
  - **Left for the manual/browser pass** (no Playwright access in this session): the 360px visual
    re-check that Sensitive fields' and Destinations' headings now read at the same size and weight
    as Details/Response/Delivery's `h2`s and visibly distinct from the two Delivery-nested
    sub-headings; and confirming at runtime that all four tooltips still open on Tab-focus and hover
    and close on blur/Escape, and that moving focus or the pointer directly from one tooltip's
    trigger to another's no longer re-incurs the full open delay (Reka's shared
    `delayDuration`/`skipDelayDuration` grouping — a runtime timing behavior a static/type/lint pass
    cannot exercise).
  - Nothing here turned out wrong against the ruling — both files' post-edit state matches
    `## Amendment` Ruling 1 exactly, and T6's criterion is understood as corrected by this task's own
    AC (one presentational class edit permitted in `DestinationRows.vue`, not zero-diff).
- **Task Planner correction note (2026-08-29), added post-review:** This task's Description and
  Testing section originally claimed that hoisting to a single `TooltipProvider` restores Reka's
  shared `delayDuration`/`skipDelayDuration` grouping, so that moving focus or the pointer directly
  from one tooltip's trigger to another's would no longer re-incur the full open delay. Review-17's
  re-review (`## Finding 2 (Minor) — RESOLVED, and my original rationale was wrong`) found that claim
  false: `resources/js/components/ui/tooltip/TooltipProvider.vue` sets `withDefaults(..., {
  delayDuration: 0 })`, so every provider in this codebase — including each of the four per-tooltip
  providers this task replaced — already opened its tooltips with no delay. There was never a shared
  delay to group between tooltips, and hoisting to one provider does not introduce one. The
  Description and Testing section above are corrected in place to state the hoist as a structural
  simplification only — one provider instead of four, fewer nodes, the shape the primitive is
  designed for — with no claim of a perceptible behaviour change; the Testing section's runtime
  timing check, which could neither pass nor fail because there is no timing difference to observe,
  is removed. The Senior Developer's completion notes above are left exactly as written, including
  the now-superseded "left for the manual/browser pass" bullet describing that same timing check —
  the code they describe is correct and shipped cleanly, only the benefit claimed for it was wrong.
  That outstanding manual timing check is withdrawn: there is nothing left for the manual pass to
  confirm on this point.

## T10 — Cap tooltip content width at the four call sites (Review-17 Finding 5, Major; `design-17` `## Amendment — tooltip content width cap`, 2026-08-29)

- **Description:** Rework task raised directly by the review gate against the already-shipped T1–T9
  feature. Review-17's re-review, driven headless at a 360px viewport (this design's own stated
  minimum supported width), measured every `TooltipContent` this feature added rendering wider than
  the viewport, with the overflow unreachable — `document.documentElement.scrollWidth` stayed 360px,
  so no gesture brings the clipped text into view. Measured: Mode 892px (532px/60% clipped), Backoff
  strategy 744px (384px/52% clipped), "Always hidden" 469px (109px/23% clipped), Response Body 431px
  (71px/16% clipped) — each against a 360px viewport, each with computed `max-width: none`. This
  matters beyond a cosmetic overflow because `design-17`'s `## Rule: form copy vs. tooltip vs. cut`
  moved these four sentences out of the form's wrapping `<p>` elements and into these tooltips on the
  strength of the tooltip carrying them; at 360px it did not, so on the form's own minimum supported
  width this feature was a net loss of information against the pre-#17 form.

  The Designer's amendment (`## Amendment — tooltip content width cap`, 2026-08-29) rules that
  `## Responsive Behavior`'s refusal of "bespoke width" yields a cap, and that the cap is
  `class="max-w-xs"` (320px), added at each `TooltipContent` call site — not in
  `resources/js/components/ui/tooltip/TooltipContent.vue`, which is generated code under
  `resources/js/components/ui/` and, per `docs/standards/coding.md` → Project structure, must never
  be hand-edited. `max-w-xs` is the identical class `ReplayDialog.vue` already carries; Reka UI's
  `TooltipContent` wrapper forwards a caller's `class` prop and merges it with its own `w-fit`, so the
  two compose rather than conflict — `w-fit` governs width below the cap, `max-w-xs` governs it above.
  The amendment's Ruling 2 withdraws N1's earlier rejection of that class (while keeping N1's separate
  and correct rejection of `ReplayDialog.vue`'s non-focusable `span` trigger), so this task is no
  longer barred by the note that previously blocked it.

  Add `class="max-w-xs"` to exactly the four `TooltipContent` elements this feature added in
  `resources/js/pages/proxies/ProxyForm.vue`: Response Body help (currently line 384), Mode help
  (currently line 438), Backoff strategy help (currently line 635), and Sensitive fields' "Always
  hidden" help (currently line 710). No other attribute, no wrapping element, and no line inside any
  of the four is touched.

- **Dependencies:** T9 (the shipped feature state this task reworks)
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - Each of the four `TooltipContent` elements (Response Body, Mode, Backoff strategy, Always hidden)
    carries `class="max-w-xs"` and no other class.
  - No copy string inside any of the four tooltips' `<p>` content changes.
  - No `aria-*` attribute, on any of the four `Tooltip`/`TooltipTrigger`/`TooltipContent` triples or
    their trigger `Button`s, changes.
  - No trigger markup changes: each trigger stays the same `TooltipTrigger as-child` wrapping the same
    `Button`, same `variant`, same `size`, same `aria-label`, same `Info` icon.
  - `resources/js/components/ui/tooltip/TooltipContent.vue` — the generated primitive — carries zero
    diff. This task's entire change surface is the four `class="max-w-xs"` additions in
    `ProxyForm.vue`; no other line of any file changes.
  - **Measured, not eyeballed**, per the Designer's amendment and Review-17's recommended resolution:
    at a 360px-wide viewport, each of the four `TooltipContent` elements, opened by keyboard focus on
    its trigger, has a rendered width that fits inside the viewport and a right edge
    (`getBoundingClientRect().right`) at or inside 360px — i.e. no greater than
    `document.documentElement.clientWidth`. The before-state, recorded here so the after-state is
    checkable against something rather than asserted on faith:

    | Tooltip | Before (unconstrained) | Viewport | Clipped |
    |---|---|---|---|
    | Mode | 892px | 360px | 532px — 60% |
    | Backoff strategy | 744px | 360px | 384px — 52% |
    | "Always hidden" | 469px | 360px | 109px — 23% |
    | Response Body | 431px | 360px | 71px — 16% |

    After `class="max-w-xs"` (320px) is applied, every one of the four is expected to render at or
    under its capped width, comfortably inside the 360px viewport with margin for the tooltip's own
    on-screen position — this is the after-state the manual pass below must confirm by the same
    measurement method, not by inspection.

- **Testing:** Frontend gates: `pnpm types:check` 0 errors, `pnpm format:check` clean, scoped
  `npx eslint resources/js/pages/proxies/ProxyForm.vue` 0 errors, and `pnpm build` (host — the sail
  container cannot run the Vite build). These gates catch a malformed class attribute or an
  unintended structural edit; they do not measure rendered pixel width, which is a runtime/DOM fact.

  **The 360px width measurement itself is a manual/browser-pass item, not something this session or
  the Senior Developer can complete** — it requires a real or emulated browser viewport, which this
  role does not have. It is called out explicitly here, not left implicit, because the Designer's
  amendment requires the re-check be measured rather than eyeballed. The manual pass must, for each of
  the four tooltips, open it via keyboard focus on its trigger at a 360px-wide viewport against a
  freshly host-built production bundle, then read `getBoundingClientRect()` (or the equivalent
  computed style) on its `[data-slot="tooltip-content"]` element and confirm: (1) rendered width
  no greater than 320px (the `max-w-xs` cap, allowing for the primitive's own `px-3` padding), and
  (2) `right <= 360` — no part of the box past the viewport's right edge. The pass must also confirm
  `document.documentElement.scrollWidth` still reads 360 (no new horizontal scroll introduced as a
  side effect of the cap) and that each tooltip still opens on Tab-focus and closes on blur/Escape,
  matching T9's already-verified trigger behaviour, which this task does not touch.

  No backend gate re-run: this task touches no PHP file, no migration, no controller, no request/
  resource class, and no backend-tested behaviour, so `composer lint`, `composer types:check`, and the
  backend test suite have no file in this task's scope to exercise.

- **Completion notes:** Done (2026-08-29). Added `class="max-w-xs"` to exactly the four
  `TooltipContent` elements this feature added in `ProxyForm.vue`: Response Body help (line 384),
  Mode help (line 438), Backoff strategy help (line 635), and Sensitive fields' "Always hidden" help
  (line 710) — confirmed by `git diff`: four one-line changes, each `<TooltipContent>` →
  `<TooltipContent class="max-w-xs">`, nothing else in the file touched. No copy string, no `aria-*`
  attribute, and no trigger markup changed on any of the four triples.
  `resources/js/components/ui/tooltip/TooltipContent.vue` carries zero diff, confirmed via `git diff
  --stat` returning empty for that file.
  - Gates: `pnpm types:check` 0 errors; `pnpm format:check` clean; scoped `npx eslint
    resources/js/pages/proxies/ProxyForm.vue` 0 errors; `pnpm build` (host) succeeds. No backend
    file touched, so no backend gate re-run, per this task's own Testing note.
  - **Left for the manual/browser pass** (no Playwright access in this session), per the task's own
    Testing section: at a 360px-wide viewport against a freshly host-built production bundle, open
    each of the four tooltips via keyboard focus on its trigger and read
    `getBoundingClientRect()` (or the equivalent computed style) on its
    `[data-slot="tooltip-content"]` element, confirming (1) rendered width no greater than 320px
    (the `max-w-xs` cap, allowing for the primitive's own `px-3` padding) and (2) `right <= 360` —
    no part of the box past the viewport's right edge — for all four (Mode, Backoff strategy,
    "Always hidden", Response Body), replacing the recorded before-state (892px/744px/469px/431px
    unconstrained, respectively). The pass must also confirm
    `document.documentElement.scrollWidth` still reads 360 (no new horizontal scroll introduced as a
    side effect of the cap) and that each tooltip still opens on Tab-focus and closes on
    blur/Escape, matching T9's already-verified trigger behaviour, which this task did not touch.
  - Nothing here turned out wrong against the ruling — all four call sites were bare
    `<TooltipContent>` with no existing class, matching the Designer's amendment and the task's own
    description exactly, so the change was the single mechanical edit specified.

## Handoff

- **Inputs:** `docs/design/design-17-proxy-form-information-architecture.md` (Approved, Product
  Manager, 2026-08-29, corrections C1–C5 landed, plus the `## Amendment — card-level legend heading
  weight ruling` and `## Amendment — tooltip content width cap` amendments, both 2026-08-29); the
  shipped `resources/js/pages/proxies/ProxyForm.vue`, `Create.vue`, `Edit.vue`,
  `resources/js/components/DestinationRows.vue` on this branch; `docs/tasks/README.md`;
  `docs/standards/planning.md`; `resources/js/pages/teams/Edit.vue` (the correct keyboard-focusable
  Tooltip precedent, as distinct from `ReplayDialog.vue`'s bare-`span`-trigger anti-pattern named at
  N1); `docs/reviews/review-17-proxy-form-information-architecture.md` (Finding 5, Major, and the
  re-review's correction to its own Finding 2 rationale).
- **Outputs:** this task plan.
- **Dependencies:** none, technical or otherwise — every control this plan's tasks build reuses an
  already-shipped primitive (`Card`, `fieldset`, `Tooltip`); no new dependency, no backend change.
- **Outstanding Questions:** none. `design-17` closed all four of its own; this plan raised none new —
  the design was specific enough on every point a Task Planner needed (grouping, exact copy, tooltip
  disposition, C1's two-fieldset ruling, C2's kept help line, C5's interpolation ruling) to break down
  without guessing.
- **Next Agent:** Senior Developer, to build T1 through T8 in order. T9 is a rework task added
  2026-08-29 in response to Review-17 Finding 1 (Major) and Finding 2 (Minor) and the Designer's
  card-level legend heading weight amendment; it is built after T1–T8's already-shipped work, not in
  that original sequence. T10 is a further rework task added 2026-08-29 in response to Review-17
  Finding 5 (Major) and the Designer's tooltip content width cap amendment; it is built after T9.
