# Design Proposal: Proxy create/edit form — information architecture restructure

- **Status:** **Approved** (design gate, delegated per `CLAUDE.md`). The gate's
  five required corrections (C1–C5) have all landed — C3 and C4 were applied in
  place by the Product Manager as factual corrections; C1, C2 and C5 were design
  rulings landed by the Designer on 2026-08-28. All five were **specification
  gaps, not changes of intent** — they closed places where a Task Planner would
  otherwise have had to guess — and none required re-approval; none changed a
  user-visible outcome the gate approved. This document was commissioned by the
  Project Owner directly (2026-08-28) and is not an amendment to any approved
  spec and not tied to an approved PRD; the Project Owner has since directed the
  work through to implementation. See `## Approval record (design gate)` at the
  end of this document for the coverage trace against the Owner's brief and the
  five corrections' original findings. The approved design specs that need
  amending as a consequence are listed under `## Consequences` below, and that
  amendment work is not done here.
- **Author:** Designer
- **Approved by / date:** **Product Manager, 2026-08-29** (design gate, delegated).
  This is the last gate before task planning: `docs/architecture/adr-026-inbound-
  verification-removal-and-minimal-outbound-header-strip.md` `## Amendment A` records
  that the Owner-directed Principal Engineer sign-off gate stays lapsed, so no
  engineering sign-off is owed on this document.
- **Commissioned by:** Project Owner, 2026-08-28 — direct criticism of the shipped
  create/edit form ("too jumbled and overwhelming"), with five specific complaints
  quoted throughout this document where they bear on a decision.
- **Scope:** `resources/js/pages/proxies/ProxyForm.vue`, `Create.vue`, `Edit.vue`,
  `resources/js/components/DestinationRows.vue`, as they exist on merged `main`.
  Roadmap item #10 (sensitive-data handling) shipped and merged as `48fe720`;
  inbound verification, which item #10 had introduced, was subsequently removed
  from the product in full, per `docs/architecture/adr-026-inbound-verification-
  removal-and-minimal-outbound-header-strip.md` (Decision B, Accepted, Project
  Owner, 2026-08-28). `main` has been merged into this branch at `a9d6ca2`, so
  this document is written against the real, shipped form, rather than against a
  forecast of it.
- **PRD:** none. This document was previously written against
  `docs/product/prd-16-configurable-inbound-verification.md` (Draft) as a stated
  dependency for the Inbound container's internal shape. **PRD-16 is withdrawn**,
  not declined — ADR-026 § *Documents*: "It is withdrawn, not amended... There is
  nothing left for it to configure: it describes how a member would express a
  verification construction for a capability the product no longer has." This
  re-based document depends on no Draft PRD. Requirement-level questions this
  proposal surfaces are routed to the Product Manager under `## Open Questions`,
  not answered here.

## Revision note (2026-08-28, re-based)

