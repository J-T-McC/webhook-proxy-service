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

- **Recommendation:** ~~Request changes~~ — **SUPERSEDED by the re-review below (2026-08-29).**
  The recommendation as written at the first pass: request changes — one Major, scoped to a single
  utility class at two sites, gated on a one-line Designer ruling. Everything else about this branch
  is clean: the scope claims are true, all eight tasks' criteria are met, every copy disposition
  matches the design verbatim, all four accessibility obligations are discharged and confirmed live,
  and all six gates are green. **See `## Re-review (2026-08-29)` for the live recommendation.**
- **Project Owner decision / date:** _superseded — see the re-review recommendation_

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

---

# Re-review (2026-08-29)

- **Reviewer / date:** Reviewer, 2026-08-29
- **Scope:** branch `feat/design-17-form-restructure` at `837601b`, four commits since the reviewed
  `e2dd1a0`: `3bf21a8` (Designer's amendment ruling Finding 1, plus a `docs/standards/design.md`
  correction, plus the `docs/status.md` fix for Finding 3), `7296d41` (Task Planner correcting T6's
  zero-diff criterion and adding T9), `cb862d2` (Senior Developer implementing T9), `837601b`
  (agent-memory note, no product code).
- **What this section covers:** whether Findings 1 and 2 are resolved, whether Finding 3's fix
  landed, whether the rework stayed in scope, and one new defect found after the first pass.

## Gates — re-run at `837601b`

| Gate | Result observed |
|---|---|
| `composer lint` | `{"tool":"pint","result":"passed"}` |
| `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `./vendor/bin/sail test --parallel` | `{"tool":"paratest","result":"passed","tests":1019,"passed":1019,"assertions":4818,"duration_ms":10346}` |
| `pnpm types:check` | clean, exit 0 |
| `pnpm format:check` | "All matched files use Prettier code style!" |
| `pnpm lint:check` | Clean on every tracked file — the count of files with errors outside `.claude/worktrees` is **0**; `npx eslint resources/` exits 0 silently |
| `pnpm build` (host) | `✓ built in 2.21s`, exit 0 |

**Suite delta reconciles exactly.** 1019 tests / 4818 assertions, identical to the first pass. The
rework added no test file and removed none, which is expected — the change is three presentational
lines and one component hoist, with no backend surface.

## Scope discipline of the rework

The diff from `e2dd1a0` to `837601b` touches two product files. Whitespace- and blank-line-suppressed
diffing reduces `ProxyForm.vue`'s 801 changed lines to exactly three content changes — one
`TooltipProvider` opened, four removed, and one `legend` class — with every remaining line being
Prettier re-wrapping at the shifted indentation depth the hoisted provider introduces.
`DestinationRows.vue`'s entire diff is one line.

**No copy changed.** The normalized visible-text extraction used at the first pass was re-run
against `e2dd1a0` for both files. Both report **an empty delta**: not one help string, tooltip body,
label, legend text or `Alert` bullet differs from what was already reviewed and found verbatim
against the design. The rework is purely presentational, as claimed.

**No accessibility wiring changed.** Every `aria-describedby` token in `ProxyForm.vue` still resolves
against an id declared in the file; there are no duplicate ids. `DestinationRows.vue` is byte-identical
apart from the one class, so `id="destinations-help"` and every row's fallback reference to it are
unchanged by construction.

**Upstream artifacts were corrected, not quietly worked around.** The Designer's amendment
(`design-17` `## Amendment — card-level legend heading weight ruling`) rules the exact class and
explicitly narrows Screen 1's "unchanged internals" to behaviour, recording that `T6`'s zero-diff
criterion would need a Task Planner correction and declining to make it from the Designer's chair.
The Task Planner then made exactly that correction, permitting the one class edit and nothing more,
and added a note that leaves T5's and T6's original completion notes untouched while recording that
their "carries the heading weight" phrasing did not match the class shipped at the time. That is the
right shape: the gate record stays readable as what was actually claimed then, with a pointer rather
than a rewrite.

## Finding 1 (Major) — RESOLVED

**Evidence, checked in source rather than taken from the completion note.** Every `legend` in the
tracked tree was re-listed:

