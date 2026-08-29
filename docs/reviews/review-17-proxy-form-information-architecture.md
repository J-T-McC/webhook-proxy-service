# Review: Proxy create/edit form — information architecture restructure (design-17)

- **Reviewer / date:** Reviewer, 2026-08-29
- **Scope:** branch `feat/design-17-form-restructure`, eight commits `0465051`..`e2dd1a0` (T1–T8),
  cut from `main` at `17fb9a8`. The whole code change is
  `resources/js/pages/proxies/ProxyForm.vue`.
- **Inputs verified:**
  - `docs/design/design-17-proxy-form-information-architecture.md` (Approved — Product Manager,
    delegated design gate, 2026-08-29, corrections C1–C5 all landed, no open questions). Read in
    full: a gate against every acceptance criterion is the case that warrants the whole artifact.
  - `docs/tasks/proxy-form-information-architecture-tasks.md` (Task-Planner-certified), read in
    full including all eight sets of completion notes.
  - `docs/standards/review.md`, `docs/standards/design.md`, `docs/standards/coding.md`,
    `docs/standards/testing.md`, `docs/standards/documentation.md`.
  - No PRD-17 and no plan-17 exist, and neither is owed: this work adds no capability, and
    `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`
    `## Amendment A` records that the Owner-directed Principal Engineer sign-off gate stays lapsed.
    The design is the specification. This is not a skipped-Designer-phase Blocker — the design
    spec exists and is approved.

## Gates — re-run by the Reviewer, not taken on trust

| Gate | Command | Result observed |
|---|---|---|
| PHP code style | `composer lint` | `{"tool":"pint","result":"passed"}` |
| PHP static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` |
| PHP test suite | `./vendor/bin/sail test --parallel` | `{"tool":"paratest","result":"passed","tests":1019,"passed":1019,"assertions":4818,"duration_ms":11395}` |
| Frontend types | `pnpm types:check` (`vue-tsc --noEmit`) | clean, exit 0 |
| Frontend format | `pnpm format:check` | "All matched files use Prettier code style!" |
| Frontend lint | `pnpm lint:check` | See note below — clean on every tracked file. |
| Frontend build | `pnpm build` (host) | `✓ built in 2.28s`, exit 0 |

**Note on `pnpm lint:check`.** The raw run reports 730 errors across 354 files and exits 1.
Every one of those 354 files lives under a stale, untracked `.claude/worktrees/agent-*`
directory that does not exist in CI — verified by counting: files with errors that are *not*
under `.claude/worktrees` number **zero**. Scoping the same linter to the tracked tree
(`npx eslint resources/`) and to the four files this feature's scope names
(`ProxyForm.vue`, `Create.vue`, `Edit.vue`, `DestinationRows.vue`) both produce no output and
exit 0. The Senior Developer's "green" claim is accurate for the files this branch touches.

**Suite delta.** The branch diff contains no test file at all, so the suite delta is structurally
zero rather than merely observed to be zero: no test was added, removed, skipped or relaxed. The
1019/4818 figure matches what the suite reported at the close of item #10, which independently
corroborates the task plan's claim that no existing test asserts on this form's copy or DOM.

## Scope claim verification

`git diff main..HEAD --stat` returns exactly two paths:

```
 docs/tasks/proxy-form-information-architecture-tasks.md   | 229 +++++-
 resources/js/pages/proxies/ProxyForm.vue                  | 793 ++++++++++++---------
 2 files changed, 673 insertions(+), 349 deletions(-)
```

The claims that `Create.vue`, `Edit.vue` and `DestinationRows.vue` have a zero diff, and that no
backend file is touched, are **confirmed** — they are absent from the stat entirely.

## Browser verification

The Senior Developer could not browser-verify: the dev-team agents carry no Playwright tool, and
every one of its eight completion notes says so explicitly, naming which checks are source-verified
and which are inherently browser-only. That disclosure is accurate and is the right posture — it
records a limit rather than claiming a check it could not run.

The browser-only checks were subsequently run in the main session, headless Playwright against a
host-built `public/build` on this branch at `e2dd1a0`, using the Edit form for proxy 2. Those
results are recorded here as verified observations, and none of them contradicts what this review
found in source:

- Five containers render in the specified order — Details, Response, Delivery, Sensitive fields,
  Destinations. `h2` headings render for Details, Response and Delivery only; Sensitive fields and
  Destinations carry a `legend` instead, which is correction C4's pairing.
- **C1 confirmed live.** The Delivery card holds a `fieldset` legended "Mode and processing";
  switching Mode to Enhanced adds the second, legended "Retry policy" — four legends in total.
- **C2 confirmed live.** `id="destinations-help"` exists, renders "The webhook is delivered to every
  destination below.", and `#destination-0-url` carries `aria-describedby="destinations-help"`.
