# Design Proposal: Proxy create/edit form — information architecture restructure

- **Status:** Draft. This is a proposal, commissioned by the Project Owner directly
  (2026-08-28), not an amendment to any approved spec and not tied to an approved PRD.
  Nothing in this document is authorized until the Project Owner rules on it. If
  accepted, the specific approved design specs that would need amending are listed
  under `## Consequences` below, and that amendment work is not done here.
- **Author:** Designer
- **Commissioned by:** Project Owner, 2026-08-28 — direct criticism of the shipped
  create/edit form ("too jumbled and overwhelming"), with five specific complaints
  quoted throughout this document where they bear on a decision.
- **Scope:** `resources/js/pages/proxies/ProxyForm.vue`, `Create.vue`, `Edit.vue`,
  `resources/js/components/DestinationRows.vue` — plus, because Roadmap item #10 has
  not yet been merged to `main` (it is at Task Planning as of this writing), the
  **approved** `design-10` content that is about to land on this same form
  (Verification, Sensitive fields, the destination Credential subsection). This
  proposal is written against the form as it will exist once #10 ships, not only
  against what `main` runs today, because restructuring the form twice in quick
  succession is exactly the outcome the Owner's brief asks this proposal to avoid.
- **PRD:** none. Requirement-level questions this proposal surfaces are routed to the
  Product Manager under `## Open Questions`, not answered here.

## Overview

The proxy create/edit form today is a single `Card` holding every field — proxy
identity, inbound handling, delivery mechanics, and destinations — in one
undifferentiated column, described in prose pitched at a reader who does not know
what a webhook is. This proposal splits that column into five bordered containers,
ordered by where each group of fields sits in a request's actual lifecycle through
the system (arrival → inbound gate/acknowledgement → delivery mechanics → data
handling → destinations), and rewrites the copy inside each container against a
stated rule for what stays on the form, what moves to a tooltip, and what is cut
outright. It resolves, rather than sidesteps, the one control the Owner's brief
flags as broken: the Verification scheme picker, whose three-way `Select` is
replaced with a plain on/off control that gates a much shorter scheme choice, with
the tension between "plain on/off" and "two schemes that verify differently" stated
explicitly rather than designed away. The container structure is built to hold
Roadmap item #16 (configurable inbound verification) without another restructuring
once that item lands.

## The problem, restated from the brief

Five criticisms, each traced to a decision below:
1. **No grouping** — fields run together in one long column → `## Grouping proposal`.
2. **Copy pitched at a reader who doesn't know what a webhook is** → `## Copy rewrite
   pass`.
3. **Tooltips can carry what the prose currently does** → `## Rule: form copy vs.
   tooltip vs. cut`.
4. **The Verification section's copy names no recognizable concept, and states the
   obvious** ("off by default" on a control that already shows it is off) → `## Copy
   rewrite pass` (Verification row) and `## The Verification control`.
5. **"My sender already implements Standard Webhooks" means nothing; the Owner wants
   a plain on/off that still maps to the existing column, with a header field
   pre-filled from the specification default, editable** → `## The Verification
   control` — the sharpest single item in this brief, addressed at length because a
   plain on/off cannot, by itself, represent a choice between two schemes that verify
   differently, and PRD-10 approved exactly two.

## Grouping proposal