| `legend` | Class | Expected |
|---|---|---|
| `DestinationRows.vue:123` — "Destinations" | `text-base font-semibold` | Card-level, heading weight |
| `ProxyForm.vue:688` — "Sensitive fields" | `text-base font-semibold` | Card-level, heading weight |
| `ProxyForm.vue:420` — "Mode and processing" | `text-sm font-medium` | Nested under Delivery's `h2`, subordinate |
| `ProxyForm.vue:579` — "Retry policy" | `text-sm font-medium` | Nested under Delivery's `h2`, subordinate |

This is exactly the Designer's Ruling 1 — the two card-level legends take the `h2`'s own class, the
two nested legends are untouched. `DestinationRows.vue`'s diff is the single class edit and nothing
else, which is the narrow permission Ruling 2 grants and the corrected T6 criterion tests. The
main session's runtime measurement agrees: Details, Response and Delivery `h2` all compute to 16px
weight 600, both corrected legends now compute to 16px weight 600, and "Mode and processing" stays
14px weight 500. **The five containers read as peers; the hierarchy defect is gone.**

The one detail worth recording, because a class change is the kind of fix that can be applied to the
right file in the wrong place: `ReplayDialog.vue:150`'s `legend` was correctly left alone. It is not
in this feature's scope and is not a card-level heading.

## Finding 2 (Minor) — RESOLVED, and my original rationale was wrong

**The structural change landed.** `ProxyForm.vue` now has a single `TooltipProvider`, opened
immediately inside the `<form>` and closed immediately before its close tag, wrapping the card stack
and the Actions row. Three occurrences of the token remain in the file — the import, the open tag and
the close tag — and no per-tooltip provider survives. All four `Tooltip`/`TooltipTrigger`/
`TooltipContent` triples, their trigger `Button`s, `aria-label`s and content copy are unchanged. The
main session confirmed all four still open on keyboard focus, still wire `aria-describedby` to their
content, and still close on Escape with focus retained on the trigger.

**But the benefit I claimed for this finding does not exist, and I should correct my own record.**
The first pass asserted that four separate providers meant "each of the four always waits the full
default delay" and that hoisting one would restore Reka's `skipDelayDuration` grouping. That is
wrong. This project's local wrapper, `resources/js/components/ui/tooltip/TooltipProvider.vue`,
declares `withDefaults(defineProps<TooltipProviderProps>(), { delayDuration: 0 })` — every provider
in this codebase already opens its tooltips with **no** delay, so there was never a delay to
re-incur and never one for a shared provider to skip. The hoist is a real simplification — one
provider instead of four, fewer nodes, the shape the primitive is designed for — but it changes
nothing a user can perceive.

This error propagated: T9's Description and Testing section both restate the timing benefit, and its
completion notes leave the runtime timing check outstanding for a manual pass. **That check cannot
fail and cannot pass — there is no timing difference to observe.** Nothing needs re-doing; the code
is fine and is better than it was. Recorded so the plan and this review are not left asserting a
behaviour the primitive's own defaults rule out. Graded as it was: Minor, now resolved.

## Finding 3 (Minor) — RESOLVED

`docs/status.md`'s `design-17` block now names the correct branch (`feat/design-17-form-restructure`),
records the phase as implementation-complete with the review gate run, and lists all three artifacts
including this review. The stale "Current agent: Designer, landing C1–C5" line is gone.

It will need one further refresh once the Owner rules on this re-review — the block currently states
the Reviewer's recommendation as *Request changes* on the original Finding 1, which this section
supersedes. That is ordinary routing upkeep for the orchestrator, not a re-raised finding.

## Finding 4 (Minor) — PARTIALLY RESOLVED, carried forward

The sharpest part is fixed. `docs/standards/design.md`'s typography passage no longer misstates the
shipped `Show.vue` heading class: it now reads `<h2 class="text-base font-semibold">`, and it
codifies the distinction this feature surfaced — a `legend` standing in for a card's own heading
takes `text-base font-semibold`, while a `legend` nested inside an already-headed card stays
`text-sm font-medium`. That is a better standard than existed before this work, and it means the
next implementer who consults the standard to settle a heading question gets the right answer.

The rest of `design-17` `## Consequences` is still outstanding: `design-10`, `design-07`,
`design-06`, `design-04`, `design-01` and `design-03` all still describe copy and containers this
restructure changed, and the `docs/standards/design.md` note that `Tooltip` is now an active pattern
for field-level explanatory copy is not written. The design is explicit that the Project Owner
directs those amendments and that this branch amends none of them, so nothing here is the Senior
Developer's to fix. Carried forward as a follow-up so it is not lost at approval. Does not block.

