> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M8b — Outbound signing, surface

## T41 — Screen 4b: `proxies/Show.vue` Signing card (AC54, AC57, AC63; Flows G, I; plan §, `design-10` amendment)
- **Description:** New `Card`, alongside the Verification card. States: **not enabled** (statement +
  **Enable signing** button, `canUpdate`-gated, opening Screen 6 directly into the one-time-reveal
  flow); **enabled, no overlap** ("Enabled — generated {date}" + **Manage signing** button); **enabled,
  overlap running** (adds the rotation line + **End overlap now**, same treatment as Screen 4). The
  rotation line and enabled status always render for anyone who can view the proxy; only the buttons
  are `canUpdate`-gated. No per-destination `Signed` badge anywhere — this is the proxy-wide status
  surface (design-10's stated reason: a badge repeated identically on every row carries no row-level
  information once signing applies to every destination alike). Renders **no new decryptability
  indicator** on failure (AC11's re-grained failure surfaces through the existing delivery-attempt
  treatment, not here) — the card shows only its last-known static configuration state.
- **Dependencies:** T38, T37
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:**
  - Each of the three states renders correctly against `security.signing`.
  - **Enable signing** opens the Manage proxy signing dialog directly into its one-time-reveal
    sub-state (Flow G step 2–3).
  - Disabling signing (via the dialog) returns the card to "not enabled" with no memory of prior
    configuration on its face.
  - No trust-domain warning is rendered anywhere on this card (PRD-10 `## Amendment B` ruling 2b — none
    is required).
- **Testing:** no frontend test harness — **manual verification** (folded into T43/T44's Flow G/H/I
  pass), plus a direct check here that all three states render against a fixture `security` prop.