This document was drafted, and twice revised, against PRD-16 (Draft) — a member-
configurable template model for inbound verification — while the Verification
control it designed for was still a live part of the product. **PRD-16 is
withdrawn.** The Project Owner ruled on 2026-08-28 that this proxy is a fan-out
service, not a security layer ("our proxy is not a security layer, it's to
support fan out," recorded in ADR-025), and completed that ruling the same day
with a second: inbound verification is removed from the product in full — no
scheme, no secret, no header name, no rejection path, no surface ("We are no
longer validating when ingesting, just fanning," `docs/architecture/adr-026-
inbound-verification-removal-and-minimal-outbound-header-strip.md`, Decision B,
Accepted). That removal shipped on `feat/item-10-sensitive-data` and merged to
`main` as `48fe720`; this branch has `main` merged into it at `a9d6ca2`, so the
working tree this document is now written against is the real, shipped form.

This is a **re-basing, not a revision in place**. What survives is the base
restructuring the Owner's brief actually asked for, none of which ever depended
on PRD-16: the five-container grouping in pipeline order, the copy-rewrite pass,
and the cut/tooltip/keep rule. `## Grouping proposal`'s ordering principle, the
`## Copy rewrite pass` sections for Details, Delivery, Sensitive fields and
Destinations/Credential, and `## Rule: form copy vs. tooltip vs. cut` stand
exactly as before their substance was drafted. What is deleted, not revised, is
everything this document built specifically for the Inbound container's
Verification control — the `### Inbound — Webhook secret` copy table, `## The
Inbound control — from two schemes to a template model`, and `## Custom-template
entry UX` — because the capability they designed for no longer exists and is not
coming back. The one container that used to hold Verification (`## Grouping
proposal`, container 2, previously named "Inbound") is ruled on fresh, below, now
that it holds nothing but Response. Every remaining section that referenced the
Provider picker, the Proof status line, or the still-unwritten `design-16` is
swept the same way.

The prior text, in both its PRD-10-only and PRD-16-dependent forms, is not
reproduced here — it remains in this branch's own history (most recently at
commit `acc3325` and the two `wip` commits that followed it), per this
document's own established practice of citing history by commit rather than
carrying dead prose forward.

**Status when this re-basing was written: Draft**, awaiting a ruling. **Superseded
by the header above:** the re-based document was taken to the delegated design gate
and approved by the Product Manager on 2026-08-29 with five required corrections. This
paragraph is retained as the record of what the re-basing itself claimed at the time
it was written, not as a live status.

## Overview

The proxy create/edit form today is a single `Card` holding every field — proxy
identity, delivery mechanics, sensitive-field handling, and destinations — in one
undifferentiated column, described in prose pitched at a reader who does not know
what a webhook is. (Item #10 added the Sensitive fields section and the
destination Credential subsection to that same column; it also added a
Verification section, which ADR-026 has since removed from the product in full.)
This proposal splits the column into five bordered containers, ordered by where
each group of fields sits in a request's actual lifecycle through the system
(the immediate synchronous reply → delivery mechanics → data handling →
destinations), and rewrites the copy inside each container against a stated rule
for what stays on the form, what moves to a tooltip, and what is cut outright.

Of the five criticisms in the Owner's brief (`## The problem, restated from the
brief`, below), this document answers three — grouping, copy pitched at a reader
who doesn't know what a webhook is, and the tooltip/cut rule. The remaining two
named the Verification control specifically: its copy, and the shape of its
on/off choice. ADR-026 has since resolved both by removing the control they
described, not by redesigning it — there is no Verification section left on the
form for either criticism to be about, and this document does not attempt to
design a replacement, because the Project Owner's ruling was that none is
wanted.

## The problem, restated from the brief

Five criticisms, each traced to a decision below:
1. **No grouping** — fields run together in one long column → `## Grouping proposal`.
2. **Copy pitched at a reader who doesn't know what a webhook is** → `## Copy rewrite
   pass`.
3. **Tooltips can carry what the prose currently does** → `## Rule: form copy vs.
   tooltip vs. cut`.
4. **The Verification section's copy names no recognizable concept, and states the
   obvious** ("off by default" on a control that already shows it is off). **Moot,
   not addressed here.** ADR-026 (Project Owner, 2026-08-28, Decision B) removed
   the Verification section from the product in full; there is no copy left to
   name a concept badly, or to state the obvious about.
5. **"My sender already implements Standard Webhooks" means nothing; the Owner
   wants a plain on/off that still maps to the existing column, with a header
   field pre-filled from the specification default, editable.** **Moot, not
   addressed here**, for the same reason as item 4: the control this criticism
   describes no longer exists. The tension this item originally raised — that a
   plain on/off cannot, by itself, represent a choice between two schemes that
   verify differently — is resolved by there being no scheme, and no choice,
   left at all.

## Grouping proposal

**Ordering principle: pipeline order, the same principle `design-10` already
established for placing its own Verification section** ("verification gates
whether a request is ever captured at all, which happens before retry policy has
anything to govern — the section order follows the pipeline order," `design-10`
Screen 1 — retained in that document as history now that the section itself is
withdrawn, but the ordering principle it stated is the one this proposal
extends). This proposal extends that same principle to the whole form rather
than inventing a second one: every field is placed according to *when*, in a
request's life, the setting it controls first has an effect — the immediate
synchronous reply, the asynchronous delivery mechanics, what the stored data
looks like afterward, and finally where it is sent.

| # | Container | Fields | Why this grouping |
|---|---|---|---|
| 1 | **Details** | Name | Identity. No conditionality, always relevant, always first — unchanged from today. |
| 2 | **Response** | Status code, Body | The *synchronous* exchange with the sender: whether the request is accepted, and what is written back immediately. Per `ADR-004` (upstream-response decoupling), the response is sent "independently of whether delivery to your destinations succeeds" — it has nothing to do with delivery mechanics, and today's placement (between Retry policy and Sensitive fields) obscures that. **This container held Verification too, until ADR-026 (Project Owner, 2026-08-28, Decision B) removed inbound verification from the product in full.** Response is what is left, and it keeps this position — first after Details, before Delivery — on its own terms: the pipeline-order principle above places it here because a request's synchronous reply happens before any of Delivery's asynchronous mechanics run, not because it once shared a container with a gating control. Named for its own content, the way every other container here is (Sensitive fields, Destinations), rather than for a pipeline stage ("Inbound") that no longer holds more than one thing. |
| 3 | **Delivery** | Mode, Processing, Retry policy (Enhanced only) | Everything governing how this proxy hands events to its destinations asynchronously — ordering, retry attempts, backoff. None of it affects the synchronous exchange above. |
| 4 | **Sensitive fields** | Sensitive fields list (defaults + additions) | A distinct concern from both of the above: what the *stored and replayed* payload looks like when a member views it later. Not about arrival, not about delivery — about data handling after the fact. |
| 5 | **Destinations** | URL, Method, Credential (per row) | Unchanged position — the form's last section today and in `design-10`, and it stays last: "where it goes," the final pipeline stage, is the natural close. |

**Ruling on the container question.** The Inbound container held two field
groups, Verification and Response; with Verification removed, the honest options
were to rename the container for what remains (Response), fold Response into an
existing container, or drop to four containers. **Ruled: rename, don't fold or
drop.** Folding Response into Delivery would undo the one thing `design-10`'s
own pipeline-order principle already established about it — that its `ADR-004`
decoupling from delivery outcome is exactly why it needs to be read apart from
Mode/Processing/Retry policy, not beside them. Folding it into Details would
conflate a piece of the request pipeline with the form's own identity field,
which has no pipeline position at all — Details is metadata about the proxy, not
a stage a request passes through. Dropping to four containers by absorbing
Response's two fields into whichever neighbor was left would reintroduce the
same problem under a different name — exactly the "fields run together"
criticism this proposal exists to fix, on a smaller scale. A container named for
what it now holds, keeping its position because the pipeline-order principle
justifies that position on its own merits (not by association with a removed
field), is the only option that neither re-litigates a settled design call nor
pretends nothing changed. **The container count stays five, and the order is
unchanged everywhere it appears in this document** (`## Screens & States`,
`## Components`).

This is a **regrouping of existing fields into existing components**, not new
fields and not a new interaction pattern: every field keeps its current `v-model`
binding, validation, and submit behavior. The only structural change is that the
form's single `Card` becomes five stacked `Card`s (`space-y-6`, the spacing
increment `docs/standards/design.md` already documents for "stacked-section
spacing"), each headed the same way `proxies/Show.vue` already heads its own
stacked cards (`<h2 class="text-base font-semibold">`) or, where a card wraps a
single `fieldset`/`legend` group (**Sensitive fields and Destinations** —
corrected by the Product Manager, 2026-08-29, correction C4: the original text
named "Retry policy, Destinations" here, but `## Screens & States` Screen 1 puts
Retry policy inside the **Delivery** card, which carries its own `h2`, and puts
Sensitive fields in a card of its own with no `h2`; Sensitive fields and
Destinations are the two single-`fieldset` cards this rule governs), the `legend`
carries that same visual weight instead of a redundant second heading. Details
and Response each hold exactly one field, or one ungrouped pair of fields, and
need no nested `fieldset` at all — the plain `h2` is the whole of their heading,
the same precedent Details already sets.

Note one deliberate rebalancing worth naming explicitly: **Response moves out of
its current position (after Retry policy, before Sensitive fields) and into its
own container, second in the stack, directly after Details.** This is the one
place this proposal changes what's grouped with what, not just how it's drawn —
justified above by `ADR-004`'s decoupling, and called out here so it isn't missed
as an incidental reflow.

## Copy rewrite pass

Every row quotes the copy as it stands today in the shipped `ProxyForm.vue`/
`DestinationRows.vue`, the proposed on-form text, and whether any remainder moves
to a tooltip. The Owner's own worked example is applied literally to every other
section — a verbose, non-developer-pitched paragraph is cut to the label plus, at
most, one short decision-relevant line; anything else becomes a tooltip or
nothing.