- No dangling `aria-describedby` anywhere on the form, in either Simple or Enhanced mode — every
  referenced id resolves against the document. This matches the static check run for this review
  independently (below).
- Four tooltips, all keyboard-safe: every trigger is a real `<button>` with `tabIndex` 0 and an
  `aria-label`; focusing one by keyboard opens its tooltip; `reka-ui` wires `aria-describedby` to
  the tooltip content automatically; Escape closes it and focus stays on the trigger. Note **N1**'s
  `ReplayDialog.vue` bare-`span` anti-pattern is absent.
- Submit path intact: Mode changed to Enhanced and back to Simple, saved, redirected to the Show
  page with no validation error.
- 360px viewport in the dark theme renders correctly, five cards stacked in order, no horizontal
  overflow (`scrollWidth` equals `clientWidth`).

**One item the browser could not settle, settled here in source instead.** Rendering "Default 5.
Max 10." cannot distinguish an interpolated `defaultAttemptLimit` from a hard-coded literal. The
source resolves it: `ProxyForm.vue` renders `Default {{ defaultAttemptLimit }}. Max 10.`, and
`defaultAttemptLimit` is assigned from `RETRY_DEFAULT_ATTEMPT_LIMIT`, imported from
`@/data/proxyRetryBackoffStrategies`, where it is declared `= 5`. This is the same constant the
Retry policy `fieldset` help one field above it already reads. **Correction C5 landed as ruled** —
the two strings cannot drift apart if the system default ever changes.

**Residual risk after all of the above: effectively none for this feature's own surface.** The
combination of the live pass and the source checks covers every acceptance criterion in the task
plan. What remains genuinely unexercised is narrow and is not specific to this change: the light
theme at 360px (dark was checked), the Create form as distinct from Edit (the two share one
component with a zero-diff wrapper on each side, so the risk is structural rather than empirical),
and pointer-hover tooltip dismissal as distinct from keyboard Escape. None of these is worth
blocking on, and none is a claim the Senior Developer made and failed to support.

## Copy dispositions — checked verbatim against the design's table

Rather than compare strings by eye, the whole visible-text content of the template was extracted
from `main` and from `HEAD`, whitespace-normalized, and diffed. That produces the exact copy delta
this branch introduces, which is the only way to prove a negative — that no string the design ruled
**cut** still renders somewhere.

**Every string the design ruled cut is gone from the template:**

| String cut | Design ruling | Verified |
|---|---|---|
| "A name to recognise this proxy." | `### Details` — cut outright, no tooltip | Gone |
| "Enhanced mode stores the payload actually dispatched, separately from…" | `### Delivery` — replaced by a one-liner | Gone |
| "Independent of the Mode setting above. Async (default) delivers this proxy's events…" | `### Delivery` — replaced by a one-liner | Gone |
| "Applies to automatic re-attempts after a failed delivery to a destination." | `### Delivery` — first sentence cut | Gone |
| "Leave blank to use the default (5). Maximum 10." | `### Delivery` — replaced, C5 interpolation | Gone |
| "Exponential increases the wait between attempts each time…" | `### Delivery` — cut from the form, moved to tooltip | Gone from the form |
| "The HTTP status returned to the sender the moment the webhook is received…" | `### Response` — trimmed; option specifics cut, not tooltipped | Gone |
| "An optional fixed body returned with the acknowledgement…" | `### Response` — trimmed | Gone |
| "Values in these fields are hidden wherever this proxy's stored payloads are shown…" | `### Sensitive fields` — trimmed | Gone |
| "Case and separators don't matter — password, Password and pass_word are all this same name." | `### Sensitive fields` — cut from the form, moved to tooltip | Gone from the form |

**Every string the design ruled kept on-form matches the specified text exactly:**