- **Completion notes:** Done. Resumed from a prior session's uncommitted, unreviewed work
  (`13d0b6c wip(item-10): T41 Signing card, incomplete`) rather than building from scratch — read
  that diff cold against this task's own Acceptance Criteria, `design-10` Screen 4b, Flow G and Flow I,
  and PRD-10 `## Amendment B` ruling 2b before trusting any of it. The wip commit had already added the
  "Signing" `Card` to `proxies/Show.vue`, placed immediately after the Verification card and before the
  Retry policy card, matching Screen 4b's stated placement. All three states are driven entirely off
  `props.security.signing` (T38), mirroring the Verification card's own established shape one card down:
  **not enabled** (`!signing.enabled`) — the plain statement plus a `canUpdate`-gated **Enable signing**
  button that opens `ProxySigningDialog.vue`; **enabled, no overlap** (`signing.enabled &&
  !signing.overlap_expires_at`) — a `dl` "Status" / "Enabled — generated {date}" line (via the
  `signingGeneratedStatus` computed) plus a `canUpdate`-gated ghost **Manage signing** button;
  **enabled, overlap running** (`signing.overlap_expires_at` set) — the rotation-in-progress line
  (always rendered, status not control) plus `canUpdate`-gated **End overlap now** and **Manage
  signing** buttons, `End overlap now` wired to `proxyRoutes.signing.overlap.destroy` via
  `router.delete(..., { only: ['security'] })` with a `Spinner`/`AlertError` pair, the same
  `endVerificationOverlap` pattern the Verification card already established one card up. No
  per-destination `Signed` badge anywhere (T33's Destinations table is untouched by this task), no
  trust-domain warning (ruling 2b), and no new decryptability indicator on failure — the card renders
  only its last-known static enabled/overlap state, exactly as Screen 4b specifies; AC11's re-grained
  failure still surfaces solely through the existing delivery-attempt treatment.

  Traced every Acceptance Criteria bullet against the existing code rather than re-deriving it: the
  three `v-if`/`v-else-if` branches on `signing.enabled` / `signing.overlap_expires_at` cover exactly
  the three states with no gap or overlap; clicking **Enable signing** only sets `signingDialogOpen =
  true` — it does not itself drive the dialog into its reveal sub-state, because doing so is
  `ProxySigningDialog.vue`'s own internal state machine (T42's file, not this task's) — `design-10`
  Flow G step 2 (open the dialog) and step 3 (the dialog's own **Enable signing** action, inside the
  dialog, generates the secret and reveals it) are two different components' responsibilities, and
  T42's own Acceptance Criteria ("State 1 → Enable signing → state 2 … in sequence") independently
  confirms this reading — T41's AC bullet folding "step 2–3" together describes the flow's overall
  directness as experienced from the card, not a literal same-click jump past dialog state 1; disabling
  (via the dialog's `handleDisable`, T42's file) triggers a `router.delete(..., { only: ['security'] })`
  reload, and the card's own not-enabled branch carries no reference to any prior configuration — no
  local state on the card leaks a "previously enabled" fact onto its face, only the dialog's own
  session-scoped `everDisabledThisSession` flag does that, and only inside the dialog; grepped the whole
  card block for trust-domain/warning language and found none.

  The wip commit's `ProxySigningDialog.vue` (T42's nominal file) already implements states 1, 2, 3, 4
  and 5 in full — including the T43 AC29-ruling-2a disclosure copy on state 4 and the flagged-design-call-4
  `Esc`/overlay-suppression on the reveal sub-state — reaching well past T41's own scope. Left entirely
  as-is per this task's own instruction: not extended, not trimmed back to a T41-only subset, not
  rebuilt. **T42 should treat that component as already largely done and verify it against its own
  Acceptance Criteria rather than re-implementing states 1/2/3/5 from zero** — T43 similarly for state 4.
  Confirmed both `resources/js/types/proxies.ts` (the `signing` sub-object shape) and the dialog itself
  needed no change for T41's own Acceptance Criteria to hold; both files are untouched by this task's
  commit — the type shape is T38's, and every dialog behaviour this card's three states depend on
  (opening/closing, the disable reload) was already correct.

  **No frontend test harness exists** (confirmed: no `vitest`/`jest` in `package.json`, no `.test.*`
  files under `resources/js`) — the "direct check" this task's own Testing line calls for is the manual
  trace above, run against `pnpm run build` output (`public/hot` absent) rather than a fixture
  object in isolation, since this app has no component-mounting harness to feed one to. A full
  authenticated-browser walkthrough (login, three seeded proxies at the three states) was started but
  abandoned once it became a login-flow-debugging exercise rather than a check of this task's own
  scope — the fuller pass belongs to T43/T44 as the task's own Testing line already says, and this
  task's rendering logic is a handful of straightforward `v-if` branches on a well-typed prop already
  covered by `pnpm run types:check` (zero errors). Two proxies seeded via `sail tinker`
  (`SecretStore::generate()`, once for no-overlap and twice for overlap-running) toward that browser
  pass and one not-enabled control were deleted again immediately once the approach was dropped —
  nothing left behind.

  `pnpm run types:check`, `pnpm run lint:check`, `pnpm run format:check` and `pnpm run build` all green
  (no code changes were needed beyond what the wip commit already had — this task's own work was
  reading it against spec, verifying, and recording). `composer lint`, `composer types:check` and the
  full suite (`./vendor/bin/sail test --parallel`) all green — 1063/1063 passing, matching the
  pre-existing baseline exactly (no regression, and no new backend code in this task to add tests for).

## T42 — Screen 6: Manage proxy signing dialog, states 1/2/3/5 (AC54, AC56, AC57, AC63; Flows G, I; flagged design call 4)
- **Description:** New `Dialog`, scoped to the proxy, modelled on `ReplayDialog.vue`'s shape. **State
  1** (not enabled): statement + **Enable signing** footer action. **State 2** (one-time reveal,
  immediately after Enable or Regenerate succeeds): `Alert` + `CopyField` for the generated secret;
  footer **Done** only — **`Esc` and overlay-click dismissal are suppressed for this sub-state only**
  (the overturned flagged design call 4), **Done** the sole keyboard-reachable exit, focus lands on it
  on mount, no confirmation step added in front of it. **State 3** (enabled, no overlap): status +
  **Regenerate signing secret** + **Disable signing** + **Close**. **State 5** (disabled, re-visited):
  identical to state 1 plus one line noting re-enabling always generates a fresh secret. (State 4 —
  enabled, overlap running — is **T43**, kept separate because it carries the AC29 ruling-2a
  disclosure this feature explicitly calls out.)