### Details

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Name | Label "Name"; help "A name to recognise this proxy." | Label "Name" only. | none — the label plus the existing placeholder (`Stripe → billing services`) already say everything a developer needs. |

### Response

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Section heading | (no heading — two fields inline in the shared column) | **"Response"** (the card's own `h2`, no nested `fieldset`/`legend` needed — this container holds exactly one field group, the same precedent `## Grouping proposal` sets for Details). | — |
| Status code | Label "Response status code"; help: *"The HTTP status returned to the sender the moment the webhook is received — an acknowledgement, sent immediately and independently of whether delivery to your destinations succeeds. Choose 200, 202, or 204; 204 (No Content) sends an empty body. Leave as Default to return 202 Accepted."* | Label **"Status code"**; help trimmed to **"Sent immediately, before delivery — independent of destination outcome."** Kept on-form because it corrects a real, plausible misreading (that this status reflects delivery success) rather than merely describing the field. | The status-option specifics (200/202/204, 204 forces an empty body, default 202) are already stated by the `Select` items themselves — cut from prose rather than duplicated in a tooltip. |
| Body | Label "Response body"; help: *"An optional fixed body returned with the acknowledgement (for example a verification challenge echo). It is a static reply, not a delivery report, and never reflects your destinations' responses. Leave blank for an empty body; 204 (No Content) always sends an empty body, so this field is disabled when 204 is selected."* | Label **"Body"**; help trimmed to **"Optional. Disabled when Status code is 204."** | *"Useful for a verification challenge echo some senders require during setup."* — the "why would I use this" rationale, worth keeping on demand rather than inline every time. |

### Delivery (Mode, Processing, Retry policy)

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Mode help | *"Enhanced mode stores the payload actually dispatched, separately from the payload received, and lets this proxy configure its own retry attempts and backoff strategy below. Automatic retry, payload capture, retention, and replay apply to every proxy regardless of Mode."* | **"Enhanced stores what was actually dispatched and unlocks the retry settings below."** | *"Automatic retry, payload capture, retention, and replay all apply regardless of Mode — this only affects dispatched-payload storage and the retry settings below."* — the disambiguation is real but is background, not something a member needs re-read every visit. |
| Downgrade disclosure (Enhanced → Simple, `design-07` AC13/AC14(c)) | Three-bullet `Alert`, full sentences | **Unchanged, verbatim.** This is a multi-step consequence statement about real, non-obvious data and behavior effects — the class of content this project's own communication conventions carve out from any terseness pass. It moves with Mode into the Delivery card but its text is untouched. | — |
| Processing help | *"Independent of the Mode setting above. Async (default) delivers this proxy's events to its destinations in parallel, with no guaranteed order — the right choice for most, higher-throughput traffic. FIFO delivers this proxy's events in the order they were received; it trades throughput for strict ordering, so FIFO is necessarily more serialized and slower than Async, not a free upgrade."* | **"Async (default) delivers in parallel, no order guaranteed. FIFO preserves order, at lower throughput. Set independently of Mode."** | — |
| Retry policy fieldset help | *"Applies to automatic re-attempts after a failed delivery to a destination. Available on Enhanced-mode proxies; Simple-mode proxies use the fixed system default ({N} attempts, {strategy} backoff)."* | **Cut the first sentence** (redundant once the fieldset only ever renders directly under Mode = Enhanced in the same card — the adjacency now says it). Keep, trimmed: **"Simple-mode proxies use the fixed default ({N} attempts, {strategy})."** | — |
| Attempts help | *"Leave blank to use the default (5). Maximum 10."* | **"Default {N}. Max 10."** — `{N}` is an interpolation, not prose shorthand for the literal `5`. **Resolved — Designer, correction C5 (2026-08-28).** The shipped copy hard-codes "5" here while the Retry policy `fieldset` help immediately above it already interpolates the same value from `defaultAttemptLimit` (`ProxyForm.vue`, sourced from `RETRY_DEFAULT_ATTEMPT_LIMIT` in `@/data/proxyRetryBackoffStrategies`). **Ruled: switch Attempts' help to read the same `defaultAttemptLimit` value, not keep the hard-coded literal.** This is a copy trim plus a one-line source fix, not a copy trim only: the two help strings sit one field apart and must not be able to drift out of sync if the system default ever changes. `{N}` in this row's "Default {N}. Max 10." means the interpolated `defaultAttemptLimit` value, exactly as `{N}` already means in the Retry policy `fieldset` help row above. | — |
| Backoff strategy help | *"Exponential increases the wait between attempts each time; fixed interval waits the same amount every time. Either way, retries are always bounded well inside your team's 30-day payload retention window."* | **Cut from the form.** | *"Exponential increases the wait each attempt; fixed interval stays constant. Always bounded well inside the 30-day retention window."* — definitional, not decision-blocking; a developer picks based on the label text itself. |

### Sensitive fields

| Field | Current (`design-10` Screen 2) | Proposed on-form | Tooltip |
|---|---|---|---|
| Section help | *"Values in these fields are hidden wherever this proxy's stored payloads are shown. This never changes what's stored or what's delivered — see a payload's Reveal to check."* | **"Hidden wherever this proxy's payloads are shown. Storage and delivery are unaffected."** | — |
| "Case and separators don't matter" note | Inline paragraph under the default-list badges | **Cut from the form.** | *"Matches password, Password, pass_word, etc. — case and separators don't matter."* moved onto the "Always hidden" label as an on-demand note. |

### Destinations / Credential

`design-10`'s Screen 3 copy is already terse and is left as-is — it is the one
section of the pre-#17 form that already reads the way this proposal asks the rest
of the form to read, and is named here as the standard the rest is brought up to,
not a section that itself needs rewriting:
- "Sent verbatim on every dispatch to this destination — the product adds no scheme
  prefix (e.g. enter "Bearer abc123" yourself if your destination expects one)."
  stays, unchanged — decision-relevant, prevents a real bug.
- "Replacing takes effect on the next dispatch — there's no transition period." stays,
  unchanged — the direct contrast to Verification's now-withdrawn overlap disclosure,
  and needs to stay explicit for that reason regardless.

**Resolved — Designer, correction C2 (2026-08-28).** The `Destinations`
`fieldset` in `DestinationRows.vue` carries its own help line above the rows,
which no row of this copy pass previously covered:

> "The webhook is delivered to every destination below."

It is not part of `design-10`'s Screen 3 Credential copy (the two lines quoted
above), so "Screen 3's copy is left as-is" does not dispose of it. Its own row:

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Section help | *"The webhook is delivered to every destination below."* | **Keep, unchanged.** | — |