## Finding 5 — NEW — Major — every tooltip this feature adds is clipped off-screen at 360px

**Criterion violated.** Three, converging:

- **T7 acceptance criterion:** "The form remains usable and **un-clipped at 360px** width
  (`docs/standards/design.md` baseline)."
- **`design-17` `## Responsive Behavior`:** "**Minimum supported width: 360px**, the standing default
  from `docs/standards/design.md` — no feature-specific override," and, in the same section,
  "**Tooltip content** uses Reka UI's default `TooltipContent` positioning/collision handling … no
  bespoke width or placement handling is introduced."
- **`docs/standards/design.md` → Minimum supported width:** 360px is "the practical minimum to remain
  usable."

**Measured, not inferred.** Driven headless at a 360×800 viewport against a freshly host-built
`public/build` at `837601b` (no `public/hot` present, so `@vite` serves the built bundle, and the
built `ProxyForm` chunk contains the new tooltip strings). Each trigger was focused by keyboard and
its `[data-slot="tooltip-content"]` measured:

| Tooltip | Rendered width | Viewport | Clipped | Computed `max-width` |
|---|---|---|---|---|
| Mode | **892px** | 360px | 532px — **60%** | `none` |
| Backoff strategy | **744px** | 360px | 384px — **52%** | `none` |
| "Always hidden" | **469px** | 360px | 109px — **23%** | `none` |
| Response Body | **431px** | 360px | 71px — **16%** | `none` |

All four render flush at `left: 0` and overflow to the right. **The clipped remainder is
unreachable, not merely off-screen:** at the same moment, `document.documentElement.scrollWidth`,
`clientWidth` and `document.body.scrollWidth` are all **360** — the page has no horizontal scroll,
so there is no gesture that brings the missing text into view. On two of the four, the majority of
the sentence simply cannot be read.

**Root cause, in source.** `resources/js/components/ui/tooltip/TooltipContent.vue` styles the
content `… z-50 w-fit rounded-md px-3 py-1.5 text-xs text-balance`. `w-fit` resolves to the text's
max-content width with **no `max-width`**, so the content never wraps; `text-balance` only
redistributes lines once wrapping occurs and does nothing here. Reka's collision handling
repositions an overflowing tooltip but cannot shrink one, which is why the design's reliance on
"Reka UI's default positioning/collision handling" does not cover this case.

**This is a design defect, not an implementation slip — which is what sets the routing.** Note **N1**
tells the implementer not to copy `ReplayDialog.vue`, citing two things together: its bare-`span`
trigger *and* its "bespoke `max-w-xs` on `TooltipContent`", and states that `## Responsive Behavior`
"already declines bespoke widths." The Senior Developer followed that instruction exactly — no
`TooltipContent` in `ProxyForm.vue` carries a class. **N1 bundled two criticisms that deserved
opposite verdicts.** Rejecting `ReplayDialog.vue`'s non-focusable trigger was right and this feature
is better for it. Rejecting its width cap by association removed the one guard the codebase already
had against precisely this failure — `ReplayDialog.vue` is the only prior consumer whose tooltip
carries a sentence rather than a two-word action label, and it is the only one that caps its width.

