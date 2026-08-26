# Review: Enhanced-mode toggle — item #7

- **Reviewer / date:** Reviewer, 2026-08-26
- **Scope:** `feat/item-07-enhanced-mode-toggle` at `415dad1`, diff `f72153f..HEAD` —
  13 task commits (T1–T13), 27 files, +2060/−190 (the task plan's stated `1999/189` was
  captured before T13's own docs commit; the delta is that commit alone, and no source file
  is affected). No migration.
- **Inputs verified:** `docs/product/prd-07-enhanced-mode-toggle.md` (Approved, Owner
  2026-08-21, incl. Amendments A and B) · `docs/design/design-07-enhanced-mode-toggle.md`
  (Approved, Product Manager 2026-08-25, approval note governing) ·
  `docs/plans/plan-07-enhanced-mode-toggle.md` (Approved, PE self-certified) ·
  `docs/tasks/enhanced-mode-toggle-tasks.md` (T1–T13, all with completion notes) ·
  `docs/architecture/adr-018-mode-gated-resolution-of-enhanced-configuration.md` (Accepted) ·
  `docs/reviews/review-06-retry-replay.md` (Minors 5, 8, 9; Ruling 2) · `docs/standards/`.

## Gate results (run by the Reviewer, not taken from the notes)

| Gate | Command | Result |
|---|---|---|
| Backend suite | `./vendor/bin/sail test --parallel` | **759 passed, 2820 assertions** — exactly as claimed |
| Code style | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` (L7) |
| TS types | `pnpm types:check` | clean |
| JS lint | `pnpm lint:check` | clean, repo-wide (the worktree pollution T6 documented is gone) |
| Format | `pnpm format:check` | clean |
| Bundle | `pnpm run build` | built in 960 ms |
| Live browser | Playwright, headless, against a fresh build | see Findings 1 and 3 |

Every reported number reproduces. **All gates green.**

## Summary

The core mechanism is correct and well built. ADR-018's resolution-time gate lands exactly
where the ADR puts it, every consumer inherits it with no branch of its own, the read-surface
suppression and the single Amendment-A carve-out are structurally enforced rather than merely
intended, and all five of the task plan's load-bearing invariants hold when re-derived
independently. Test quality is high: the headline AC14(a) case asserts *identity* across two
proxies rather than inferring it, and the mid-flight downgrade/upgrade cases exercise real
attempt schedules rather than mocking the resolver. Review-06's three carried obligations are
each genuinely discharged.

One **Major** surfaced that no test and no manual-verification step covered, reproduced live:
the new downgrade disclosure tells a member their saved retry policy "applies again, with the
same values, if you turn Enhanced back on" — and on the very path design-07 Flow C step 3 names
("Changes mind"), turning Enhanced back on inside the same form leaves the fields blank and the
next save **silently destroys the persisted policy**. This is not an implementation deviation —
the implementer built plan §Technical ruling 4 exactly as written — but ruling 4 mischaracterises
the lost values as "in-session typed values", and my reproduction shows mount-seeded persisted
values are lost too. Plus four Minors and three Nits.

## AC coverage (PRD-07, AC1–AC25)

| AC | How verified | Verdict |
|---|---|---|
| AC1 mode changeable after creation | `ModeSwitchSafetyAcceptanceTest::test_switching_mode_does_not_recreate_the_proxy_its_destinations_or_its_ingest_url` | ✅ |
| AC2 settable at create and edit, one attribute | `test_no_separate_mode_change_workflow_exists` (pins absence of `proxies.mode`/`toggle-mode`/`switch-mode`) | ✅ |
| AC3 Simple is the default | `test_simple_is_the_database_default_...` — asserts against a bare `new Proxy()->save()`, not the factory. Correct choice | ✅ |
| AC4 existing proxies untouched | Inspection + positive proof: `git diff f72153f..HEAD -- database/migrations/` is empty; re-derived, holds | ✅ |
| AC5 permission-gated, no new permission | `test_a_member_without_update_permission_cannot_change_a_proxys_mode_but_an_authorized_member_can`; `test_no_new_team_permission_was_added` pins the 14-case list | ✅ |
| AC6(a) dispatched-output store gated | `test_switching_simple_to_enhanced_composes_capture_for_the_next_event_only`; `PipelineFactory` untouched (0 diff lines) | ✅ |
| AC6(b) retry configurability gated | `RetryPolicyTest` gate cases + `ModeGatedRetryInheritanceAcceptanceTest` (real attempt schedule through `DeliverToDestination`) | ✅ |
| AC7 mode-independent stays so | Re-derived: `grep ProxyMode::` over `app/` returns exactly the two ADR-018 evaluation points (`PipelineFactory:28,42`; `RetryPolicy:48,59`) plus cast/validation/controller-write CRUD. No new gate | ✅ |
| AC8 not an entitlement | Inspection — no plan/tier/quota check anywhere in the diff | ✅ |
| AC9 current mode governs | `PipelineFactory` composes from the live row loaded at processing; nothing snapshots mode (verified by the same grep) | ✅ |
| AC10 no loss/error/duplication in flight | `test_a_downgrade_with_a_held_fifo_line_and_a_pending_sibling_advances_without_loss`; `test_a_redelivery_straddling_a_switch_never_duplicates_or_errors` | ✅ |
| AC11 mixed treatment normal | Satisfied by absence — no surface displays a per-event mode; confirmed by inspection of the events surfaces | ✅ |
| AC12 truthful presentation | Help text verbatim per design-07; read surfaces suppressed (server-tested). **Partially compromised on one path — Finding 1** | ⚠️ |
| AC13 downgrade erases nothing + disclosed | `test_an_expired_events_output_captured_under_enhanced_is_erased_normally_after_a_downgrade`; `test_a_replay_on_a_now_simple_proxy_neither_writes_nor_deletes...`; disclosure bullet 2 | ✅ |
| AC14 lead — restoration on upgrade | Server half: `test_upgrading_resubmits_the_preserved_values_and_they_persist_unedited`. Client half was **untested and unverified by anyone** — I verified it live: a Simple proxy holding 4/`fixed` renders nothing while Simple, and prefills `4` / `Fixed interval` the moment Enhanced is selected | ✅ (verified by Reviewer) |
| AC14(a) Simple always resolves the default | `RetryPolicyTest::test_a_simple_proxy_with_a_dormant_policy_resolves_identically_to_one_that_never_had_a_policy` | ✅ |
| AC14(b) no dormant value on a read surface | `ProxyRetryFieldPresentationAcceptanceTest` (Index, Show, events index, events detail) + the rewritten `ProxyIndexShowTest` case | ✅ |
| AC14(b)(i)–(iv) form carve-out | (i)(iv) server-tested; (ii)(iii) verified live by me — see AC14 row | ✅ |
| AC14(c) disclosure states preservation | Disclosure renders verbatim. **Its promise is falsified on one path — Finding 1** | ⚠️ |
| AC15 two axes independent | Mode help text mentions no ordering/throughput; Processing help text still states independence | ✅ |
| AC16 Show states mode meaning | Caption verbatim per design-07 Screen 2(a), under the header, no new card (Amendment B) | ✅ |
| AC17 switching unrestricted | Inspection — no rule on `mode`, no in-flight predicate anywhere; compliance is the absence | ✅ |
| AC18 extensibility | `PipelineFactory` byte-identical, reserved `#8`/`#9`/`#12` comments intact | ✅ |
| AC19–AC25 scope boundaries | Inspection over the whole diff — no mapping, no storage/retention change, no retry value/cap change, no processing change, still exactly two `SelectItem`s, no audit surface, no numeric target | ✅ |