**Ruled: keep, not trim or cut.** It is a plausible rule-1 cut candidate under
`## Rule: form copy vs. tooltip vs. cut` — a `fieldset` legended "Destinations,"
holding a list of destination rows, could be read as already showing that the
webhook goes to each of them — but the sentence states a fact the rows
themselves do not: that delivery **fans out to every row**, not to a single
selected destination or the first one that succeeds. A developer scanning a
list of destination rows could plausibly read it either way without this line;
that makes it decision-relevant under rule 2, not a rule-1 restatement. It is
also already exactly the length and register the rest of this copy pass is
bringing the form up to — one short, plain sentence, no rewrite needed — the
same standard `design-10` Screen 3's Credential copy was already held to above.

**Accessibility consequence: none, because nothing about the line changes.**
It keeps `id="destinations-help"`, and every destination row's URL `input`
keeps pointing at it via `aria-describedby="destinations-help"` when that row
has no error, exactly as `DestinationRows.vue` ships today. No new
`aria-describedby` target is introduced and none needs to be.

## Rule: form copy vs. tooltip vs. cut

Three buckets, applied consistently above rather than field-by-field judgment calls
restated each time:

1. **Cut outright** when the sentence states something the control itself already
   shows (a toggle's current state, an option already spelled out in a `Select`
   item), or restates the label in different words. The Owner's own example is the
   test case: "Off by default" next to a control visibly showing Off fails this
   test and is cut, not shortened.
2. **Stays on the form, one short line, `text-sm text-muted-foreground`** when the
   fact is **decision-relevant** — a developer could plausibly fill the field
   wrong, or misjudge what it affects, without it. Examples: Response status "sent
   independently of delivery outcome" (prevents assuming it reflects delivery
   success); the destination credential's "no scheme prefix added" (prevents a
   silent auth failure).
3. **Moves to a tooltip** when the fact is **background or definitional** —
   correct and occasionally useful, but not needed to fill the field correctly the
   first time, and not something a returning member needs to re-read. Examples:
   what "exponential vs. fixed" backoff means, why a response body might be used
   (challenge echo), that obfuscation matching ignores case/separators. A tooltip
   is not a dumping ground for every trimmed sentence — a field with nothing
   background-only to say gets no tooltip at all (Name, the Sensitive-fields Add
   input, the Destinations URL/Method fields carry none in this proposal).

## Screens & States

### Screen 1 — Create / Edit Proxy form, restructured

```
Card "Details"
  h2 "Details"
  Name

Card "Response"
  h2 "Response"
  Status code
  Body

Card "Delivery"
  h2 "Delivery"
  fieldset "Mode and processing"
    Mode
    [Downgrade disclosure, v-if downgrading — unchanged]
    Processing
  fieldset "Retry policy" [v-if Enhanced]
    Attempts
    Backoff strategy

Card "Sensitive fields"
  fieldset "Sensitive fields"
    Always hidden (badges)
    Also hidden for this proxy (badges + add)

Card "Destinations"
  fieldset "Destinations"  (DestinationRows.vue, unchanged internals)
    [rows: URL, Method, Credential subsection, Remove]
    Add destination

Actions: Submit, Cancel  (unchanged, outside all Cards, at the form's end)
```

**States.** Every field's default/empty/loading/error/success state is exactly
the one already specified — `design-10` for Sensitive fields and Credential;
`design-07` for Mode and the downgrade disclosure; `design-06` for Retry policy;
the current shipped behavior for Name, Response, and Destinations. **No field
gains or loses a state in this proposal.** The only field group this document
ever added new states for — the Verification checkbox, the Provider picker, the
sample-request paste, the Signing-details `Collapsible`, and the Proof status
line — depended entirely on PRD-16's (Draft) template model. PRD-16 is
withdrawn and inbound verification is removed from the product in full
(ADR-026 Decision B, 2026-08-28); there is no such field group left to design
states for, and none is proposed here. This document is now purely a
regrouping and copy pass over fields that already exist and already carry their
approved states.

## Components