**Ordering principle: pipeline order, the same principle `design-10` already
established for placing its own Verification section** ("verification gates whether
a request is ever captured at all, which happens before retry policy has anything to
govern — the section order follows the pipeline order," `design-10` Screen 1). This
proposal extends that same principle to the whole form rather than inventing a
second one: every field is placed according to *when*, in a request's life, the
setting it controls first has an effect — arrival and gating, the immediate
synchronous reply, the asynchronous delivery mechanics, what the stored data looks
like afterward, and finally where it is sent.

| # | Container | Fields | Why this grouping |
|---|---|---|---|
| 1 | **Details** | Name | Identity. No conditionality, always relevant, always first — unchanged from today. |
| 2 | **Inbound** | Verification (scheme + secret + header), Response (status code + body) | Both concern the *synchronous* exchange with the sender only: whether the request is accepted, and what is written back immediately. Per `ADR-004` (upstream-response decoupling), the response is sent "independently of whether delivery to your destinations succeeds" — it has nothing to do with delivery mechanics, and today's placement (between Retry policy and Sensitive fields) obscures that. Verification precedes Response within the container because gating happens before a response is even composed. |
| 3 | **Delivery** | Mode, Processing, Retry policy (Enhanced only) | Everything governing how this proxy hands events to its destinations asynchronously — ordering, retry attempts, backoff. None of it affects the synchronous exchange above. |
| 4 | **Sensitive fields** | Sensitive fields list (defaults + additions) | A distinct concern from both of the above: what the *stored and replayed* payload looks like when a member views it later. Not about arrival, not about delivery — about data handling after the fact. |
| 5 | **Destinations** | URL, Method, Credential (per row) | Unchanged position — the form's last section today and in `design-10`, and it stays last: "where it goes," the final pipeline stage, is the natural close. |

This is a **regrouping of existing fields into existing components**, not new
fields and not a new interaction pattern: every field keeps its current `v-model`
binding, validation, and submit behavior. The only structural change is that the
form's single `Card` becomes five stacked `Card`s (`space-y-6`, the spacing
increment `docs/standards/design.md` already documents for "stacked-section
spacing"), each headed the same way `proxies/Show.vue` already heads its own
stacked cards (`<h2 class="text-base font-semibold">`) or, where a card wraps a
single `fieldset`/`legend` group (Retry policy, Destinations, and the two new
sub-groups inside Inbound), the `legend` carries that same visual weight instead of
a redundant second heading.

Note one deliberate rebalancing worth naming explicitly: **Response moves out of
its current position (after Retry policy, before Sensitive fields) and into the
Inbound container, next to Verification.** This is the one place this proposal
changes what's grouped with what, not just how it's drawn — justified above by
`ADR-004`'s decoupling, and called out here so it isn't missed as an incidental
reflow.

## Copy rewrite pass

Every row quotes the copy as it stands today (`ProxyForm.vue`, or `design-10`'s
approved text for fields not yet merged), the proposed on-form text, and whether
any remainder moves to a tooltip. The Owner's own worked example is applied
literally to every other section — a verbose, non-developer-pitched paragraph is
cut to the label plus, at most, one short decision-relevant line; anything else
becomes a tooltip or nothing.

### Details

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Name | Label "Name"; help "A name to recognise this proxy." | Label "Name" only. | none — the label plus the existing placeholder (`Stripe → billing services`) already say everything a developer needs. |

### Inbound — Webhook secret (was "Verification")

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Section legend | "Verification" | **"Webhook secret"** — the Owner's own suggested label, and the short, recognizable term the brief asks for. | — |
| Section help | *"Require an incoming request to prove it's really from your sender before anything is captured. Off by default — existing proxies are unaffected."* | **Cut entirely.** This is the Owner's own worked example: the "off by default" clause is dead weight once the control is a checkbox that visibly starts unchecked, and "prove it's really from your sender" restates what "Webhook secret" already says to a developer. | *"Rejected before capture — no event is stored for a request that fails this check."* This is the one fact worth keeping: it is not obvious from the control, and it is the answer to a real support question ("why didn't my request show up?"), so it goes on demand rather than nowhere. |
| Enable control | `Select` with three items: "Not required (default)" / "My sender already implements Standard Webhooks" / "My sender sends a shared secret in a header" | **`Checkbox` + Label "Require on incoming requests."** Plain on/off, no sentence to parse. See `## The Verification control` for the full resolution of what happens to the two schemes underneath. | — |
| Scheme (shown only when the checkbox is checked) | (the same `Select`, items above) | **`Select`, two items: "Standard Webhooks" / "Custom header."** Short, recognizable terms — "Standard Webhooks" is a name developers who use it already know on sight; "Custom header" says what the mechanism is without narrating it. | — |
| Standard Webhooks — Secret value help | *"The secret your sender issued you for this integration. This product never generates it for you — paste the value they gave you."* | **"Paste the secret your sender issued — not generated here."** One line; kept on-form (not tooltip) because it heads off a real wrong action (looking for a Generate button). | — |
| Standard Webhooks — "what your sender must send" block | Full paragraph plus a bulleted list plus a closing tolerance sentence | **Kept, tightened**: "Sender must send:" then the three header names and the HMAC description exactly as today (`webhook-id`, `webhook-timestamp`, `webhook-signature — one or more HMAC-SHA256 signatures, base64-encoded, space-delimited`), then one line, "Requests older than {tolerance} are rejected." This block is not decorative — `design-10` names it as the intended compensation for AC46 ("a member's own inspection is the debugging path"), so it stays visible rather than moving to a tooltip; only the surrounding sentences are trimmed. | — |
| Custom header (`shared-secret`) — Header name | Label "Header name"; placeholder `X-Signature` (a hint, not a value); help *"The header your sender sends the secret in. Case-sensitive as your sender configures it."* | Label **"Header"**; the field is **pre-filled with an actual default value, `X-Signature`**, not just a placeholder — the member keeps it or edits it. Help trimmed to **"Case-sensitive."** | *"The header your sender sends the secret in."* — the definitional half of the old help line, kept on demand. |
| Custom header — Secret value help | *"The exact value your sender will send in that header."* | **Cut.** "Secret" next to a header-name field the member just filled in needs no further explanation. | — |
| Replace (editing a set secret) | "Secret set — changed {date}" + Replace, with the 24-hour-overlap disclosure appearing once Replace is clicked (`design-10` C5 / Amendment ruling 2a) | **Unchanged.** This is a state disclosure with real operational consequences (a secret's active window), not descriptive prose — it stays exactly as `design-10` specifies it, full sentences, both branches (no overlap running / overlap already running). | — |

### Inbound — Response

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Section legend | (no legend — just two fields inline) | **"Response"** (fieldset legend, nested inside the Inbound card). | — |
| Status code | Label "Response status code"; help: *"The HTTP status returned to the sender the moment the webhook is received — an acknowledgement, sent immediately and independently of whether delivery to your destinations succeeds. Choose 200, 202, or 204; 204 (No Content) sends an empty body. Leave as Default to return 202 Accepted."* | Label **"Status code"**; help trimmed to **"Sent immediately, before delivery — independent of destination outcome."** Kept on-form because it corrects a real, plausible misreading (that this status reflects delivery success) rather than merely describing the field. | The status-option specifics (200/202/204, 204 forces an empty body, default 202) are already stated by the `Select` items themselves — cut from prose rather than duplicated in a tooltip. |
| Body | Label "Response body"; help: *"An optional fixed body returned with the acknowledgement (for example a verification challenge echo). It is a static reply, not a delivery report, and never reflects your destinations' responses. Leave blank for an empty body; 204 (No Content) always sends an empty body, so this field is disabled when 204 is selected."* | Label **"Body"**; help trimmed to **"Optional. Disabled when Status code is 204."** | *"Useful for a verification challenge echo some senders require during setup."* — the "why would I use this" rationale, worth keeping on demand rather than inline every time. |

### Delivery (Mode, Processing, Retry policy)

| Field | Current | Proposed on-form | Tooltip |
|---|---|---|---|
| Mode help | *"Enhanced mode stores the payload actually dispatched, separately from the payload received, and lets this proxy configure its own retry attempts and backoff strategy below. Automatic retry, payload capture, retention, and replay apply to every proxy regardless of Mode."* | **"Enhanced stores what was actually dispatched and unlocks the retry settings below."** | *"Automatic retry, payload capture, retention, and replay all apply regardless of Mode — this only affects dispatched-payload storage and the retry settings below."* — the disambiguation is real but is background, not something a member needs re-read every visit. |
| Downgrade disclosure (Enhanced → Simple, `design-07` AC13/AC14(c)) | Three-bullet `Alert`, full sentences | **Unchanged, verbatim.** This is a multi-step consequence statement about real, non-obvious data and behavior effects — the class of content this project's own communication conventions carve out from any terseness pass. It moves with Mode into the Delivery card but its text is untouched. | — |
| Processing help | *"Independent of the Mode setting above. Async (default) delivers this proxy's events to its destinations in parallel, with no guaranteed order — the right choice for most, higher-throughput traffic. FIFO delivers this proxy's events in the order they were received; it trades throughput for strict ordering, so FIFO is necessarily more serialized and slower than Async, not a free upgrade."* | **"Async (default) delivers in parallel, no order guaranteed. FIFO preserves order, at lower throughput. Set independently of Mode."** | — |
| Retry policy fieldset help | *"Applies to automatic re-attempts after a failed delivery to a destination. Available on Enhanced-mode proxies; Simple-mode proxies use the fixed system default ({N} attempts, {strategy} backoff)."* | **Cut the first sentence** (redundant once the fieldset only ever renders directly under Mode = Enhanced in the same card — the adjacency now says it). Keep, trimmed: **"Simple-mode proxies use the fixed default ({N} attempts, {strategy})."** | — |
| Attempts help | *"Leave blank to use the default (5). Maximum 10."* | **"Default {N}. Max 10."** | — |
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
  unchanged — the direct contrast to Verification's 24-hour overlap, and needs to
  stay explicit for that reason.

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
   silent auth failure); Standard Webhooks' three required headers (without them
   the sender's requests fail with nothing to inspect, per `design-10`'s AC46
   framing).