Inspection-only ACs (AC4, AC7, AC8, AC15, AC18, AC19, AC22–AC25) were re-derived from the diff
rather than accepted as self-evident; each absence genuinely holds.

## Load-bearing invariants — re-derived independently

| Invariant | Method | Result |
|---|---|---|
| Exactly three readers of the two raw retry columns | `grep -rn "retry_attempt_limit\|retry_backoff_strategy" app/` | **Holds.** `RetryPolicy.php:48`, `:59`, `ProxyFormResource.php:36-37`. Every other hit is a `$fillable`/`@property`/cast declaration, a validation-rule key, a `$data[...]` read of validated *request input*, or `ProxyResource` delegating to the resolver. No fourth reader |
| `ProxyFormResource` has exactly one caller | grep over `app/` + `resources/js/` | **Holds** — `ProxyController.php:146`, inside `edit()`. Also pinned by a Finder-based test that fails on any new caller anywhere in `app/` |
| `RetryPolicy` sole reader of all seven `retry.*` keys | grep + cross-check against `config/retry.php`'s seven keys and the nine `positiveConfigInt()` call sites | **Holds.** The only executable read is `config("retry.{$key}")` inside `positiveConfigInt()`; all seven keys route through it. `SweepDueRetries` has no `config(` call at all |
| `PipelineFactory` untouched | `git diff f72153f..HEAD -- app/Pipeline/PipelineFactory.php` | **Holds** — zero diff lines |
| Excluded Senior-Developer items absent | `git diff` on `DeliverToDestination.php` and `bootstrap/app.php` | **Holds** — zero diff lines each. Both fixes are present on `main` independently (`bootstrap/app.php:36` calls `trustProxies()`; `DeliverToDestination.php:197` loads the proxy `withTrashed()`) |