| Element | Component | Notes |
|---|---|---|
| Card sections | `Card` (×5, was ×1) | Reused, same primitive, more instances. |
| Card/fieldset headings | plain `h2`/`legend`, `text-base font-semibold` (h2) or existing `legend` weight | Reuses the exact pattern `proxies/Show.vue` already uses for its own stacked cards — no new heading component. |
| Tooltips | `Tooltip`/`TooltipTrigger`/`TooltipContent`/`TooltipProvider` (`ui/tooltip`) | **New usage for this purpose.** The primitive is generated and already in use in five places — **corrected by the Product Manager, 2026-08-29, correction C3**: the original text named only `AppHeader.vue` and `SidebarMenuButton.vue`, but `resources/js/pages/teams/Edit.vue`, `resources/js/pages/teams/Index.vue` and `resources/js/components/ReplayDialog.vue` use it too. The substantive claim stands and is confirmed against the shipped code: every existing usage is either an **icon-button action label** ("Remove member", "Cancel invitation", "Leave team") or a **truncation reveal** of text already on screen (`ReplayDialog.vue`, showing a destination's full method and URL), so this proposal is still the first place the primitive carries explanatory field copy. The corrected list is given so a Task Planner looking for precedent finds all of it. Trigger is an `Info` icon (`@lucide/vue`, already imported in `ProxyForm.vue` for the downgrade `Alert`) inside a small, keyboard-focusable button — never a bare hover-only element (see `## Accessibility`). |
| Everything else | unchanged | `Input`, `InputError`, `Alert`/`AlertTitle`/`AlertDescription`, `Badge`, `Button` — all reused exactly as `design-10`/`design-07`/current `ProxyForm.vue` already specify them. |

## Interactions

This proposal changes no interaction behaviour. It regroups already-shipped
fields into five `Card`s and trims copy; every `useForm()` state transition —
Mode/Processing's independent selects, the Enhanced/Simple retry-field
discard-and-reseed, the 204-forces-empty-body watcher on Response, the
Sensitive-fields add/remove, and every destination row's add/remove/focus and
secret Replace/Remove-credential flow — is untouched. The only interactions this
document ever proposed beyond the shipped form's own — the Verification
checkbox's discard-on-toggle, the Provider picker's pre-fill-and-discard-on-
change, editing a preset relabeling it to "Custom (from {Preset})", and the live
re-check triggered by pasting a sample request — depended on PRD-16's (Draft)
template model for a control (Verification) that ADR-026 Decision B has since
removed from the product in full. None of it is restated here; there is no live
control left for it to describe.

## Accessibility

- **Card/fieldset structure adds real semantic grouping, not just visual
  boxes.** Where a container holds more than one distinct group of related
  controls, each group is a `fieldset`/`legend` exactly as Retry policy and
  Destinations already are today — a single-field-or-single-group container
  (Details, Response) needs none. The Delivery card holds two such groups:
  **Mode and processing** (`legend` "Mode and processing" — Mode, the
  downgrade disclosure, and Processing) and **Retry policy** (Attempts,
  Backoff strategy, rendered only for Enhanced-mode proxies). This is a net
  accessibility improvement over today's shipped form, where Mode, Processing,
  and Response sit as unrelated sibling `div`s with no grouping semantics at
  all.
  > **Resolved — Designer, correction C1 (2026-08-28).** This bullet and
  > Screen 1 previously disagreed: this bullet named two `fieldset`/`legend`
  > groups in the Delivery card, but Screen 1's structure diagram showed only
  > one (`fieldset "Retry policy"`), with Mode, the downgrade disclosure and
  > Processing as bare children of the card and no legend text for a
  > Mode/Processing group. **Ruled: the Delivery card holds two
  > `fieldset`/`legend` groups, not one.** Mode, the downgrade disclosure, and
  > Processing are wrapped in a `fieldset` with `legend` text **"Mode and
  > processing"**; Retry policy keeps its own `fieldset`/`legend` exactly as
  > already specified. This is the reading that keeps the "net accessibility
  > improvement" claim above true in full — under the alternative (folding
  > Mode/Processing under the card's own `h2` with no nested `fieldset`), Mode
  > and Processing would still sit as ungrouped siblings inside the card, the
  > same failure mode this bullet exists to fix, just moved one level in. Screen
  > 1 is updated to show both `fieldset`s with this `legend` text; `##
  > Components`'s generic "Card/fieldset headings" row already covers this
  > pattern without naming specific groups and needs no further change.
- **Tooltip triggers must be keyboard-reachable and screen-reader-exposed, not
  hover-only** — Reka UI's `Tooltip` primitive shows on focus as well as hover by
  default, which this proposal relies on rather than re-implementing; each
  trigger is a real `button` with a discernible `aria-label` naming what it
  explains (e.g. `aria-label="More about the response status code"`), following
  the same "icon-only control needs a named target" rule
  `docs/standards/design.md` already states for Delete/Remove buttons. Content is
  linked via `aria-describedby`, not conveyed by the icon or hover state alone —
  this is the same objection `design-10`'s Accessibility section already raises
  against a `title`-only attribute (N1), applied here to the same failure mode in
  a new location.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s baseline — every
  primitive used here (`Card`, `Select`, `Tooltip`, `fieldset`) is an
  already-vetted Reka UI primitive or plain semantic HTML, composed the same way
  this app already composes them; nothing here introduces a new interaction
  pattern needing separate a11y validation.

## Responsive Behavior

- **Card stacking.** Five `Card`s stack vertically with `space-y-6` at every
  width — no side-by-side layout at any breakpoint, matching this app's existing
  "forms are single-column" convention (`docs/standards/design.md` → Established
  patterns). The form's outer wrapper (`mx-auto w-full max-w-3xl`) is unchanged.
- **Every field inside every card** keeps its existing responsive treatment
  exactly as specified where it was originally defined — the `w-full sm:w-64`/
  `sm:w-32` `Select`/`Input` sizing convention, the Destinations row's `sm:grid-
  cols-[1fr_auto_auto]`, the Credential `Collapsible`'s vertical stacking. Moving
  a field into a new container changes nothing about the field's own responsive
  rules.
- **Tooltip content** uses Reka UI's default `TooltipContent` positioning/
  collision handling (the same defaults `docs/standards/design.md` already relies
  on for `Dialog`/`AlertDialog` sizing) — no bespoke width or placement handling
  is introduced.
- **Minimum supported width:** 360px, the standing default from
  `docs/standards/design.md` — no feature-specific override; nothing in this
  restructuring narrows the form below what it already tolerates today.

## Create vs. Edit divergence

**They should not diverge.** The only differences between Create and Edit today
are field-*state*, not field-*presence* or container structure: Edit's write-only
field, the destination credential, renders its collapsed "Credential set —
changed {date}" status instead of a blank input, because `initial` carries
persisted values Create's `initial` never does. That is already exactly how
`ProxyForm.vue` is built — one component, one `initial` prop, states computed from
it — and this proposal changes none of that mechanism. Every container and every
field proposed above appears on both Create and Edit; nothing here introduces a
field, section, or card that is Edit-only or Create-only. A member creating a new
proxy simply sees the destination credential in its **Unset** state (`design-10`
Screen 3's own state table already covers this) rather than a form with sections
missing.

## Open Questions

1. ~~**To the Product Manager: does the Owner's "plain on/off" for Verification
   mean only the mechanics addressed here, or does it mean the member should
   never be asked to choose between the two schemes at all?**~~ **MOOT, closed
   by this re-basing — not reopened.** This question asked how to shape a choice
   the member would still make between verification schemes. The Project
   Owner's ruling of 2026-08-28, recorded in ADR-026 Decision B, removed inbound
   verification from the product in full: "We are no longer validating when
   ingesting, just fanning." There is no scheme, no checkbox, and no choice left
   for this question to be about. This is a stronger outcome than the question's
   own "declined" contingency contemplated — PRD-16 was not declined in favor of
   PRD-10's scheme registry; the entire capability the question presupposed is
   gone. Closed as moot, not answered.
2. ~~**To the Product Manager: does "a header field pre-filled with the
   specification's default header name, editable" apply only to the
   Custom-header scheme, or was it meant to make Standard Webhooks' three fixed
   headers editable too?**~~ **MOOT, closed by this re-basing — not reopened**,
   for the same reason as Question 1. There is no header field, no scheme, and
   no Standard Webhooks option left on this form for the question to
   distinguish between. Closed as moot, not answered.
3. **To the Product Manager: should renaming the form's "Verification" legend to
   "Webhook secret" also rename the proxy Show page's "Verification" card
   (`design-10` Screen 4)?** **Still open — this crosses into the approved
   `design-10` and is not this document's to close.** **Updated finding, not a
   ruling:** the premise has moved further since the question was written. This
   re-based document no longer proposes a "Webhook secret" legend, or any
   Verification legend, at all — the section that would have carried either name
   is deleted along with the rest of the withdrawn Inbound-control design (see
   `## Revision note` above), because ADR-026 removed the underlying capability
   rather than merely renaming it. Confirmed against merged `main`:
   `resources/js/pages/proxies/Show.vue` no longer has a "Verification" card at
   all — `design-10`'s own `## Amendment — inbound verification withdrawn
   (2026-08-28)` withdrew Screen 4 in full, and the shipped page's only
   remaining pipeline-adjacent card is **"Signing."** So there is, at present,
   nothing named "Verification" on either surface for a naming decision to
   reconcile. Whether that makes this question fully moot, or whether the
   Product Manager judges some other consistency check still owed, is the
   Product Manager's call — this document does not make it.

   **RULED — Product Manager, 2026-08-29: moot. Closed, and no `design-10`
   amendment is owed on naming grounds.** The Designer's finding is confirmed
   against the shipped code on this branch, which has `main` merged in:
   `resources/js/pages/proxies/Show.vue` has seven cards — **Deliveries, Trend,
   Ingest URL, Response, Destinations, Signing, Retry policy** — and none of
   them is named "Verification." `design-10`'s own inbound-verification
   withdrawal amendment (approved by the Product Manager, 2026-08-28) withdrew
   Screen 4 in full, so neither the approved spec nor the shipped page carries
   the name. This document, re-based, proposes neither a "Verification" legend
   nor a "Webhook secret" legend on the form. Both sides of the naming
   comparison the question posed are therefore empty, and a rename cannot
   propagate from a name that exists in neither place.

   **The further consistency check the question invited is answered too, and it
   passes.** The relevant question is no longer "does the rename propagate" but
   "do the form's five new container names agree with the Show page's card names
   for the same subject matter," since a member moves between the two surfaces.
   Checked against the shipped `Show.vue`: **Response** (form container 2) and
   **Response** (Show card, `Show.vue` `h2`) agree; **Destinations** (form
   container 5) and **Destinations** (Show card) agree; **Retry policy** (the
   `fieldset` nested in the Delivery card) and **Retry policy** (Show card)
   agree. The two form containers with no Show-page counterpart, **Details** and
   **Sensitive fields**, cannot disagree with anything. No container name
   proposed here collides with, or contradicts, a name already shipped on the
   Show page. **No amendment to `design-10`, and no amendment to any other
   approved spec, is required on naming grounds by this proposal.** The
   `design-10` amendments that `## Consequences` already lists — Screen 2's help
   copy and Screen 2/Screen 3's containers — are unaffected by this ruling and
   still stand.

   **This closes the last open question in this document. None remains open.**
4. **New, to the Product Manager: PRD-16 UX Direction point 9 (readable months
   later) is answered for the create/edit form here, but the dedicated read-only
   summary on the proxy Show page is out of this proposal's stated scope. Is a
   Show-page update to the Verification/Signing cards part of the same
   `design-16` effort PRD-16 routes to next, or a separate follow-on?**
   **RESOLVED by withdrawal — closed, no live successor.** PRD-16 is withdrawn
   (ADR-026 § *Documents*), and `design-16` will never be written — there is no
   "Next Agent: Designer" routing left for this question to resolve into.
   Closed.

Every control proposed here reuses an existing, already-shipped primitive
(`Select`, `Tooltip`, `fieldset`) — no data model, API, or dispatch-time
behavior changes are proposed by this document.

## Consequences

If the Project Owner accepts this proposal, the following **already-approved**
artifacts describe the current shape of fields this proposal regroups and
rewrites, and would need amending to stay accurate. None of them is edited by this
document; this is a list for whoever the Owner directs to carry the amendment:

- **`design-10-sensitive-data-handling.md`** — Screen 2's (Sensitive fields) help
  copy (trimmed per `## Copy rewrite pass` above) and its container (moves from a
  section inside the form's single `Card` into its own "Sensitive fields" card).
  Screen 3's (Destinations Credential subsection) help copy is left as-is by this
  proposal, but its container — now a card wrapping the existing
  `DestinationRows.vue` `fieldset` rather than a bare `fieldset` in the form's
  single `Card` — should be confirmed. **Screen 1 and Screen 4 (inbound
  verification) need no further amendment from this proposal**: ADR-026's own
  routed amendment, `## Amendment — inbound verification withdrawn
  (2026-08-28)`, already withdrew both in full and was approved by the Product
  Manager on 2026-08-28. **Open Question 3 has since been ruled (Product
  Manager, 2026-08-29): moot, and no further `design-10` amendment is owed on
  naming grounds** — neither the shipped `Show.vue` nor the approved spec
  carries a "Verification" name any longer, and every container name this
  proposal does introduce either matches its Show-page counterpart (Response,
  Destinations, Retry policy) or has none (Details, Sensitive fields). The
  Screen 2 and Screen 3 amendments named in this bullet are unaffected by that
  ruling and still stand.
- **`design-07-enhanced-mode-toggle.md`** — Mode's help copy (trimmed) and the
  downgrade disclosure's container (moves into the new Delivery card; its own
  copy unchanged).
- **`design-06-retry-replay.md`** — Retry policy fieldset's help copy (trimmed)
  and container placement (into Delivery, nested inside the same card as
  Mode/Processing).
- **`design-04-queued-processing.md`** — Processing's help copy (trimmed) and
  container placement (into Delivery).
- **`design-01-walking-skeleton.md`** — the form's overall section list and
  single-`Card` structure (Screen 2 of that spec), superseded by the five-card
  structure here; Name's help copy (cut).
- **`design-03-decoupled-upstream-response-show.md`** — if this spec is confirmed
  to own the create/edit form's Response copy (rather than that copy having been
  added directly against the PRD without a dedicated design update at the time),
  its Response field copy and the field's move into its own **Response** card.
  (Previously proposed as a sub-section of an "Inbound" card that also held
  Verification; that card no longer exists — Response now heads a card of its
  own. See `## Grouping proposal`.)