3. **Moves to a tooltip** when the fact is **background or definitional** —
   correct and occasionally useful, but not needed to fill the field correctly the
   first time, and not something a returning member needs to re-read. Examples:
   what "exponential vs. fixed" backoff means, why a response body might be used
   (challenge echo), that obfuscation matching ignores case/separators. A tooltip
   is not a dumping ground for every trimmed sentence — a field with nothing
   background-only to say gets no tooltip at all (Name, the Sensitive-fields Add
   input, the Destinations URL/Method fields carry none in this proposal).

## The Verification control — resolving on/off vs. two schemes

**Where this stands today.** `design-10` (approved) specifies a single `Select`
with three items: `none` ("Not required," the default), `standard-webhooks` ("My
sender already implements Standard Webhooks"), and `shared-secret` ("My sender
sends a shared secret in a header"). `ADR-022` fixes this as a **closed two-case
scheme registry** — the two non-`none` values verify a request in genuinely
different ways: Standard Webhooks computes an HMAC over a fixed structure and
requires three fixed header names (`webhook-id`, `webhook-timestamp`,
`webhook-signature`) with no per-proxy configuration of any of them; the shared
secret scheme checks one member-named header against one member-supplied value,
with no timestamp, no HMAC construction the member configures, nothing fixed by
an external spec. These are not two presentations of one idea — they are two
different verification mechanisms, and PRD-10 approved both.

**What the Owner asked for.** A plain on/off, still mapped to the existing
column, and — quoting the brief — "when it is on the member sees a header field
pre-filled with the specification's default header name, which they can keep or
edit."

**The tension, stated plainly.** A single on/off cannot, by itself, represent a
choice between two mechanisms that verify differently. Something has to carry
*which* scheme applies once the toggle is on — either that choice stays visible
in some short form, or the product silently decides it, and silently deciding
between two different cryptographic verification methods is not a call this
proposal is positioned to make. Separately, "a header field pre-filled with the
specification's default header name" describes a field that **only one of the two
schemes has**: Standard Webhooks' three headers are fixed by the external
specification and are not a per-proxy setting today (`ADR-022`) — there is no
single "header field" for that scheme to pre-fill. The shared-secret scheme *does*
have exactly one configurable header field, but it has no specification default —
it is arbitrary, chosen by whatever the sender happens to send, and today's field
carries only a placeholder hint (`X-Signature`), never an actual value.

**What this proposal does.** Two moves, kept deliberately separate so each can be
judged on its own:
- **The on/off is real and literal.** A `Checkbox` — "Require on incoming
  requests" — replaces the `Select`'s `none` state. Checked/unchecked is exactly
  the boolean the Owner asked for, and it maps to the same underlying column
  `design-10` already specified: unchecked submits the same "not required"
  sentinel the `Select`'s `none` item submits today (no data-model change; see
  `## Consequences`). This directly answers complaint 5's "plain on/off."