## Review-06's three carried obligations

| Obligation | Discharged? | Evidence |
|---|---|---|
| **Minor 8** — (a) stop clearing **and** (b) mode-gate the resolver, **in one task**; (c) invert *and rename* the T30 test + add Show-page suppression | **Yes, all three.** | (a)+(b) both land in commit `40f4982` (T1) — a single commit, verified by `git show`. (c) `test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null` → `..._preserves_stored_values`, and the previously-unnamed second instance in `ProxyUpdateTest` renamed the same way; Show-page suppression covered server-side by `ProxyRetryFieldPresentationAcceptanceTest`. Note that Minor 8(a)'s *second half* (relax `prohibited_if`) was deliberately **declined** at plan §Technical ruling 2 and ADR-018 Decision 3 — I concur; relaxing it would open the one write path that could overwrite an invisible dormant value |
| **Minor 9** — guard `retry.sweep_grace_seconds` | **Yes.** | `RetryPolicy::sweepGraceSeconds()` returns `positiveConfigInt('sweep_grace_seconds')`; `SweepDueRetries` calls it; the seventh key now sits inside the same seam as the other six. Blank/zero/negative/non-numeric all covered by new `RetryPolicyTest` cases plus a `SweepDueRetriesTest` regression proving the throw happens *instead of* sweeping |
| **Minor 5** — `DeliveryResource.created_at`, its consumer, and the inverted pin **as one task** | **Yes.** | All three in commit `331f30f` (T12) — the resource field, `events/Show.vue`'s label + ordering switch, and `ReadSurfaceRevealAcceptanceTest.php:95` inverted from `->missing(...)` to a presence assertion. The `sortId` derivation is fully removed, including from the `DeliveryGroup` interface |
| **Ruling 2** — "(a) without (b) is a defect" | **Honoured.** | Single commit, single tree; no intermediate state ever existed where the columns were preserved but the resolver ungated |

Both indivisible-commit requirements (T1 and T12) were genuinely met — verified by `git show`,
not by the completion notes' claim.

## Manual-verification claims — checked, not accepted