- **`docs/standards/design.md`** — one new-usage note worth folding into the
  standard once ratified: `Tooltip` as an active pattern for field-level
  explanatory copy (today generated but unused for that purpose). Not a new
  primitive, so this is additive documentation, not a stack change the Owner
  needs to approve. (The prior revision's `Checkbox`-as-settings-toggle note is
  withdrawn along with the Verification control that would have used it —
  nothing in this document introduces a new `Checkbox` usage.)

**Two items named by the prior revision no longer belong on this list, and are
recorded here so a reader who remembers that revision isn't left looking for
them:**

- **`prd-10-sensitive-data-handling.md` and `ADR-022`** — the prior revision
  named these because its Inbound-control design assumed PRD-16's reversal of
  PRD-10 AC50. That design is deleted (see `## Revision note` above); ADR-026
  has already superseded ADR-022 in full and already routed PRD-10's own
  amendment independently of this proposal. Nothing here adds to that.
- **`design-16`** — will never be written. PRD-16 is withdrawn, not merely
  declined: "It is withdrawn, not amended... There is nothing left for it to
  configure" (ADR-026 § *Documents*).

**Nothing under `docs/` other than this file has been touched to produce this
re-based proposal.** No approved spec, `docs/status.md`, or `.claude/automation/`
file was edited.