| Shipped on-form string | Design's proposed text | Match |
|---|---|---|
| "Sent immediately, before delivery — independent of destination outcome." | identical | Exact |
| "Optional. Disabled when Status code is 204." | identical | Exact |
| "Enhanced stores what was actually dispatched and unlocks the retry settings below." | identical | Exact |
| "Async (default) delivers in parallel, no order guaranteed. FIFO preserves order, at lower throughput. Set independently of Mode." | identical | Exact |
| "Simple-mode proxies use the fixed default ({N} attempts, {strategy})." | both placeholders interpolated live | Exact |
| "Default {N}. Max 10." | `{N}` interpolated per C5 | Exact |
| "Hidden wherever this proxy's payloads are shown. Storage and delivery are unaffected." | identical | Exact |
| "The webhook is delivered to every destination below." | C2 — keep, unchanged | Unchanged |

**All four tooltips carry the design's words verbatim**, and no tooltip carries words the design did
not specify:

| Trigger | Shipped content | Design |
|---|---|---|
| Response Body | "Useful for a verification challenge echo some senders require during setup." | `### Response`, Body row |
| Mode | "Automatic retry, payload capture, retention, and replay all apply regardless of Mode — this only affects dispatched-payload storage and the retry settings below." | `### Delivery`, Mode row |
| Backoff strategy | "Exponential increases the wait each attempt; fixed interval stays constant. Always bounded well inside the 30-day retention window." | `### Delivery`, Backoff row |
| "Always hidden" | "Matches password, Password, pass_word, etc. — case and separators don't matter." | `### Sensitive fields` |

**The anti-pattern the design names is respected.** `## Rule: form copy vs. tooltip vs. cut` bucket 3
warns that "a tooltip is not a dumping ground for every trimmed sentence" and names four fields that
must carry none — Name, the Sensitive-fields Add input, and the Destinations URL/Method fields.
None of them has one. Status code, whose option specifics were ruled cut rather than tooltipped,
correctly has none either. Exactly four tooltips exist.

**Labels changed as specified:** "Response status code" → "Status code", "Response body" → "Body".

**The downgrade disclosure is byte-identical.** It does not appear in the normalized text diff at
all, which proves its three bullets survived the move into the Delivery card untouched — the
carve-out `CLAUDE.md` states for multi-step consequence statements, and the design's own
"Unchanged, verbatim" ruling.

**Note N2 survived.** Response's relocation is a real move, not a reflow. In the normalized text
extraction the Response strings sit between Details and Delivery on `HEAD`, where on `main` they
sat between the Backoff strategy help and the Sensitive fields legend. This is the one change of
substance the design flagged as droppable, and it was not dropped.

## Accessibility — the obligations the design makes specific

The task plan's header names four accessibility obligations and pins each to a task. Each was
checked independently rather than read from a completion note.

**1. `id="destinations-help"` survives as every destination row URL input's `aria-describedby`
target.** `resources/js/components/DestinationRows.vue` is byte-identical to `main` — proven two
ways, by its absence from `git diff main..HEAD --stat` and by an md5 comparison against
`git show main:…`, both matching. The help paragraph carries `id="destinations-help"` at line 124
and each row's URL input falls back to it at line 152 when that row has no error. Confirmed live on
`#destination-0-url`. **Met.**