| Claim | Verdict |
|---|---|
| T7: `#mode` carries `aria-describedby="mode-help mode-error"` and matches `#processing_mode` attribute-for-attribute | **Holds.** Verified live against a fresh build: `#mode` → `aria-describedby="mode-help mode-error"`, `aria-invalid` absent (no error), `role="combobox"`; `#processing_mode` → the identical pattern. `#mode-help`/`#mode-error` both exist. The accessible name "Mode" computes (one `getByRole('combobox', { name: 'Mode' })` match) — this is a Reka `SelectTrigger`, the class of primitive that broke at review-06 M-3, so I checked the computed name rather than the markup |
| T8: the disclosure appears on downgrade, has three bullets, does not gate Save, disappears on switching back | **Holds** — reproduced live, `[role="alert"]` count 1 → 0 on toggling back |
| T8: Flow B "the disclosure never appears on upgrade" | **Holds** — reproduced |
| T9: a Simple-mode save sends `null` for both retry fields despite dormant state, and the persisted values survive | **Holds** — the PUT body and the resulting DB state both reproduce |
| T10: Simple-with-dormant and Simple-never-configured render identical caption and identical card values | **Holds** — now also guaranteed server-side by test, which is stronger than the manual check |
| T12: a zero-attempt replay group shows a real time label and groups sort by `created_at`, not `Delivery.id` | **Holds** by construction — `sortId` is gone from both the computed and the interface; ordering is `localeCompare` over ISO `created_at`, and `groupLabel()`'s null path is now only reachable for legacy rows, which are always `kind: 'original'` |
| T13: "27 files changed, 1999 insertions(+), 189 deletions(-)" | **Stale by one commit** — the true figure at `415dad1` is 2060/190. The delta is T13's own docs commit; no source file differs. Cosmetic |
| T1: "`ProxyController::store()`/`update()` now omit both retry keys from the write array entirely unless the submitted mode is Enhanced" | **False for `store()`** — see Finding 2 |

**One manual-verification gap, not a false claim:** design-07 Flow B **step 3** — the
dormant-value restore prefill, which is the whole of AC14's "without the member re-entering
anything" on the client — was never scheduled for manual verification (T8's Testing section
names Flow B only as "the disclosure never appears") and has no test. I verified it live; it
works. Recording it so the coverage gap is on the record even though the outcome is good.

## Findings

| # | Severity | Location | Finding |
|---|---|---|---|
| 1 | **Major** | `resources/js/pages/proxies/ProxyForm.vue:90-95` (the `watch(isEnhanced, …)` clearing effect) interacting with the new disclosure at `:239-285`; plan-07 §Technical ruling 4 | An **abandoned** downgrade silently destroys the persisted retry policy, immediately after the new disclosure promises the opposite |
| 2 | Minor | `app/Http/Controllers/ProxyController.php:78-91` | `store()`'s comment and T1's completion note both state that a Simple-mode create "never writes either column, not a value, not NULL". It does write both, as NULL |
| 3 | Minor | `docs/tasks/enhanced-mode-toggle-tasks.md` T8 §Testing | No task scheduled a manual verification of design-07 Flow B **step 3**, the AC14 restore prefill — the one client-side behaviour AC14's lead sentence turns on |
| 4 | Minor | `plan-07` §Test strategy vs §Architecture C | The plan's Test strategy asks for "no proxy retry-column **key** at all" on non-Edit responses; §Architecture C requires the keys to stay in the payload. The implementation follows Architecture C (asserts the keys are `null`), which is right — the plan contradicts itself |
| 5 | Nit | `resources/js/pages/proxies/ProxyForm.vue:262-270` | The disclosure renders "(5 attempts, **E**xponential)"; design-07's approved copy reads "(5 attempts, exponential)" and plan-07 promised "the rendered string is identical to the approved copy" |
| 6 | Nit | `resources/js/pages/proxies/ProxyForm.vue:327-333` | The Retry policy fieldset's help text still hard-codes "(5 attempts, exponential backoff)" — pre-existing from #6, and the one remaining hand-written copy of the default the new disclosure was careful to derive |
| 7 | Nit | `tests/Feature/Proxies/ProxyRetryFieldPresentationAcceptanceTest.php:64-70` | The Index assertion indexes `proxies.data.0`/`.1` without pinning which proxy is which. Sound here (both are Simple, both must be `null`), but it would not survive a fixture reorder |

### Finding 1 (Major) — an abandoned downgrade destroys the policy the disclosure just promised to keep