## Handoff

- **Inputs:** the Project Owner's verbal criticism of the shipped form
  (2026-08-28); the shipped `resources/js/pages/proxies/ProxyForm.vue`,
  `Create.vue`, `Edit.vue`, `DestinationRows.vue` on merged `main` (item #10,
  `48fe720`, plus ADR-026's inbound-verification removal, both merged into this
  branch at `a9d6ca2`); the approved `design-10-sensitive-data-handling.md` (as
  its own inbound-verification-withdrawal amendment left it, 2026-08-28);
  `design-01`, `design-04`, `design-06`, `design-07` for the fields they
  originally placed on this form; `docs/standards/design.md`.
- **Outputs:** this proposal, re-based.
- **Dependencies:** none, technical or otherwise. The prior revision was written
  against PRD-16 (Draft) and its feasibility study; PRD-16 is withdrawn (ADR-026
  § *Documents*), and every control this document now proposes reuses an
  existing, already-shipped primitive.
- **Outstanding Questions:** **none.** All four are closed. Open Questions 1 and
  2 were closed as moot by the re-basing (the control they concerned no longer
  exists); Open Question 4 was resolved by PRD-16's withdrawal — `design-16`
  will never be written; **Open Question 3 was ruled moot by the Product
  Manager on 2026-08-29**, with the naming-consistency check it invited answered
  and passing (see `## Open Questions` item 3).
- **Next Agent:** **Task Planner**, to break the corrected document down. All
  five required corrections recorded in `## Approval record (design gate)` have
  landed: C1 (the Delivery card's internal `fieldset` structure — ruled two
  `fieldset`/`legend` groups) and C2 (the Destinations `fieldset` help line —
  ruled keep, unchanged) were design rulings landed by the Designer; C5 (the
  Attempts help's `{N}`) was ruled an interpolation from the same source the
  Retry policy `fieldset` help already reads, also landed by the Designer; C3
  and C4 were factual corrections already applied in place by the Product
  Manager. No re-approval was required — none of the five changed a
  user-visible outcome the gate approved. Separately, the
  Project Owner directs which approved specs (`design-10`, `design-07`,
  `design-06`, `design-04`, `design-01`, `design-03`,
  `docs/standards/design.md`) are amended under `## Consequences` and by whom;
  this document amends none of them.

## Approval record (design gate)

**Approved by: Product Manager · 2026-08-29 · with five required corrections
(C1–C5).**

This gate covers the **re-based** document — the version that survives PRD-16's
withdrawal and ADR-026 Decision B's removal of inbound verification from the
product. It does not approve, and does not revive, any of the Inbound-control
material the re-basing deleted.

### The basis this design was judged against

There is no PRD-17, and none is owed. This work was commissioned by the Project
Owner directly on 2026-08-28 as criticism of a shipped surface, not as a new
capability: it adds no field, no data, no endpoint and no behaviour. The
requirements basis is therefore the Owner's own brief, restated by the Designer at
`## The problem, restated from the brief` and quoted throughout. The design was
judged against that brief on the three standards this project's delegated design
gate applies — that the design answers its requirements, that it invents none, and
that it is specific enough for a Task Planner to break down without guessing.

`docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-
header-strip.md` `## Amendment A` records that the Owner-directed Principal
Engineer sign-off gate stays lapsed, so this is the last gate before task
planning and it was applied as a real gate, not a formality.

### Coverage trace — the Owner's five criticisms

| # | Criticism | Where answered | Verdict |
|---|---|---|---|
| 1 | No grouping — fields run together in one long column | `## Grouping proposal`; `## Screens & States` Screen 1 | **Covered.** Five containers in pipeline order, each justified by when in a request's life its fields first take effect. The ordering principle is not invented here — it extends the one `design-10` Screen 1 already stated for this same form. The one substantive regrouping (Response moving out from between Retry policy and Sensitive fields) is called out explicitly rather than buried as a reflow, and is grounded in `ADR-004`'s decoupling of the acknowledgement from delivery outcome. Accepted as designed. |
| 2 | Copy pitched at a reader who doesn't know what a webhook is | `## Copy rewrite pass` | **Covered.** Every shipped help string is quoted verbatim, with a proposed replacement and a stated disposition. Verified below against the shipped components. |
| 3 | Tooltips can carry what the prose currently does | `## Rule: form copy vs. tooltip vs. cut` | **Covered, and better than asked.** The Owner asked for tooltips; the design supplies a three-bucket rule (cut / keep one line / tooltip) applied uniformly, with the Owner's own worked example ("off by default" on a control visibly off) as the test case for the cut bucket. It also states the anti-pattern — a tooltip is not a dumping ground for every trimmed sentence — and names the four fields that get none. |
| 4 | The Verification section's copy names no recognizable concept | Declared moot | **Correctly moot.** Verified against the shipped `resources/js/pages/proxies/ProxyForm.vue` on this branch: there is no Verification section, no scheme field, no secret field and no header-name field anywhere on the form. ADR-026 Decision B removed the control the criticism was about. There is no copy left to fix. |
| 5 | "My sender already implements Standard Webhooks" means nothing; the Owner wants a plain on/off | Declared moot | **Correctly moot**, same verification, same authority. The design does not attempt to invent a replacement control, which is right — the Owner's ruling was that none is wanted, and proposing one would have been inventing a requirement. |

Three of five answered, two moot by an Owner ruling that post-dates the brief. The
brief is discharged as far as the product still has surface for it.

### Invented requirements — none found

Every container, every field and every state in this document already exists on
the shipped form. The document says so and it holds up: `## Interactions` claims no
interaction behaviour changes, and the shipped `ProxyForm.vue` confirms that each
mechanism it names is present and untouched by anything proposed here — the
Enhanced/Simple retry discard-and-reseed, the 204-forces-empty-body watcher, the
sensitive-field add/remove, and `DestinationRows.vue`'s per-row credential
Replace / Remove-credential flow. **No new field, no new data, no new endpoint, no
new dependency, no new primitive.** The one genuinely new thing is a new *usage* of
an already-generated primitive (`Tooltip` carrying field copy), which is what
criticism 3 asked for.

### Verification of the document's factual claims about the shipped form

Checked against `resources/js/pages/proxies/ProxyForm.vue`,
`resources/js/components/DestinationRows.vue` and
`resources/js/pages/proxies/Show.vue` as they stand on this branch, which has
`main` merged in.

**The field inventory is complete and correctly assigned, with one omission
(C2).** Every field on the shipped form appears in `## Grouping proposal` and in
Screen 1: Name → Details; Response status code and Response body → Response; Mode,
the downgrade `Alert`, Processing and the Retry policy `fieldset` (Attempts,
Backoff strategy) → Delivery; the Sensitive fields `fieldset` (default badges,
per-proxy additions with their Remove buttons, the Add input and button) →
Sensitive fields; `DestinationRows.vue`'s rows (URL, Method, Remove destination,
the Credential `Collapsible`) and Add destination → Destinations. **No field named
in this document is absent from the shipped form, and no field on the shipped form
is missing from the document's inventory.**