**And the obvious local fix is barred by a standard.** `TooltipContent.vue` lives under
`resources/js/components/ui/`, which `docs/standards/coding.md` → Project structure (restated in
`docs/standards/review.md`'s checklist) says is generated code that **must never be hand-edited**.
So the width cap cannot go in the primitive; it can only be a `class` passed at the four call sites —
which is exactly what N1 forbids. The design has to yield for this, in the same shape as Ruling 2
yielded on "unchanged internals."

**Why Major and not Blocker.** I considered Blocker: T7's acceptance criterion says "un-clipped at
360px" in as many words, and this is not a marginal overshoot — it is all four instances, two of them
losing more than half their text. What holds me at Major is that nothing is broken or lost. The form
itself is un-clipped and fully usable at 360px (verified: no page overflow, five cards stacked in
order, every control reachable); every field can still be filled and the form submitted; no data is
affected; and the design's own `## Rule: form copy vs. tooltip vs. cut` puts all four of these
strings in bucket 3 — background or definitional, explicitly "not needed to fill the field correctly
the first time." A member at 360px is under-informed, not blocked. Under this project's severity
definitions that is "materially violates the … standards", which is Major. **Major blocks approval
here regardless**, so the practical routing is identical either way; I would rather name the band
accurately than inflate it.

**The reason it belongs at the top of the Major band, and not lower.** The design deliberately *cut*
these four sentences from the form on the strength of the tooltip carrying them — that is the whole
of the Owner's criticism 3, "tooltips can carry what the prose currently does." At 360px they
demonstrably do not carry it, so on the minimum supported width this feature is a net loss of
information against the pre-#17 form, where the same sentences sat in wrapping `<p>` elements. That
is worth the Owner seeing plainly before deciding.

**Recommended resolution.** A Designer ruling on whether `## Responsive Behavior`'s refusal of
bespoke widths yields a `max-width` for tooltip content, and if so what it is — noting that the fix
cannot live in the generated primitive and that N1's two criticisms of `ReplayDialog.vue` should be
separated so its width cap is no longer rejected by association with its trigger. Then a Senior
Developer task applying it to the four call sites. Re-verification is cheap and should be measured,
not eyeballed: re-run the four width measurements at 360px and require every rendered width to be
`<= clientWidth`.

## Findings summary — re-review

| # | Severity | Status | Location |
|---|---|---|---|
| 1 | Major | **Resolved** — both card-level legends at `text-base font-semibold`, both nested legends untouched | `ProxyForm.vue:688`; `DestinationRows.vue:123` |
| 2 | Minor | **Resolved** structurally; my stated rationale was wrong and is corrected above | `ProxyForm.vue` — single hoisted `TooltipProvider` |
| 3 | Minor | **Resolved** — needs one routine refresh after this re-review | `docs/status.md` |
| 4 | Minor | **Partially resolved** — the `design.md` typography correction landed; six `## Consequences` amendments carried forward | `design-17` `## Consequences` |
| 5 | Major | **New, open** — all four tooltips clipped at 360px, 16–60% of each unreachable | `ProxyForm.vue` tooltip call sites; `design-17` `## Responsive Behavior` / N1 |

## Re-review recommendation

- **Recommendation:** ~~**Request changes** — on Finding 5 alone.~~ **SUPERSEDED by
  `## Close-out (2026-08-29)` below**, which records Finding 5 resolved. The text of this
  re-review's recommendation is retained unedited as the record of what was recommended at the
  time; see the close-out for the live recommendation.

Everything raised at the first pass is closed, and the rework is exemplary in shape: two product
files, three content changes, zero copy drift, zero accessibility drift, all six gates green, and
both upstream artifacts corrected in place with the original gate records left readable rather than
rewritten. Findings 1 and 3 are fully resolved, Finding 2 is resolved with a correction to my own
reasoning, and Finding 4's most misleading part is fixed with the rest carried forward as
non-blocking follow-ups.

Finding 5 is new, was missed by both the Senior Developer and by me at the first pass, and originates
in the design rather than the implementation. It blocks approval as a Major, and it needs the same
Designer-then-Developer routing that resolved Finding 1 — which took one amendment and one
one-class commit, so this is not expensive to close.

If the Project Owner judges that clipped background copy at 360px is acceptable for now — a
defensible call, since all four strings are bucket-3 content and the form itself is fully usable at
that width — then this becomes **Approve with follow-ups**, with Finding 5 carried as the first
follow-up. That call is the Owner's; this review does not make it.

- **Project Owner decision / date:** _superseded — see the close-out recommendation_

## Re-review handoff

- **Inputs:** branch `feat/design-17-form-restructure` at `837601b`; `design-17`
  `## Amendment — card-level legend heading weight ruling` (2026-08-29, Designer, self-certified);
  `docs/tasks/proxy-form-information-architecture-tasks.md` T6 correction and T9;
  `docs/standards/design.md` typography correction; `docs/status.md`; the main session's browser pass
  and this Reviewer's own 360px tooltip measurement at the same commit.
- **Outputs:** this re-review, appended in place.
- **Dependencies:** Finding 5's fix depends on a Designer ruling before any code change, because the
  design's `## Responsive Behavior` and note N1 currently forbid the only permitted fix and the
  generated primitive may not be hand-edited.
- **Outstanding Questions:** for the Designer — does `## Responsive Behavior`'s refusal of bespoke
  tooltip widths yield a `max-width` for `TooltipContent` at the four `ProxyForm.vue` call sites, and
  what value; and should N1 be split so that its correct rejection of `ReplayDialog.vue`'s
  non-focusable trigger no longer carries with it the rejection of that component's width cap?
- **Next Agent:** Project Owner (decision), then Designer (Finding 5 ruling), then Senior Developer
  (apply to the four call sites), then Reviewer (second re-review, appended in place below this
  section).

---

# Close-out (2026-08-29)

- **Reviewer / date:** Reviewer, 2026-08-29
- **Scope:** branch `feat/design-17-form-restructure` at `b663280`, three commits since the
  re-reviewed `837601b`: `c5c1d43` (Designer's second amendment,
  `## Amendment — tooltip content width cap`), `528550e` (Task Planner adding T10 and correcting
  T9's inherited delay-grouping claim), `b663280` (Senior Developer implementing T10).
- **Purpose:** close Finding 5 and give a final recommendation. Findings 1–4 were settled in the
  re-review above and are not re-opened here.

## Gates — re-run at `b663280`

| Gate | Result observed |
|---|---|
| `composer lint` | `{"tool":"pint","result":"passed"}` |
| `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `./vendor/bin/sail test --parallel` | `{"tool":"paratest","result":"passed","tests":1019,"passed":1019,"assertions":4818,"duration_ms":10131}` |
| `pnpm types:check` | clean, exit 0 |
| `pnpm format:check` | "All matched files use Prettier code style!" |
| `pnpm lint:check` | 0 files with errors outside `.claude/worktrees`; `npx eslint resources/` exits 0 silently |
| `pnpm build` (host) | `✓ built in 2.14s`, exit 0 |

Suite count unchanged at 1019/4818 for the third consecutive run, and the branch still contains no
test file — the delta is structurally zero, not merely observed to be.

## Finding 5 (Major) — RESOLVED

**The diff is four lines.** `git diff 837601b..b663280 -- resources/js/pages/proxies/ProxyForm.vue`
shows exactly four changed lines, one per call site, each `<TooltipContent>` becoming
`<TooltipContent class="max-w-xs">`. Nothing else in the file moved. All four
`TooltipContent` open tags in the file carry the class — none was missed.

**Nothing else drifted**, each checked rather than assumed:

- **Copy:** the normalized visible-text extraction reports an **empty delta** against `837601b` for
  both `ProxyForm.vue` and `DestinationRows.vue`. No help string, tooltip body, label, legend or
  `Alert` bullet changed.
- **Trigger markup:** filtering the diff for `TooltipTrigger`, `aria-label`, `Button`, `Info` and
  `TooltipProvider` returns **zero** matching lines — no trigger, accessible name or provider was
  touched. All four `aria-label`s are still the ones reviewed at the first pass.
- **Accessibility wiring:** every `aria-describedby` token in `ProxyForm.vue` still resolves against
  a declared id; no dangling references, no duplicate ids.
- **Finding 1's fix is intact:** the two card-level `legend`s are still `text-base font-semibold`
  and the two Delivery-nested ones still `text-sm font-medium`.

**The generated primitive was not hand-edited, which was the constraint that made this a Designer
question.** `resources/js/components/ui/tooltip/TooltipContent.vue` has a zero diff for this commit,
and more strongly, `git diff main..b663280 -- resources/js/components/ui/` is **empty across the
entire branch** — no file under the generated `ui/` tree was touched at any point in this feature.
That satisfies `docs/standards/coding.md` → Project structure, restated in
`docs/standards/review.md`'s checklist.

**Measured independently by the Reviewer, not accepted from the completion note or the
hand-off.** Driven headless at 360×800 against a freshly host-built bundle at `b663280`, each
trigger focused by keyboard and its `[data-slot="tooltip-content"]` measured:

| Tooltip | Before | After | `left` | `right` | Computed `max-width` | Fits in 360px |
|---|---|---|---|---|---|---|
| Mode | 892px | **320px** | 0 | 320 | `320px` | Yes |
| Backoff strategy | 744px | **320px** | 11 | 331 | `320px` | Yes |
| "Always hidden" | 469px | **320px** | 0 | 320 | `320px` | Yes |
| Response Body | 431px | **320px** | 0 | 320 | `320px` | Yes |

This reproduces the reported numbers exactly, including Backoff strategy's `left: 11` — Reka's
collision handling nudging it inboard, which is the primitive behaving as `## Responsive Behavior`
always expected once the element is small enough for repositioning to have somewhere to go.
`document.documentElement.scrollWidth` stays 360 throughout and every `left` is ≥ 0, so nothing is
clipped in either direction. Each trigger still carries an `aria-describedby` pointing at its
content.

**The capped text wraps rather than being clipped on the other axis** — the failure mode a width cap
can introduce. Checked on the worst offender: the Mode tooltip now renders 320×76px with
`scrollHeight` 76 equal to `clientHeight` 76, `overflow: visible`, at a 16px line-height, and its
full 158-character sentence is present in the DOM. It wraps to roughly five lines and is entirely
readable. **The information the design moved off the form is now genuinely carried by the tooltip at
the minimum supported width, which is what Finding 5 said it was not.**

**The upstream corrections are the right shape.** The Designer's amendment rules the exact class at
the call site, states plainly that "`## Responsive Behavior` and N1 were themselves wrong on this one
point" rather than recasting the implementation as at fault, and splits N1 into two
independently-verdicted criticisms — the non-focusable `span` trigger rejection stands, the
`max-w-xs` rejection is withdrawn. The Task Planner's T9 correction withdraws the manual timing
check that could neither pass nor fail, and leaves the Senior Developer's completion notes as
written with a correction note beneath, the same treatment T6 received. Both follow this project's
convention of pointing at a superseded record rather than rewriting it.

## Findings — final status

| # | Severity | Status |
|---|---|---|
| 1 | Major | **Resolved** (re-review) — both card-level legends at heading weight |
| 2 | Minor | **Resolved** (re-review) — single hoisted `TooltipProvider`; my original rationale corrected in place |
| 3 | Minor | **Resolved** (re-review) — `docs/status.md` block corrected |
| 4 | Minor | **Partially resolved** — the `design.md` typography correction landed; six `## Consequences` amendments carried forward as a non-blocking follow-up |
| 5 | Major | **Resolved** — all four tooltips capped at 320px, fitting and wrapping unclipped at 360px |

**No Blocker or Major remains open.**

## Final recommendation

- **Recommendation:** **Approve with follow-ups.**

Every Major is closed and verified by measurement rather than by claim. The feature delivers what
`design-17` specifies: five containers in pipeline order reading as visual peers, every copy
disposition matching the design's table verbatim, four accessibility obligations discharged, and all
four tooltips carrying their content readably at the minimum supported width. All six gates are
green, the backend is untouched, and the generated `ui/` tree was never hand-edited.

The rework across three review cycles stayed disciplined throughout — twelve changed lines of
product code in total after the first pass, zero copy drift at every step, and both upstream
defects corrected in their own artifacts by the roles that own them rather than worked around in
code.

**Carried as follow-ups, none blocking:**

1. **Finding 4 —** `design-17` `## Consequences` still lists six approved specs (`design-10`,
   `design-07`, `design-06`, `design-04`, `design-01`, `design-03`) describing copy and containers
   this restructure changed, plus the `docs/standards/design.md` note that `Tooltip` is now an active
   pattern for field-level explanatory copy. Owner-directed; the design amends none of them itself.
2. **`docs/status.md`** needs one routine refresh to record this close-out and the Owner's decision.
3. **A width cap belongs in the shared primitive eventually.** `max-w-xs` now sits at four call
   sites, and the next tooltip carrying a sentence anywhere in the app will hit the same defect
   unless its author remembers. The durable fix is a default `max-width` in
   `components/ui/tooltip/TooltipContent.vue`, which cannot be hand-edited — so it belongs in a
   regeneration or an upstream shadcn-vue change, not in this feature. Worth an ADR-level note
   rather than silent repetition.

- **Project Owner decision / date:** _pending_

## Close-out handoff

- **Inputs:** branch `feat/design-17-form-restructure` at `b663280`; `design-17`
  `## Amendment — tooltip content width cap` (2026-08-29, Designer, self-certified);
  `docs/tasks/proxy-form-information-architecture-tasks.md` T10 and the T9 correction note; the
  Reviewer's own 360px measurement of all four tooltips at this commit.
- **Outputs:** this close-out, appended in place.
- **Dependencies:** none. Nothing is gated on another role.
- **Outstanding Questions:** none.
- **Next Agent:** **Project Owner** — approval decision. No rework is outstanding.