*Criterion:* PRD-07 **AC14(c)** (the disclosure must state that saved retry configuration "applies
again, with the same values, if Enhanced is turned back on"), **AC12**'s write-surface clause
("must never show one thing while the save does another"), and the **Owner's Q-07-01(b)
rationale** — preservation was chosen so that "an accidental downgrade must not silently destroy
tuned configuration."

*Reproduction* (headless Chromium, real login, against a freshly built bundle; fixtures seeded and
removed afterwards):

1. Seed an Enhanced proxy persisting `retry_attempt_limit = 4`, `retry_backoff_strategy = fixed`.
2. Open its Edit page. The Retry policy fieldset reads `4` / `Fixed interval`. ✅
3. Select **Mode = Simple**. The disclosure appears; bullet 3 reads *"Any retry configuration
   you've saved for this proxy is kept but stops applying while it's Simple — the system default
   (5 attempts, Exponential) governs meanwhile. **It applies again, with the same values, if you
   turn Enhanced back on.**"* The fieldset unmounts and `watch(isEnhanced, …)` clears both fields.
4. Change your mind — select **Mode = Enhanced** again. This is design-07 Flow C step 3 verbatim
   ("Changes mind: switches Mode back to Enhanced before saving"). The disclosure disappears. The
   fieldset re-renders **blank**: `attempts = ""`, `backoff = "Default (Exponential)"`.
5. Click **Save changes**. Observed PUT body:
   `{"name":"RV Enhanced","mode":"enhanced",…,"retry_attempt_limit":null,"retry_backoff_strategy":null,…}`
6. Post-save DB state: `{"id":14,"mode":"enhanced","limit":null,"strategy":null}` — **the persisted
   `4`/`fixed` is gone.**

The member was told, seconds earlier and by this feature's own new copy, that those values would
survive; they then took the reversal action the design spec names, and lost them without a warning,
a confirmation, or any visible cue beyond two fields quietly reading "Default".

*Why this is not the implementer's defect.* `ProxyForm.vue` implements plan-07 §Technical ruling 4
exactly, and that ruling forbids "fixing" the asymmetry. But ruling 4 describes the lost values as
*"in-session **typed** values"* — the reproduction above types nothing. The values lost are
**mount-seeded, persisted** ones, a case ruling 4 never considers and the PRD's AC14 closing
paragraph (which likewise says "values **typed** in the current session") also never considers.
design-06 Flow F's stated purpose — "so no stale value can ever be submitted for a **simple-mode**
proxy" — is served entirely by T9's submit normalisation now; the clearing watcher is no longer
load-bearing for it.

*Why Major and not Blocker.* No acceptance criterion's literal text is breached: the proxy is never
*saved* as Simple, so AC14's persistence promise (downgrade-save → upgrade-save) is not engaged, and
at the instant of Save the form and the write agree. The clearing behaviour itself is pre-existing
from #6 and unchanged in code by #7. What #7 adds is the copy that makes it a broken promise, and
the preservation model that makes it anomalous — under #6 a downgrade save cleared too, so the
in-form round trip was consistent.

*Why Major and not Minor.* It is silent, reproducible destruction of exactly the state this
feature's headline mechanism exists to protect, reached from the affordance the disclosure itself
invites. Review-06's harm assessment of the identical loss ("two scalar values, re-enterable in
seconds") applied when the loss was the *specified* outcome; it now contradicts shipped copy.

*Route:* **Principal Engineer** (plan §Technical ruling 4), with the **Product Manager** consulted
if the answer is to change the disclosure copy instead of the behaviour. This is not the Senior
Developer's to fix unilaterally — ruling 4 currently forbids the obvious change. For the PE's
convenience, the smallest fix that appears consistent with ruling 4's *stated intent* is to have
the Simple → Enhanced transition restore `props.initial.retryAttemptLimit` /
`retryBackoffStrategy` rather than leave the fields blank: typed in-session values still would not
survive the round trip (ruling 4's actual concern), Flow F's Enhanced → Simple clearing is
untouched, and the disclosure's promise becomes true. I am recording the option, not prescribing
it — the call is the PE's.

### Finding 2 (Minor) — `store()`'s comment states a rule the code does not implement

*Criterion:* `docs/standards/documentation.md` (accuracy of a completion record) and T1's own
acceptance criterion — *"A `mode = simple` store/update never writes either retry column — not a
value, not NULL."*

`update()` builds an explicit array literal and spreads the retry keys in only for Enhanced — that
one is correct. `store()` does:

```php
$proxy = Proxy::make(array_merge(
    $data,
    $data['mode'] === ProxyMode::Enhanced->value ? [ /* retry keys */ ] : [],
));
```

On a Simple submission the conditional yields `[]`, so `array_merge($data, [])` is simply `$data` —
and `$data` still carries `retry_attempt_limit => null` / `retry_backoff_strategy => null`
(validation permits null under `prohibited_if`, and T9's client normalisation now guarantees both
are sent as null on every Simple submission). Both keys are fillable, so `Proxy::make()` assigns
them and the INSERT writes both columns as NULL. Confirmed:

```
Proxy::make(array_merge($data, []))->getAttributes()
  => ["name","mode","processing_mode","retry_attempt_limit","retry_backoff_strategy"]
```

Behaviourally harmless — a create has nothing to preserve and the column default is NULL either
way, which is precisely what the comment's own parenthetical says. But the comment asserts the
mechanism ("The two retry keys are added to the array only when the submitted mode is Enhanced …
a Simple-mode create never writes either column, not a value, not NULL"), and T1's completion note
repeats it, and neither is true of `store()`. The stated purpose of writing `store()` this way was
that "the code reads as one rule rather than two" — it reads as a rule it does not implement, which
is the failure mode a future reader will trust. Either make `store()` actually omit the keys
(`Arr::except($data, [...])` on the Simple branch) or correct the comment and the note to say the
Simple branch is a no-op because create has nothing to preserve.

### Finding 3 (Minor) — the AC14 restore prefill had no verification of any kind

Every other design-07 flow got either a test or an explicit manual-verification section. Flow B
step 3 — the behaviour AC14's "without the member re-entering anything" reduces to on the client —
got neither: T8's Testing section names Flow B only as "the disclosure never appears", and no test
touches it. The task plan's own front matter commits to a manual-verification section for "every
task that touches design-07's Flows A–E". I verified it live and it works, so nothing is broken;
the finding is the coverage gap, which matters because the mechanism it rests on (a watcher that
deliberately does *not* fire on mount) is fragile and is one line away from Finding 1's failure
mode.

## Standards checklist

| Area | Result |
|---|---|
| Authorization | ✅ No new permission or gate; `edit()`/`update()` still `authorize('update', …)`; `TeamPermission` case list pinned unchanged by test |
| Security | ✅ Amendment A's exposure is two clamped, inert scalars reaching only a holder of the update permission. No secret, payload, header, route or egress path added or widened |
| Data / migrations | ✅ No migration, column, index, or enum value. Verified by empty `database/migrations` diff |
| Architecture / module boundaries | ✅ Single-resolver invariant not merely preserved but **repaired** (`ProxyResource` no longer reads the columns; `SweepDueRetries` no longer reads config). ADR-018 Decision 1's two evaluation points re-derived and exact |
| Config sanity | ✅ All seven `retry.*` keys now behind `positiveConfigInt()`. This closes the last instance of the recurring partial-guard shape in this codebase |
| Testing | ✅ `createQuietly()` throughout; no per-class `RefreshDatabase`; tests assert mechanisms (real attempt schedules, a Finder-based single-caller pin) rather than restating the implementation |
| Documentation | ⚠️ Findings 2 and 3. Otherwise the docblock sweep is thorough — no surviving citation of the retired "columns are always NULL" rationale |
| Design conformance | ✅ Help text, disclosure bullets, and both Show captions are verbatim design-07. `Alert` inline and non-dismissible, between Mode and Save, not a gate (all verified live). Caption is a `<p>` under the header, no new card (Amendment B). Index unchanged. Mode `Select` still exactly two items |
| Scope discipline | ✅ Nothing outside the plan's authorisation. `PipelineFactory` byte-identical; neither excluded Senior-Developer item present; no feature-#8 artifact in the diff. The two collateral changes outside stated Files lists (T1's four fixtures, T12's `WebhookEventResource`) are both justified — see below |

**On the two out-of-Files-list collateral changes, both judged correct:**

- **T1's four fixture fixes** are legitimate corrections, not weakened assertions. Each adds
  `mode => Enhanced` to a fixture that had been relying on a raw column being honoured on a
  default-`Simple` proxy — the retired invariant. Crucially, `ProxyUpdateTest`'s inverted case does
  not soften to a loose assertion: it swaps `assertNull` for `assertSame(5, …)` /
  `assertSame('fixed', …)`, which is a *stronger* pin than before. Its immediate neighbour
  (`test_update_clears_a_previously_configured_retry_policy_when_omitted`) correctly still asserts
  NULL, because it submits `mode: enhanced` — the Enhanced-clears-on-omission path is intact.
- **T12's `WebhookEventResource` addition** (`'created_at' => null` in the legacy-fallback array)
  belongs. The TS `Delivery` interface declares `created_at: string | null` non-optionally; without
  the addition a legacy-fallback row would be `undefined` at runtime, and the two
  `DeliveryResource`-shaped payloads would have divergent key sets. No behaviour changes (legacy
  rows are always `kind: 'original'` and never read `group.time`). The docblock's "left null" list
  was updated in the same change. Correct call, correctly documented.

## Recommendations

1. **Finding 1 (Major) — blocks approval.** Route to the **Principal Engineer** to re-rule
   §Technical ruling 4 against the mount-seeded case, with the **Product Manager** consulted if
   the resolution is to change AC14(c)'s disclosure copy rather than the form behaviour. Whichever
   way it lands, add coverage — a test if the harness gap ever closes, otherwise an explicit
   manual-verification step on the rework task.
2. **Finding 2 (Minor).** Senior Developer: make `store()` implement the rule its comment states,
   or correct the comment and T1's completion note. One line either way.
3. **Finding 3 (Minor).** Fold a manual-verification step for Flow B step 3 into the rework task
   for Finding 1 — the two touch the same watcher.
4. **Finding 4 (Minor).** Principal Engineer, documentation only: reconcile plan-07 §Test strategy
   with §Architecture C so a future reader is not sent looking for an absent key. The implemented
   assertion is the correct one.
5. **Nits 5–7.** Backlog. Nit 6 in particular is a natural companion to whatever touches the
   disclosure copy next.

Standing items unchanged and carried forward, neither raised as a finding here: the **absent
frontend test harness** (deferred backlog item — Findings 1 and 3 are the third and fourth concrete
arguments for re-raising it), and the **inspection-only ACs** (AC4, AC7, AC8, AC15, AC18, AC19,
AC22–AC25), which I re-derived from the diff rather than accepted on assertion.

## Approval

- **Recommendation:** **Request changes** — one Major (Finding 1). Everything else about this
  feature is in good order: the mechanism is right, the invariants hold under independent
  re-derivation, review-06's three obligations are genuinely discharged, both indivisible commits
  were honoured, and every reported gate number reproduces. If the Project Owner judges Finding 1
  an accepted consequence of design-06 Flow F rather than a defect — a defensible reading, since no
  AC's literal text is breached and the behaviour predates #7 — then the recommendation becomes
  **Approve with follow-ups** with Findings 1–4 recorded as such. That judgment is the Owner's, not
  mine.
- **Project Owner decision / date:** _pending_

## Handoff

- **Inputs:** PRD-07 (Approved, Owner 2026-08-21, incl. Amendments A and B); design-07 (Approved,
  PM 2026-08-25, approval note governing); plan-07 (PE self-certified); ADR-018 (Accepted) and the
  annotated ADR-015; `docs/tasks/enhanced-mode-toggle-tasks.md` T1–T13 with completion notes;
  `docs/reviews/review-06-retry-replay.md` (Minors 5, 8, 9 and Ruling 2); `docs/standards/`; the
  branch diff `f72153f..415dad1` (27 files); a live headless-browser session against a freshly
  built bundle (fixtures seeded and removed; the shared dev database is as it was found).
- **Outputs:** this review.
- **Dependencies:** none new. No dependency, stack, data-model, route, or permission change in the
  reviewed diff.
- **Outstanding Questions:** **Finding 1 → Principal Engineer** (plan §Technical ruling 4), with
  the **Product Manager** consulted if the disclosure copy is what changes. **Finding 4 → Principal
  Engineer** (documentation). Findings 2, 3 and Nits 5–7 → Senior Developer / backlog.
- **Next Agent:** **Project Owner** — to decide whether Finding 1 blocks or is accepted. On "blocks",
  the rework routes to the **Principal Engineer** first (the fix is forbidden by a standing plan
  ruling), then the Senior Developer, then back here for a re-review recorded in place in this file.