**Every quoted "Current" copy string is verbatim-accurate.** All nine were compared
character-for-character with the shipped components: Name's help, the Mode help,
the Processing help, the Retry policy `fieldset` help, the Attempts help, the
Backoff strategy help, the Response status help, the Response body help, the
Sensitive fields section help, the "Case and separators don't matter" note, and the
two credential sentences in `DestinationRows.vue`. Each matches.

**Structural claims verified:** Response does sit between the Retry policy
`fieldset` and the Sensitive fields `fieldset` today, as `## Grouping proposal`
states; Mode, Processing and the two Response fields are indeed bare sibling
`div`s with no grouping semantics, as `## Accessibility` states; `Info` is already
imported into `ProxyForm.vue` from `@lucide/vue`; `proxies/Show.vue` does head its
stacked cards with `<h2 class="text-base font-semibold">`; and
`docs/standards/design.md` does document `space-y-6` as the stacked-section
spacing increment, single-column forms as the established pattern, and 360px as
the minimum supported width.

**One omission and one incomplete claim were found, and are C2 and C3 below.**

### Required corrections

All five are **specification gaps, not disagreements with the design**. None
changes a user-visible outcome this gate approved, and none requires re-approval.
Each is recorded in place, at the section it corrects.

- **C1 — the Delivery card's internal `fieldset` structure is unspecified.**
  `## Accessibility` says each group in the Delivery card is a `fieldset`/`legend`
  and names two groups (Mode/Processing, Retry policy); Screen 1 shows only one
  `fieldset` there and supplies no legend text for a Mode/Processing group. A Task
  Planner cannot resolve this by reading. The Designer must rule one way or the
  other and make the two sections agree. Recorded at `## Accessibility`.
- **C2 — one shipped help string is missing from the copy pass.** The Destinations
  `fieldset`'s own help line in `DestinationRows.vue` — "The webhook is delivered
  to every destination below." — has no row in `## Copy rewrite pass` and is not
  part of `design-10` Screen 3's credential copy, so "Screen 3 is left as-is" does
  not dispose of it. The Designer must state its treatment, and must handle the
  fact that it carries `id="destinations-help"` and is the `aria-describedby`
  target of **every** destination row's URL input. Recorded at
  `## Copy rewrite pass` → `### Destinations / Credential`.
- **C3 — the `Tooltip` precedent list was incomplete. Corrected in place by the
  Product Manager**; no Designer action needed. `## Components` named two files;
  five use the primitive. The document's substantive claim — that this is the
  first use carrying explanatory field copy — was checked against all five and
  stands. Recorded at `## Components`.
- **C4 — a parenthetical named the wrong two cards. Corrected in place by the
  Product Manager**; no Designer action needed. `## Grouping proposal` cited
  "(Retry policy, Destinations)" as the single-`fieldset` cards; per Screen 1 they
  are Sensitive fields and Destinations. Recorded at `## Grouping proposal`.
- **C5 — "Default {N}" is ambiguous between a literal and an interpolation.** The
  shipped Attempts help hard-codes "5" while the Retry policy help directly above
  it interpolates the same value; the copy table writes `{N}` for both. The
  Designer must say which this row asks for. Recorded at `## Copy rewrite pass` →
  `### Delivery`.

### Non-blocking notes

- **N1 — do not copy `ReplayDialog.vue` as the tooltip pattern.** Its
  `TooltipTrigger` wraps a bare `span`, which is not keyboard-focusable, and it
  sets a bespoke `max-w-xs` on `TooltipContent`. This document's `## Accessibility`
  section already requires a real focusable `button` with a discernible
  `aria-label` and `aria-describedby`-linked content, and its
  `## Responsive Behavior` section already declines bespoke widths. Both are
  right. The note exists only so a Task Planner searching for precedent does not
  find the weaker one first and follow it.
- **N2 — Response's move is the one change of substance, and it should survive
  task breakdown intact.** Everything else here can be described as "same fields,
  new boxes, shorter copy." Response moving to second in the stack is not that: it
  changes what is read next to what. It is well justified and is accepted as
  designed, but it is the one item a planner could quietly drop as an incidental
  reflow while keeping the containers. It should not be dropped.

### Ruling on Open Question 3

Ruled moot and closed at `## Open Questions` item 3 — no `design-10` amendment is
owed on naming grounds, and the broader form-versus-Show-page naming consistency
check the question invited was run and passes. **No open question remains in this
document.**