**2. Every tooltip trigger is a keyboard-focusable button with an accessible label.** All four use
the same shape: `TooltipTrigger as-child` wrapping a real `Button` (`variant="ghost"
size="icon-sm"`) holding the `Info` icon, each with its own `aria-label` — "More about the response
body", "More about Mode", "More about Backoff strategy", "More about matching for Always hidden
fields". This is the `teams/Edit.vue` precedent, not `ReplayDialog.vue`'s. Note **N1**'s two
specific warnings are both respected: no trigger is a bare `span`, and no `TooltipContent` carries a
bespoke `max-w-xs` — every one is unstyled, per `## Responsive Behavior`'s refusal of bespoke
widths. This also satisfies `docs/standards/design.md` → Screen-reader requirements ("every
icon-only or ambiguous-target control carries a discernible `aria-label`"). **Met**, and confirmed
live for focus, Escape and `aria-describedby` wiring.

**3. Every new `fieldset` has a `legend`.** Four `fieldset`s, four `legend`s: "Mode and processing"
(new, correction C1), "Retry policy", "Sensitive fields", "Destinations". **Met** structurally —
see Finding 1 on how two of them are *styled*.

**4. No `aria-describedby` points at an id this branch deleted.** Two ids were removed — `name-help`
(T1) and `retry-backoff-strategy-help` (T4) — and both references were updated in the same commits.
Verified exhaustively rather than by spot-check: every `id="…"` in the file was collected and every
whitespace-separated token of every `aria-describedby` was resolved against that set. Thirteen
distinct references, zero dangling, zero duplicate ids. The live pass reached the same result by
walking the rendered DOM in both Simple and Enhanced modes. **Met.**

**One regression class checked that no task named.** The inverse risk of obligation 4 is a
`describedby` reference silently *dropped* rather than left dangling. Comparing `main`'s wiring
against `HEAD`'s: the only two removals are the two the design authorizes. Every other help id and
every error id retains its reference. In particular the Sensitive-fields Add input's
`aria-describedby="sensitive-fields-error"` is unchanged, and the section help paragraph carried no
id on `main` either — so nothing was lost there.

## Sequencing deviations

Two are recorded in the task notes, and both hold up.

**T1 built ahead of itself** — it introduced the outer `<div class="space-y-6">` stacking wrapper and
moved the Submit/Cancel Actions row out of the Card, even though those belong to the final
structure T7 verifies. Its note discloses both, says why (the file has to stay syntactically valid
between dependent tasks), and says they are re-verified at T7. Checking `main`: the Actions row
genuinely was inside the single `Card`, so moving it out is required by Screen 1's "Actions: Submit,
Cancel (unchanged, outside all Cards, at the form's end)" and is not scope creep. **Disclosure
adequate.**

**T3 built ahead of itself** — it closed the Delivery Card and opened an unheaded holding Card for
Sensitive fields plus Destinations, pending T5/T6. Same rationale, same disclosure. **Adequate.**

**Nothing was silently folded in.** T1's diff is 706 changed lines, which is far more than "Details
gets its own Card" implies and is exactly the shape in which a stray change hides. Re-running that
diff with whitespace and blank-line changes suppressed reduces it to: the `space-y-6` wrapper, the
Details `Card` and its `h2`, the Name help paragraph's removal, the Name `aria-describedby` update,
the holding `Card`, and the Actions row's move. Everything else is Prettier re-indentation forced by
the new nesting depth. The same check on T3 leaves only the Delivery `h2`, the "Mode and processing"
`fieldset`/`legend`, the Mode tooltip and the Mode help trim. **No undisclosed change in either.**

Independently, the whole-branch normalized text diff (above) is the strongest evidence on this
point: it enumerates every copy change across all eight commits, and every line in it is a design
ruling. A silently folded-in copy edit would appear there and does not.

## Acceptance-criterion coverage

| Task | Criteria | Verdict |
|---|---|---|
| T1 — Details card, Name help cut | Details `Card` first, `h2 text-base font-semibold`, Name only, no `fieldset`; help paragraph removed entirely; `aria-describedby` drops `name-help`, keeps `name-error`; `v-model`/placeholder/disabled/validation unchanged | **Met** on every criterion |
| T2 — Response card, moved second, copy, Body tooltip | Second card headed "Response", exactly Status code and Body, after Details and before Delivery; both labels and both help strings exact; Status code carries no tooltip; Body tooltip is a focusable button with `aria-label`, content linked via `aria-describedby`; `statusSelect`/`selectedStatus`/`bodyDisabled`/204 watcher unchanged | **Met.** N2's relocation landed as a real move |
| T3 — Delivery shell, "Mode and processing" fieldset (C1) | Third card headed "Delivery" with two `fieldset`/`legend` groups; Mode help exact; Mode tooltip conformant; Processing help exact, no tooltip; downgrade `Alert` unchanged character-for-character and still gated on `isDowngrading`; `isEnhanced`/`isDowngrading`/the reseed watcher unchanged | **Met.** C1 landed as ruled |
| T4 — Retry policy copy pass (C5) | Fieldset help exact with both interpolations live; Attempts help "Default {N}. Max 10." with `{N}` interpolated and no literal `5` left; Backoff help `<p>` removed and its id dropped from `aria-describedby`; Backoff tooltip conformant; `retryStrategySelect`/sentinel/validation unchanged | **Met.** C5 landed as ruled |
| T5 — Sensitive fields card, copy, tooltip (C4) | Fourth card wraps the `fieldset`/`legend` with no separate `h2`; section help exact; "Case and separators…" removed from the template entirely; "Always hidden" tooltip conformant; badges, additions list, add/remove handlers and the no-obfuscation-toggle invariant unchanged | **Met on the stated criteria.** See Finding 1 on the legend's visual weight, which the task's own AC does not test but the design requires |
| T6 — Destinations card, structural wrap only (C2) | Fifth card wraps `DestinationRows` with no `h2`; `DestinationRows.vue` zero diff; `destinations-help` present once and still each row's URL fallback target; the `destinations` `InputError` unchanged | **Met on the stated criteria.** See Finding 1 |
| T7 — cross-cutting a11y and structure sweep | Exactly five cards, `space-y-6`, correct order, on both Create and Edit; every `fieldset` legended; all four tooltip triggers real focusable buttons; no dangling `aria-describedby`; outer wrapper and Actions row unchanged and outside all cards; usable at 360px | **Met.** The two criteria the note left as browser-only (tooltip focus/Escape behaviour, 360px rendering) are now confirmed live |
| T8 — regression sweep and gates | All backend and frontend gates green with zero backend diff; full suite green at the same count; no existing test asserts on this form's copy or markup | **Met.** All six gates re-run for this review; the "no test asserts on copy" claim is structurally confirmed by the branch containing no test file |

## Standards compliance

| Check | Source | Result |
|---|---|---|
| Every input has an associated `Label`; icon-only controls carry a target-naming `aria-label` | `design.md` → Screen-reader requirements | Pass — four new icon-only buttons, four `aria-label`s |
| Help and error text linked via `aria-describedby`, not just visually adjacent | `design.md` → Screen-reader requirements | Pass — thirteen references, none dangling |
| Each validated field renders `InputError`, sets `:aria-invalid` and `aria-describedby`; focus moves to the first `[aria-invalid="true"]` on failed submit | `design.md` → Validation feedback | Pass — the `onError` handler in `submit()` is unchanged |
| Every interactive control keyboard-reachable and operable; focus ring never suppressed | `design.md` → Accessibility baseline (WCAG 2.1 AA) | Pass — confirmed live for all four new controls |
| Reuse existing `ui/*` primitives before adding one; icons only from `@lucide/vue` | `design.md` → Component library | Pass — `Card`, `Tooltip`, `Button` all existing; `Info` was already imported |
| Generated `components/ui/*` are never hand-edited | `coding.md` → Project structure | Pass — no file under `components/ui/` is in the diff |
| No new runtime dependency without an Owner-approved ADR | `coding.md` → Dependencies | Pass — no dependency change; no lockfile in the diff |
| Stacked-section spacing `space-y-6`; card padding `p-6`; 360px minimum width | `design.md` → Spacing, Minimum supported width | Pass |
| Section/card heading treatment consistent across the surface | `design-17` `## Grouping proposal` | **Fail — Finding 1** |
| Tests run green from the suite, not from claimed results | `review.md` → Review scope | Pass — all six gates re-run here |
| Every artifact carries Status, Author, Approval and a Handoff section | `documentation.md` → Active conventions | Pass for the design and the task plan |
| Approval recorded in the artifact **and** `docs/status.md` | `documentation.md` → Active conventions | **Fail — Finding 3** |

## Findings

| # | Severity | Location | Finding |
|---|---|---|---|
| 1 | Major | `resources/js/pages/proxies/ProxyForm.vue:666`; `resources/js/components/DestinationRows.vue:123` | The Sensitive fields and Destinations cards' `legend`s do not carry the heading weight the design requires, so two of the five containers read as visually subordinate to the other three. |
| 2 | Minor | `resources/js/pages/proxies/ProxyForm.vue:363, 419, 602, 677` | Each of the four tooltips instantiates its own `TooltipProvider`, so Reka's shared open/close delay grouping never applies between them. |
| 3 | Minor | `docs/status.md:178–197` | The `design-17` status block is stale: it records the phase as the design gate and the current agent as the Designer, and names a branch that is not the one this work was built on. |
| 4 | Minor | `docs/design/design-17-…md` `## Consequences`; `docs/standards/design.md` | The seven downstream artifacts the design lists as needing amendment are all still unamended, including a `docs/standards/design.md` typography passage that is now factually wrong about the shipped code. |

### Finding 1 — Major — the single-`fieldset` cards' headings do not carry heading weight

**Criterion violated.** `design-17` `## Grouping proposal`: the five cards are "each headed the same
way `proxies/Show.vue` already heads its own stacked cards (`<h2 class="text-base
font-semibold">`) or, where a card wraps a single `fieldset`/`legend` group (**Sensitive fields and
Destinations** …), the `legend` carries **that same visual weight** instead of a redundant second
heading." The design gate's correction **C4** exists specifically to fix which two cards this rule
governs, so the rule was looked at directly at the gate and left standing.

**What shipped.** Details, Response and Delivery are headed `<h2 class="text-base font-semibold">`.
Sensitive fields and Destinations have no `h2` — correct — but their `legend`s are
`class="text-sm font-medium"`, which is both a smaller size and a lighter weight, and is the *same*
class as the two `legend`s nested *inside* the Delivery card ("Mode and processing", "Retry policy")
that are deliberately subordinate to that card's own `h2`. So the shipped form renders three
top-level headings and two that are styled exactly like a sub-heading, and a member scanning the
form sees a hierarchy the design did not specify. On a feature whose entire deliverable is visual
grouping — commissioned because the Owner found the form "too jumbled and overwhelming" — this is
the axis that matters most.

**Why Major rather than Minor.** No task acceptance criterion is literally breached: T5's AC tests
only "no separate `h2` inside the `Card`" and T6's only "no `h2` … the component's own `legend`
carries the heading weight," neither of which names a class. But the design is the specification for
this feature — there is no PRD and no technical plan — and this is an explicit, user-visible
sentence of it that did not land. It is also the one place where the completion notes overstate:
T5's note asserts "the `legend` carries the heading weight" and T6's repeats it, when the legend's
styling is unchanged from `main` and is indistinguishable from a subordinate legend. This matches
the precedent set at review-07, where shipped work that defeated a design-named outcome without
breaching any AC's literal text was graded Major.

**A genuine ambiguity, which shapes the routing.** "The `legend` carries the heading weight" can be
read as the implementer read it — the legend *is* the heading, so leave legends styled as legends —
and `docs/standards/design.md`'s typography section does say section/card headings are `text-sm
font-medium` (though that passage is stale; see Finding 4). The design's own words are more
specific than that reading allows: "that same visual weight" refers to the `h2`'s, and the clause
"instead of a redundant second heading" only works if the legend looks like the heading it replaces.
Either way the shipped result is internally inconsistent across the five containers, which the
design forbids however the sentence is read.

**The Destinations half is a design self-conflict, not an implementation defect.** That `legend`
lives in `DestinationRows.vue`, which Screen 1 marks "unchanged internals" and which T6's AC
requires to have zero diff. Restyling it and leaving it byte-identical cannot both be satisfied.
The Senior Developer followed the instruction it was given and should not be asked to break it. This
half needs a Designer ruling on which clause governs, per the escalation rule for a finding caused
by a design-spec defect.

**Recommended resolution.** One line from the Designer settling both halves — the intended class for
a card-level `legend`, and whether "unchanged internals" yields for it — then the Senior Developer
applies it to `ProxyForm.vue:666` and, if ruled, to `DestinationRows.vue:123`. This is a
single-utility-class change per site with no behavioural surface; it needs no re-run of the backend
gates, only `pnpm format:check` and a 360px re-check.

### Finding 2 — Minor — one `TooltipProvider` per tooltip

`ProxyForm.vue` wraps each of its four tooltips in its own `TooltipProvider`. Reka's provider exists
to share `delayDuration` and `skipDelayDuration` across a group of tooltips, so that moving between
two of them does not re-incur the open delay; with one provider per tooltip that grouping can never
apply, and each of the four always waits the full default delay.

This matches the shape already shipped in `teams/Edit.vue` and `ReplayDialog.vue`, and the design's
`## Components` row names all four parts without saying where the provider sits, so nothing is
violated — it is a consistency and feel improvement, not a defect. Recorded as a follow-up:
hoisting one `TooltipProvider` to wrap the whole `<form>` would cover all four and is a smaller
diff than the current arrangement. Does not block.

### Finding 3 — Minor — `docs/status.md`'s `design-17` block is stale

The block still reads **Phase:** "design gate passed", **Current agent:** "Designer, landing C1–C5;
then the Task Planner", and names the branch `design/proxy-form-restructure`. All three are behind
reality: C1–C5 landed, the task plan is written and Task-Planner-certified, all eight tasks are
built, and the work sits on `feat/design-17-form-restructure`. `docs/standards/documentation.md` →
Active conventions requires approval and phase to be recorded in the artifact **and** `docs/status.md`,
and `CLAUDE.md` makes `docs/status.md` the routing surface — a stale block here misroutes the next
agent to the Designer.

This is not the Senior Developer's to fix. `CLAUDE.md` assigns `docs/status.md` upkeep to the
orchestrator skill. Recorded so the Owner can route it alongside the approval decision. Does not
block.

### Finding 4 — Minor — the design's `## Consequences` amendments are all outstanding

`design-17` `## Consequences` lists seven artifacts whose current text this restructure makes
inaccurate: `design-10` (Screen 2's help copy and Screen 2/3's containers), `design-07` (Mode's
help copy, the disclosure's container), `design-06` (Retry policy help and placement), `design-04`
(Processing help and placement), `design-01` (the form's section list and single-`Card` structure,
plus Name's help), `design-03` (the Response copy and the field's move), and
`docs/standards/design.md` (a note that `Tooltip` is now an active pattern for field-level
explanatory copy). None has been amended.

The design is explicit that it amends none of them and that the Project Owner directs which are
amended and by whom, so this branch is correct not to have touched them — it is an open routing
decision, not an omission by the Senior Developer. It is recorded here only so it is not lost at
the approval gate.

One of the seven is worth pulling out because it will mislead a future implementer sooner than the
rest: `docs/standards/design.md`'s typography passage states that card headings are `text-sm
font-medium` and cites `Show.vue` as the evidence, but the shipped `Show.vue` heads all seven of its
cards `text-base font-semibold`. The passage is marked "Proposed default", not ratified, so nothing
in this branch violates it — but it is the passage a reader would consult to settle Finding 1, and
it currently gives the wrong answer. Does not block.

## Recommendations

- **Finding 1 (Major, blocks approval).** Designer rules the intended `legend` treatment for a
  card-level `legend`, and whether Screen 1's "unchanged internals" yields for `DestinationRows.vue`.
  Senior Developer then applies the ruling. Re-review needs only the frontend format gate and a
  360px visual re-check.
- **Finding 2 (Minor, follow-up).** Optional: hoist a single `TooltipProvider` to the form root.
- **Finding 3 (Minor, follow-up).** Orchestrator refreshes the `design-17` block in `docs/status.md`
  — phase, current agent, branch name.
- **Finding 4 (Minor, follow-up).** Project Owner directs the `## Consequences` amendments,
  ideally starting with the `docs/standards/design.md` typography correction.

Findings 2, 3 and 4 do not block. If the Project Owner judges Finding 1's heading treatment a
matter of taste rather than a specification breach, this becomes *Approve with follow-ups* — that
call is the Owner's, and this review does not make it.

## Approval

- **Recommendation:** Request changes — one Major, scoped to a single utility class at two sites,
  gated on a one-line Designer ruling. Everything else about this branch is clean: the scope claims
  are true, all eight tasks' criteria are met, every copy disposition matches the design verbatim,
  all four accessibility obligations are discharged and confirmed live, and all six gates are green.
- **Project Owner decision / date:** _pending_

## Handoff

- **Inputs:** `docs/design/design-17-proxy-form-information-architecture.md`;
  `docs/tasks/proxy-form-information-architecture-tasks.md`; `docs/standards/review.md`,
  `design.md`, `coding.md`, `documentation.md`, `testing.md`; branch
  `feat/design-17-form-restructure` at `e2dd1a0`; the main session's Playwright pass against a
  host-built bundle at the same commit.
- **Outputs:** this review.
- **Dependencies:** Finding 1's fix depends on a Designer ruling before any code change.
- **Outstanding Questions:** for the Designer — for a `Card` that wraps a single `fieldset`, what
  class does the `legend` carry so that it reads as that card's heading; and does Screen 1's
  "`DestinationRows.vue`, unchanged internals" yield to `## Grouping proposal`'s heading-weight rule
  for that component's own `legend`, or override it?
- **Next Agent:** Project Owner (decision), then Designer (Finding 1 ruling), then Senior Developer
  (apply), then Reviewer (re-review, recorded in place in this file).
