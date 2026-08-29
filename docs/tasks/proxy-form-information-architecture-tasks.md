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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
  - `resources/js/components/DestinationRows.vue` has zero diff from its currently shipped content.
  - `id="destinations-help"` is present exactly once, on the fieldset's help paragraph, and every
    destination row's URL `input` still points at it via `aria-describedby="destinations-help"` when
    that row has no error.
  - The `InputError` for `form.errors.destinations` (rendered outside `<DestinationRows>`) is
    unchanged.
- **Testing:** pnpm gates green. Manual verification (host production build): adding/removing a
  destination row, and replacing/removing a row's credential, all behave exactly as before; a
  deliberately invalid destination URL still swaps that row's `aria-describedby` to its own error id
  instead of `destinations-help`.
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

## Handoff

- **Inputs:** `docs/design/design-17-proxy-form-information-architecture.md` (Approved, Product
  Manager, 2026-08-29, corrections C1–C5 landed); the shipped
  `resources/js/pages/proxies/ProxyForm.vue`, `Create.vue`, `Edit.vue`,
  `resources/js/components/DestinationRows.vue` on this branch; `docs/tasks/README.md`;
  `docs/standards/planning.md`; `resources/js/pages/teams/Edit.vue` (the correct keyboard-focusable
  Tooltip precedent, as distinct from `ReplayDialog.vue`'s bare-`span`-trigger anti-pattern named at
  N1).
- **Outputs:** this task plan.
- **Dependencies:** none, technical or otherwise — every control this plan's tasks build reuses an
  already-shipped primitive (`Card`, `fieldset`, `Tooltip`); no new dependency, no backend change.
- **Outstanding Questions:** none. `design-17` closed all four of its own; this plan raised none new —
  the design was specific enough on every point a Task Planner needed (grouping, exact copy, tooltip
  disposition, C1's two-fieldset ruling, C2's kept help line, C5's interpolation ruling) to break down
  without guessing.
- **Next Agent:** Senior Developer, to build T1 through T8 in order.