- **The scheme choice is kept, but shortened to two words each, and only shown
  once the toggle is on.** "Standard Webhooks" / "Custom header" replace the two
  full-sentence `Select` items. This is not the same complaint as "off by
  default" being dead weight — the scheme names are load-bearing information, not
  restated obviousness — but it does answer complaint 5's actual target, which
  the Owner named directly: *the phrasing* ("this doesn't mean anything to
  anyone"), not the existence of a choice. "Standard Webhooks" is, by the Owner's
  own suggestion elsewhere in the brief, exactly the kind of short label a
  developer recognizes on sight.
- **The "pre-filled, editable header" behavior is applied to the scheme that
  actually has a single configurable header — Custom header (`shared-secret`)** —
  by upgrading its field from a placeholder hint to a real default value
  (`X-Signature`), which the member keeps or edits, literally satisfying the
  Owner's description for that scheme. Standard Webhooks' three fixed headers are
  left exactly as `ADR-022`/`design-10` approved them: named, static text, not an
  input.

**What is a design decision here and what is not.** The Checkbox-plus-shortened-
Select shape above is this proposal's design judgment, and is offered as the
recommended default. But two things go beyond what a design spec can settle
unilaterally, named in `## Open Questions` rather than decided here: whether the
Owner's "plain on/off" was meant to remove the scheme choice from the member
entirely (which would mean the product picks a single scheme on its own, or the
two schemes get merged into one — either is a real requirement change against
`ADR-022`'s closed registry, not a copy or layout change); and whether "pre-filled
header, editable" was meant to extend to Standard Webhooks' fixed headers, which
would be a functional change to a specification-fixed value, not something this
proposal invents on its own authority.

## Where item #16 lands

Item #16 (configurable inbound verification, in Product Manager drafting) adds a
preset picker, a custom signed-string template built from tokens, an
algorithm/encoding choice, a signature header name, a signature-extraction rule,
a timestamp source, a tolerance, and a paste-a-real-request test gate. All of it
is inbound-gate configuration — it belongs inside the **Inbound → Webhook secret**
fieldset this proposal already creates, as a third scheme alongside (or
replacing/generalizing) "Custom header," not as a new top-level card. Concretely,
the container this proposal proposes already has:
- a single on/off gate (the `Checkbox`) that #16's whole feature sits behind,
  unchanged;
- a `Select` for "which scheme," which #16 can extend with a third item (a
  preset, or "Custom") without restructuring anything above it;
- an established pattern, already used twice on this same form (Standard
  Webhooks' static header block; the shared-secret header field), for rendering
  scheme-conditional fields underneath the `Select` — #16's template builder,
  algorithm/encoding choice, extraction rule, timestamp source, and tolerance
  input all render the same way, conditional on the same `Select`, inside the
  same fieldset.
This is stated as a placement observation, not a design for #16 itself — #16's own
fields, states, and the paste-a-real-request test gate are #16's design spec to
write once its PRD is approved. Nothing here invents any of #16's requirements.

## Screens & States

### Screen 1 — Create / Edit Proxy form, restructured

```
Card "Details"
  h2 "Details"
  Name

Card "Inbound"
  h2 "Inbound"
  fieldset "Webhook secret"
    Checkbox "Require on incoming requests" [i]
    [v-if checked]
      Select "Scheme" — Standard Webhooks | Custom header
      [scheme-conditional fields, per ## Copy rewrite pass]
  fieldset "Response"
    Status code
    Body

Card "Delivery"
  h2 "Delivery"
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

**States.** Every field's default/empty/loading/error/success states are exactly
those already specified — `design-10` for Verification, Sensitive fields, and
Credential; `design-07` for Mode and the downgrade disclosure; `design-06` for
Retry policy; the current shipped behavior for Name, Processing, Response, and
Destinations. **No field gains or loses a state in this proposal** — every change
here is layout (which container a field sits in) and copy (what its label/help
says), never the state machine behind it. The one net-new interactive element is
the Verification `Checkbox` itself, whose states are exactly a standard checkbox's
(unchecked/checked/disabled-while-`form.processing`, `aria-invalid` unused since
it has no validation of its own — the `Select` beneath it keeps its existing
"none selected yet" and error states unchanged).

## Components

| Element | Component | Notes |
|---|---|---|
| Card sections | `Card` (×5, was ×1) | Reused, same primitive, more instances. |
| Card/fieldset headings | plain `h2`/`legend`, `text-base font-semibold` (h2) or existing `legend` weight | Reuses the exact pattern `proxies/Show.vue` already uses for its own stacked cards — no new heading component. |
| Verification on/off | `Checkbox` (`ui/checkbox`) | **New usage** — this primitive exists and is generated, but today is only used for row-selection in `teams/Index.vue`/`Edit.vue`. Using it as a standalone settings toggle is a new usage pattern for this app, flagged per `docs/standards/design.md`'s "new components are flagged in the spec" convention (it is not a new *component*, only a new *use* of an existing one). No `Switch` primitive exists in this codebase and this proposal does not introduce one — `Checkbox` + `Label` is the existing accessible on/off idiom already generated here. |
| Verification scheme | `Select`/`SelectTrigger`/`SelectContent`/`SelectItem`/`SelectValue` | Unchanged primitive, shorter item labels, fewer items (two instead of three — `none` is now the `Checkbox`'s unchecked state, not a list item). |
| Tooltips | `Tooltip`/`TooltipTrigger`/`TooltipContent`/`TooltipProvider` (`ui/tooltip`) | **New usage for this purpose.** The primitive is generated and already imported (`AppHeader.vue`, `SidebarMenuButton.vue`) for icon-button affordances, but this proposal is the first place it carries explanatory field copy. Trigger is an `Info` icon (`@lucide/vue`, already imported in `ProxyForm.vue` for the downgrade `Alert`) inside a small, keyboard-focusable button — never a bare hover-only element (see `## Accessibility`). |
| Everything else | unchanged | `Input`, `InputError`, `Alert`/`AlertTitle`/`AlertDescription`, `Collapsible`, `Badge`, `Button` — all reused exactly as `design-10`/`design-07`/current `ProxyForm.vue` already specify them. |

## Interactions

- **Verification checkbox toggling off** clears the in-session `verification_scheme`
  and any in-session, unsaved scheme-conditional field values — the same "hidden
  field can never carry a stale value into submit" rule `ProxyForm.vue` already
  applies to the Retry-policy fieldset on a Mode change, and `design-10` already
  applies to a scheme change. No new discard rule is introduced; this is the
  existing rule, applied to one more control.
- **Toggling the checkbox back on** does not restore a prior in-session choice —
  the `Select` opens with no scheme selected (placeholder, not a default), the
  same "never silently pick a scheme for the member" stance `design-10`'s AC23
  already establishes for the initial state.
- Every other interaction (destination row add/remove/focus, secret Replace/
  Remove-credential flows, badge add/remove) is **unchanged** — this proposal
  moves fields between containers and shortens their copy; it does not touch a
  single piece of `useForm()` state-transition logic.

## Accessibility

- **Card/fieldset structure adds real semantic grouping, not just visual
  boxes.** Where a container holds more than one distinct group of related
  controls (Inbound: Webhook secret + Response; Delivery: Mode/Processing +
  Retry policy), each group is a `fieldset`/`legend` exactly as Retry policy and
  Destinations already are today — a single-field container (Details) needs none.
  This is a net accessibility improvement over today's shipped form, where Mode,
  Processing, and Response sit as unrelated sibling `div`s with no grouping
  semantics at all.
- **The Verification `Checkbox`** carries a programmatically associated `Label`
  ("Require on incoming requests"), keyboard-operable (Space to toggle, standard
  Reka UI behavior), and `aria-describedby` pointing at its tooltip content's id
  when the tooltip is open — mirroring the existing `aria-describedby` wiring
  every other field on this form already has for help/error text.
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
  primitive used here (`Card`, `Checkbox`, `Select`, `Tooltip`, `fieldset`) is an
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
fields (verification secret, destination credential) render their collapsed
"Set — changed {date}" status instead of a blank input, because `initial` carries
persisted values Create's `initial` never does. That is already exactly how
`ProxyForm.vue` is built — one component, one `initial` prop, states computed from
it — and this proposal changes none of that mechanism. Every container and every
field proposed above appears on both Create and Edit; nothing here introduces a
field, section, or card that is Edit-only or Create-only. A member creating a new
proxy simply sees every write-only field in its **Unset** state (Screen 1/3's own
state tables already cover this) rather than a form with sections missing.

## Open Questions

1. **To the Product Manager: does the Owner's "plain on/off" for Verification mean
   only the mechanics addressed here (a real checkbox replacing the `none` Select
   item), or does it mean the member should never be asked to choose between the
   two schemes at all?** This proposal keeps the scheme choice, shortened to two
   words, because PRD-10/`ADR-022` approved two schemes that verify differently
   and nothing in this commission authorizes merging or auto-selecting between
   them. If the Owner's intent was the latter, that is a functional change against
   an Accepted ADR and needs its own ruling before any design spec reflects it.
2. **To the Product Manager: does "a header field pre-filled with the
   specification's default header name, editable" apply only to the Custom-header
   scheme (as this proposal reads it), or was it meant to make Standard Webhooks'
   three fixed headers editable too?** `ADR-022` fixes those three header names to
   the external specification with no per-proxy configuration; making them
   editable would be a real functional change, and is exactly the kind of
   generalization item #16 (configurable inbound verification) is already being
   scoped to handle deliberately, rather than something this proposal should
   invent piecemeal against #10's approved, closed registry.
3. **To the Product Manager: should renaming the form's "Verification" legend to
   "Webhook secret" also rename the proxy Show page's "Verification" card
   (`design-10` Screen 4)?** Leaving the form saying "Webhook secret" while the
   Show page still says "Verification" would reintroduce exactly the kind of
   inconsistency this brief is trying to remove. This proposal does not touch
   Screen 4 (out of its stated scope), but the naming decision, if accepted,
   should be applied consistently by whoever amends `design-10`.

No item here is a Principal Engineer feasibility doubt — every control proposed
reuses an existing, already-shipped primitive (`Checkbox`, `Select`, `Tooltip`,
`fieldset`), and no data model, API, or dispatch-time behavior changes.

## Consequences

If the Project Owner accepts this proposal, the following **already-approved**
artifacts describe the current shape of fields this proposal regroups and
rewrites, and would need amending to stay accurate. None of them is edited by this
document; this is a list for whoever the Owner directs to carry the amendment:

- **`design-10-sensitive-data-handling.md`** — the largest single amendment.
  Screen 1's Verification section: legend text, the `Select`'s item count and
  labels (three items with sentence-length labels → a `Checkbox` plus a two-item
  `Select`), the "off by default" help sentence (cut), the Custom-header field's
  placeholder-to-default-value change, and the section's placement (moves from a
  standalone section into the new Inbound card, alongside Response). Screen 2's
  help copy (trimmed). Screen 3's help copy (left as-is per this proposal, but
  its container — now a card wrapping the existing `DestinationRows.vue`
  `fieldset` — should be confirmed). If Open Question 3 is ruled in favor of
  consistency, Screen 4's "Verification" card title on the Show page as well.