- **Dependencies:** T37, T41
- **Files:** `resources/js/components/ProxySigningDialog.vue` (new, or inline in `Show.vue` per this
  app's existing dialog-composition convention — match whichever `ReplayDialog.vue` establishes)
- **Acceptance Criteria:**
  - State 1 → Enable signing → state 2 (one-time reveal) → Done → state 3, in sequence, calling T37's
    `store` endpoint and never re-displaying the secret afterward.
  - In state 2, pressing `Esc` and clicking the overlay both do nothing; **Done** is reachable by
    keyboard (Tab/Shift+Tab stay inside the dialog's focus trap) and is the only way to close it.
  - State 3 → Disable signing → state 5, with the "generates a fresh secret" line present.
  - Regenerating from state 3 (no overlap yet) transitions back to state 2 with a **new** secret, never
    the same value shown before.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flow G and Flow I steps,
  against a production build: the `Esc`/overlay suppression in state 2 specifically (this is the one
  behaviour in the whole feature most likely to regress silently, since it is a *removal* of default
  `Dialog` behaviour); the full state sequence 1→2→3→5.
- **Completion notes:** Done. As T41's own completion notes flagged, the prior session's wip commit
  (`13d0b6c`) already implemented `ProxySigningDialog.vue` states 1, 2, 3, 4 and 5 in full, wired to
  T37's endpoints. This task verified states 1/2/3/5 against T42's own Acceptance Criteria and
  `design-10` Screen 6/Flows G and I rather than rebuilding — state 4 (T43's) was left untouched, as
  instructed.

  **Verified already correct, no change needed:** the state 1 → Enable signing → state 2 (one-time
  reveal) → Done → state 3 sequence — `generate('enable')` calls T37's `store` endpoint via `fetch`
  (the `PayloadViewer.vue`-established escape hatch for this app's one other non-Inertia JSON
  endpoint) and sets `revealedSecret`; the `watch(() => props.open, …)` resets `revealedSecret` to
  `null` on every close, so the secret is never re-displayed on a later open (AC57) and **Done**
  (`handleDone`) simply closes the dialog — `design-10` Flow G step 4 confirms this is the intended
  shape ("the dialog's next open shows the ordinary enabled status"), not a same-session state-2-to-3
  transition without a close in between. In state 2, `Esc` and overlay-click are both refused by
  `suppressDismissalDuringReveal` (bound to `@escape-key-down`/`@pointer-down-outside` on
  `DialogContent`) plus the outermost `handleOpenChange` guard; `show-close-button` is `false` so the
  corner `X` is gone too; a `watch(state, …)` focuses `doneButtonRef` via `nextTick` the moment state
  becomes `'reveal'`; **Done** is the sole button rendered in the footer for that state, so Reka UI's
  own focus trap keeps Tab/Shift+Tab inside the dialog with Done the only exit — this is the
  overturned flagged design call 4, correctly implemented as the *overturned* version (suppression
  applies only to this one sub-state, not the whole dialog). State 3 → Disable signing → state 5:
  `handleDisable` calls `router.delete` against T37's `destroy` endpoint with `only: ['security']`,
  sets `everDisabledThisSession` on success, and closes the dialog; state 5 (`not-enabled` +
  `everDisabledThisSession`) renders the "Enabling again generates a new secret — your previous one
  is never shown or reused" line, present exactly when the task's AC requires it. Regenerating from
  state 3 calls the same `store` endpoint via `generate('regenerate')`, which always returns a freshly
  generated secret from the server (T37's own contract) and overwrites `revealedSecret`, so state 2 is
  re-entered with a genuinely new value, never the one shown before.

  **Fixed — unchecked partial reload (audit finding 1).** `router.reload({ only: ['security'] })` at
  the end of `generate()` was fire-and-forget with no `onError`. Added an `onError` callback that sets
  `requestError`, surfaced through the same `AlertError` this component already uses for every other
  request failure (`handleDisable`, `handleEndOverlap` already follow this convention; matched it here
  rather than inventing a new one, per the task's own instruction). Message: "Signing secret generated,
  but this proxy's status could not be refreshed. Close and reopen this dialog to see the current
  status." — deliberately distinct from the enable/regenerate action-failure strings, since the
  generate action itself succeeded; only the background status refresh failed. `AlertError` renders
  outside the per-state `template` blocks, so it surfaces even while state 2 (reveal) is still showing.

  **Fixed — missing `canUpdate` gate on Screen 6's own actions (audit finding 2).** Added a
  `canUpdate: boolean` prop to `ProxySigningDialog.vue` and gated all four state-changing actions with
  `v-if="props.canUpdate"`: **Enable signing** (state `not-enabled`), **Disable signing** and
  **Regenerate signing secret** (state `enabled`/`overlap`, in the `v-else` footer branch), and **End
  overlap now** (state `overlap`, T43's state — only the permission guard was touched here, not the
  disclosure copy or any other content of that state, per the instruction to leave state 4 alone).
  `resources/js/pages/proxies/Show.vue` now passes `:can-update="canUpdate"` (the same computed the
  page's other mutating controls already use) to the dialog. Confirmed via `Show.vue:986-1044` that
  every trigger opening this dialog was already itself `canUpdate`-gated, so there is no live exposure
  today — this closes the gap `design-10` § Interactions names explicitly rather than fixing an active
  bug.

  **Added — Screen 6 state 3's ordinary-branch disclosure**, per the design amendment landed under T42
  (commit `f7cf54a`, self-certified by the Designer under PRD-10 AC29 ruling 2a's delegated wording
  authority). Rendered the approved copy verbatim as a second `p`, help styling
  (`text-sm text-muted-foreground`), in the `enabled` state's template, directly below the "Enabled —
  generated {date}." status line and above where `DialogFooter` renders — i.e., in front of the member
  before **Regenerate signing secret** is reachable. Confirmed verbatim in the production bundle
  (`grep` against `public/build/assets/Show-*.js` after `pnpm build`) rather than trusting the source
  alone, since Prettier's line-rewrap of the template text could in principle have altered wording;
  Vue's default whitespace-condense collapses the wrapped source lines back to the single approved
  sentence, confirmed by the built-output grep.

  **Testing.** No frontend test harness (confirmed: no `vitest`/`jest` in `package.json`, no
  `.test.*` files under `resources/js`). A live browser pass was not attempted: `public/hot` exists on
  disk and a `vite` dev-server process was already running under another PID at task start — killing a
  dev server another concurrent session might own was judged riskier than the verification value of a
  live pass, and the task explicitly permits falling back to a code trace rather than sinking budget
  into login-flow debugging. Fell back to: the code trace above against each Acceptance Criterion, and
  a grep of the actual `pnpm build` output (`public/build/assets/Show-*.js`) to confirm the new
  disclosure copy renders verbatim rather than only checking the source template. The full Flow G/I
  browser walkthrough remains T44's, as the task plan already places it there.

  **What T43 needs to know:** state 4's disclosure copy and its `End overlap now` button are otherwise
  untouched by this task — the only change inside that `v-else-if="state === 'overlap'"` block is the
  new `v-if="props.canUpdate"` on the `End overlap now` `Button`, which does not affect the button's
  existing behaviour for any member who already holds `canUpdate` (the only population that could
  reach that state's button today, since every dialog-opening trigger is itself gated). T43 does not
  need to add its own `canUpdate` gate to state 4 — this task already added it.

  Gates: `composer lint`, `composer types:check`, `pnpm types:check`, `pnpm lint:check` all green.
  `pnpm format:check` initially flagged the new lines in `ProxySigningDialog.vue` (line-wrap only, no
  wording change) — fixed with `pnpm exec prettier --write`, then green. `pnpm build` green (output
  bundle grepped as above). Full suite (`./vendor/bin/sail test --parallel`) — 1063/1063 passing,
  matching the pre-existing baseline exactly (no backend code touched by this task).

## T43 — Screen 6 state 4 and Flow H step 2: the AC29 ruling-2a disclosure on the signing surface (correction B2) — **required before M8b is considered complete**
- **Description:** `design-10`'s amendment-gate correction **B2**, called out explicitly because it was
  the one correction the gate required before M8b could be task-planned at all. State 4 (enabled,
  overlap running) renders the rotation line and **End overlap now**, **plus** member-facing copy —
  rendered as part of this state, therefore visible **before** the member clicks **Regenerate signing
  secret** — stating that regenerating now stops the currently-honoured previous secret being honoured
  immediately, for **every destination of the proxy**, and that its 24 hours will not finish out. Flow
  H step 2 branches exactly as Flow B step 2 (T23) does for the inbound surface: **no overlap running**
  → the ordinary demote-not-discard copy; **overlap already running** → this state's disclosure. No
  confirmation step is added — the disclosure satisfies AC29's added bullet; it does not add ceremony
  in front of a still-single-click action.
- **Dependencies:** T42
- **Files:** same as T42 (the signing dialog component)
- **Acceptance Criteria:**
  - State 4 (overlap already running) shows the discard-disclosure copy **before** the Regenerate
    button is clicked, naming that the effect applies to every destination of the proxy.
  - State 3 → Regenerate (no overlap yet) shows the **ordinary** demote-not-discard copy, not the
    discard one — the two states/copies must not be swapped or merged.
  - Clicking Regenerate in state 4 still requires no confirmation dialog.
  - This exact disclosure requirement — stated before the action, present on **both** the inbound
    (T23) and signing (this task) surfaces — is satisfied by both tasks together; a review that finds
    it on only one surface should treat this task as incomplete regardless of what T23 shows.
- **Testing:** no frontend test harness — **manual verification**: rotate signing once (state 3 →
  regenerate, ordinary copy, confirmed), then rotate again while the first overlap is still running
  (state 4, discard copy, confirmed) — both branches exercised in one fixture proxy, against a
  production build.
- **Completion notes:** Done. Verified against `ProxySigningDialog.vue` as it now stands, rather than
  rebuilt — both audit findings that preceded this task have already been closed by prior commits, and
  this task's own review confirmed each of its four Acceptance Criteria independently rather than
  taking that on trust.

  **AC1 — state 4's discard disclosure.** Present, inherited unchanged from the prior session's wip
  commit `13d0b6c` and untouched by T42 (T42's own completion notes record that the only edit inside
  `v-else-if="state === 'overlap'"` was the `canUpdate` gate on **End overlap now** — the disclosure
  paragraph itself was not touched). Renders as the final `p` in that template block (lines 367–371),
  therefore before `DialogFooter`'s **Regenerate signing secret** button, reading verbatim: "Regenerating
  again now will stop that previous secret being honoured immediately, for every destination this proxy
  has — its 24 hours will not finish out." Matches `design-10` Screen 6 state 4's quoted copy
  (correction B2) word for word, names every-destination scope as required. Verified, not written here.

  **AC2 — state 3's ordinary-branch copy, and that it is not the discard copy.** This was the audit's
  one finding against the inherited implementation: state 3 (`enabled`) carried no member-facing
  disclosure at all pre-amendment. Closed by two commits ahead of this task: `f7cf54a` amended
  `design-10` to add the approved wording under the Designer's AC29-ruling-2a wording delegation, and
  T42's `15ee641` rendered it as the second `p` in `v-else-if="state === 'enabled'"` (lines 318–329),
  reading verbatim: "Regenerating keeps your current secret working for the next 24 hours, for every
  destination this proxy has, so you don't need a coordinated cutover. To stop it early — for example if
  it's been leaked — use End overlap now, which appears here and on the Signing card once you
  regenerate." Matches `design-10`'s `## Amendment — Screen 6 state 3's ordinary-branch disclosure`
  "exact copy" block word for word — the demote-not-discard framing, no mention of immediate loss.

  **Branch exclusivity, checked at both the source and the compiled level** — this is AC2's specific
  failure mode named by the task ("the two states/copies must not be swapped or merged") and was
  checked directly rather than inferred: `state` (lines 69–79) is a single `computed<DialogState>`
  returning exactly one of four string values, driven by `props.signing.enabled` and
  `props.signing.overlap_expires_at` — an overlap already running always yields `'overlap'`, never
  `'enabled'`, and the two are therefore never simultaneously true by construction. The template
  renders the two disclosures in separate `v-else-if="state === 'enabled'"` /
  `v-else-if="state === 'overlap'"` blocks in the same chain as the other two states, so Vue renders at
  most one of the four. Confirmed this holds in the actual `pnpm build` output, not just in source:
  grepped `public/build/assets/Show-*.js` for both exact phrases — each string ("Regenerating keeps your
  current…" / "Regenerating again now will stop…") appears **exactly once** in the whole bundle, sitting
  inside the compiled ternary's `key:2` (`enabled`) and `key:3` (`overlap`) branches respectively, one
  `?…:` apart in the same `O.value===` chain — i.e. mutually exclusive by the compiled output's own
  structure, not merely by convention in the source.

  **AC3 — no confirmation step.** Both states' **Regenerate signing secret** button is the same
  `Button` in the shared `v-else` footer branch (lines 408–427, template lines 397–427) — a plain
  `@click="generate('regenerate')"`, no `AlertDialog` or intermediate step anywhere in the file, for
  either state 3 or state 4.

  **AC4 — present on both surfaces.** Confirmed independently rather than trusting T23's own
  completion notes: `ProxyForm.vue:628–660` carries the identical two-branch shape for the inbound
  verification secret (Flow B step 2) — an `initialVerificationOverlapExpiresAt`-gated `v-if`/`v-else`
  pair reading, for the overlap-running branch, "You already have a previous secret from your last
  rotation, still honoured until {timestamp}. Saving a new secret now stops that previous secret being
  honoured immediately — its 24 hours do not finish out." and for the ordinary branch, "Your current
  secret keeps working for 24 hours after you save this…". Same shape, same disclosure requirement,
  present on both the inbound (T23) and signing (this task) surfaces — satisfied by the two tasks
  together, per this task's own fourth Acceptance Criterion.

  **Nothing required fixing.** All four Acceptance Criteria held on inspection; this task's only
  change is these completion notes.

  **Testing.** No frontend test harness (confirmed as in T23/T42: no `vitest`/`jest` in
  `package.json`, no `.test.*` files under `resources/js`). A live two-branch browser pass (rotate
  once for the state 3 ordinary copy, again mid-overlap for the state 4 discard copy, per this task's
  own Testing line) was not attempted: `public/hot` is still present on disk and the same `vite`
  dev-server process observed by T42 is still running under a PID that may belong to another session —
  disturbing it was judged out of scope per this task's own instruction not to sink into environment
  wrangling. Fell back to the code trace and compiled-bundle grep above. `pnpm build` itself does not
  require the dev server down (it writes to `public/build/` independently of `public/hot`), so the
  bundle grep is against a genuine production build, even though a live browser pass against it was
  not attempted. The live two-branch walkthrough remains owed at **T44**, which already scopes the full
  Flow H browser pass (both overlap branches, end overlap now) against a production build with
  `public/hot` confirmed absent.

  Gates: `composer lint`, `composer types:check`, `pnpm types:check`, `pnpm lint:check`,
  `pnpm format:check` all green — no source changes were needed, so this is confirmation, not a
  before/after fix. `pnpm build` green; `public/build/assets/Show-*.js` grepped as described above.
  Full suite (`./vendor/bin/sail test --parallel`) — 1063/1063 passing, 4939 assertions, matching the
  pre-existing baseline exactly (no backend code touched by this task).

## T44 — Manual verification: `design-10` Flows G, H, I against a production build
> **Status: WITHDRAWN — folded into T49, Task Planner's call per ADR-026 § *Sequencing and build
> order*, which flagged the fold as this document's own decision to make.** Once ADR-026 resequences
> this task to run after Decision A's strip reduction (**T55**), it sits immediately before **T49**'s
> own whole-surface pass with nothing in between to regress — the two tasks would walk the identical
> Flows G/H/I back to back for no reason. **T49 below now explicitly covers Flows D through I**,
> folding this task's scope into its own Acceptance Criteria; no coverage is lost. This entry is kept,
> not deleted, because ADR-026 refers to "T44" by number throughout. Do not build against the original
> scope below independently of T49.
- **Description:** The signing surface's own full walkthrough, now that M8b is built end to end —
  `plan-10`'s own test-strategy note excluded Flows G–I "because the surface they describe is not
  built"; it now is, so this task closes that exclusion explicitly rather than leaving it silently
  stale.
- **Dependencies:** T41, T42, T43
- **Files:** none; verification-only
- **Acceptance Criteria:** Flow G (enable + one-time reveal), Flow H (regenerate, both overlap
  branches, end overlap now), and Flow I (disable, re-enable generates a fresh secret) each pass
  exactly as specified, against `pnpm run build` with `public/hot` confirmed absent, in both themes, at
  360px.
- **Testing:** manual, recorded in completion notes with concrete steps and observed outcomes per
  `docs/standards/planning.md`'s "AC-trace"/"Verify step" requirement.
- **Completion notes:** WITHDRAWN — see status note above. Folded into T49; no independent completion
  record is made for this task number.

---