- **`design-07-enhanced-mode-toggle.md`** — Mode's help copy (trimmed) and the
  downgrade disclosure's container (moves from a standalone position into the new
  Delivery card; its own copy is unchanged).
- **`design-06-retry-replay.md`** — Retry policy fieldset's help copy (trimmed)
  and container placement (into Delivery).
- **`design-04-queued-processing.md`** — Processing's help copy (trimmed) and
  container placement (into Delivery).
- **`design-01-walking-skeleton.md`** — the form's overall section list and
  single-`Card` structure (Screen 2 of that spec), superseded by the five-card
  structure here; Name's help copy (cut).
- **`design-03-decoupled-upstream-response-show.md`** — if this spec is confirmed
  to own the create/edit form's Response copy (rather than that copy having been
  added directly against the PRD without a dedicated design update at the time),
  its Response field copy and the field's move into the Inbound card.
- **`docs/standards/design.md`** — two new-usage notes worth folding into the
  standard once ratified: `Checkbox` as a standalone settings-toggle idiom (today
  documented only for row selection), and `Tooltip` as an active pattern for
  field-level explanatory copy (today generated but unused for that purpose).
  Neither is a new primitive, so this is additive documentation, not a new rule
  the Owner needs to approve as a stack change.

**Nothing under `docs/` other than this new file has been touched to produce this
proposal.** No approved spec, `docs/status.md`, or `.claude/automation/` file was
edited.

## Handoff

- **Inputs:** the Project Owner's verbal criticism of the shipped form
  (2026-08-28); the current implementation of `ProxyForm.vue`/`Create.vue`/
  `Edit.vue`/`DestinationRows.vue`; the approved `design-10-sensitive-data-
  handling.md` (as the amendment gate left it, including its Amendment section);
  `design-01`, `design-04`, `design-06`, `design-07` for the fields they
  originally placed on this form; `docs/standards/design.md`.
- **Outputs:** this proposal.
- **Dependencies:** none technical — every control reuses an existing primitive.
  Roadmap item #16 (configurable inbound verification), still in Product Manager
  drafting, is the reason the Inbound container is built to hold more than it
  shows today; nothing here depends on #16 being drafted or approved first.
- **Outstanding Questions:** three, listed under `## Open Questions`, all
  directed to the Product Manager as the Owner's proxy on requirement-level
  reads of the brief.
- **Next Agent:** Product Manager — to route the Project Owner's ruling on
  whether to accept this proposal, and, if accepted, to answer the three Open
  Questions and direct which approved specs are amended and by whom. This
  document does not self-approve and does not amend any of the artifacts listed
  under `## Consequences`.
