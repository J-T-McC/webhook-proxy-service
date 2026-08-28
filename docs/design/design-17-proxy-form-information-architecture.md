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
- **PRD:** none for the base restructuring. **`docs/product/prd-16-configurable-
  inbound-verification.md` (Draft, awaiting Project Owner approval) is a stated
  dependency for the Inbound container's internal shape** — see `## Revision —
  reworked against PRD-16` below. Requirement-level questions this proposal
  surfaces are routed to the Product Manager under `## Open Questions`, not
  answered here.

## Revision note (2026-08-28, same day)

This proposal originally designed the Inbound container's Verification control
against PRD-10's closed two-scheme list — a `Checkbox` gating a two-item `Select`
("Standard Webhooks" / "Custom header"). **A Draft PRD, PRD-16, has since reversed
PRD-10's closed scheme list in favor of a template model** (a signed-string built
from tokens, plus chosen axes; presets are saved templates, not a second code
path). Because PRD-16 changes what sits *inside* the Inbound container rather than
whether the container exists, this document is revised **in place** rather than
left stale: `## Grouping proposal`'s five containers, the `## Copy rewrite pass`
sections for Details/Delivery/Sensitive fields/Destinations, and the form-wide
rule in `## Rule: form copy vs. tooltip vs. cut` are **unchanged** by this
revision. What changed is `## The Inbound control` (renamed from "The
Verification control") and everything downstream of it. **PRD-16 is Draft, not
approved** — every design decision below that depends on it is provisional in the
same way, and is stated as such rather than asserted as settled. If the Project
Owner declines PRD-16, this revision's Inbound-container design reopens along with
it, and the original Checkbox-plus-two-item-Select shape (preserved nowhere else
but in this proposal's prior committed revision, `acc3325`) is the fallback.

**Second revision, same day: `docs/architecture/prd-16-template-model-feasibility.md`
(Principal Engineer, Study — informational, not a decision).** Its worked examples
replace this document's placeholder provider names with real ones and real field
counts (`## The Inbound control` § The container, concretely), and it surfaces two
findings this role owns rather than the Principal Engineer — a coverage-honesty
gap and a safety-rule bypass — addressed in the new `## Coverage honesty, the
tolerance clock, and the literal-timestamp trap` section below. **The Project
Owner has added a Principal Engineer technical sign-off as an extra gate on this
specific design**, on top of the ordinary Product Manager design gate, because
the requirements underneath are still forming (PRD-16 Draft, Q-16-01 open). This
document is written for that reader too: `## The Inbound control` § Axis
extensibility check exists specifically so a technical reviewer can confirm every
axis the feasibility study names has a place on this form without re-deriving it
from the field tables.

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
flags as broken: the Verification picker, whose current three-way `Select` is
replaced with a plain on/off `Checkbox` gating a provider/template picker built to
PRD-16's (Draft) template model. The tension the Owner's brief opened between
"plain on/off" and "a choice that hides real differences underneath" turned out to
soften once PRD-16 is read — see `## The Inbound control` for the full account of
what changed and what a Draft status still leaves open. The container structure is
built to hold Roadmap item #16 without another restructuring once that item lands,
and this revision is the first proof of that claim: the same five containers this
proposal opened with absorb PRD-16's much larger field set without moving out of
the Inbound card.

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
   pre-filled from the specification default, editable** → `## The Inbound control`
   — originally the sharpest item in this brief, because a plain on/off cannot, by
   itself, represent a choice between two schemes that verify differently, and
   PRD-10 approved exactly two. **Largely resolved by PRD-16 (Draft)**, which
   replaces "two schemes" with "one template model plus two legacy holdouts" —
   see that section for the resolution and what still depends on the PRD being
   approved.

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
| 2 | **Inbound** | Verification (on/off, provider/template picker, secret, and — under PRD-16 (Draft) — the template/axis fields and proving surface), Response (status code + body) | Both concern the *synchronous* exchange with the sender only: whether the request is accepted, and what is written back immediately. Per `ADR-004` (upstream-response decoupling), the response is sent "independently of whether delivery to your destinations succeeds" — it has nothing to do with delivery mechanics, and today's placement (between Retry policy and Sensitive fields) obscures that. Verification precedes Response within the container because gating happens before a response is even composed. **PRD-16 (Draft) grows what "Verification" contains substantially — see `## The Inbound control` — but not which container it sits in.** |
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

**This subsection is rewritten against PRD-16 (Draft).** The prior revision's
two-item scheme `Select` is superseded by a provider/template picker — see `## The
Inbound control` for the full design and reasoning. The row-by-row copy table
below reflects that shape; rows unaffected by PRD-16 (the checkbox, the legend,
the section help) are unchanged from the prior revision and are repeated here so
this table stays a complete, standalone reference.

| Field | Current (shipped `design-10` shape) | Proposed on-form | Tooltip |
|---|---|---|---|
| Section legend | "Verification" | **"Webhook secret"** — the Owner's own suggested label, and the short, recognizable term the brief asks for. Unchanged by PRD-16. | — |
| Section help | *"Require an incoming request to prove it's really from your sender before anything is captured. Off by default — existing proxies are unaffected."* | **Cut entirely.** The Owner's own worked example: "off by default" is dead weight once the control visibly starts unchecked. Unchanged by PRD-16. | *"Rejected before capture — no event is stored for a request that fails this check."* |
| Enable control | `Select` with three items: "Not required (default)" / "My sender already implements Standard Webhooks" / "My sender sends a shared secret in a header" | **`Checkbox` + Label "Require on incoming requests."** Unchanged by PRD-16 — see `## The Inbound control`, Q1. | — |
| Provider (shown only when the checkbox is checked) | *(did not exist — PRD-16 adds this axis of choice)* | **`Select` "Provider"**: **GitHub**, **Stripe**, **Slack** (AC5's floor; more per Q-16-02), a divider, **Standard Webhooks**, **Shared secret only**, a divider, **Custom**. No default selection — placeholder "Choose a provider," never silently decided (PRD-16 AC37). | — |
| Sample request | *(did not exist)* | **`Textarea` "Sample request from your provider"**, placed directly under the Provider `Select` (rendered as soon as the checkbox is on, before a provider is even chosen — see `## The Inbound control` § UX Direction point 1) — a single paste target for the real request's headers and body. Help: **"Paste one real request. Used to check your configuration below — never stored, never dispatched (PRD-16 AC29)."** Kept on-form, not a tooltip: the retention promise is exactly the fact a careful developer would otherwise hesitate to paste a real request without. | — |
| Provider suggestion (only when a sample is pasted and no provider is chosen yet) | *(did not exist)* | A dismissible inline note under the Sample field: **"This looks like {Provider} — use the {Provider} preset?"** with **Use** / **Dismiss**. Never auto-applies (PRD-16 AC37) — see `## The Inbound control` § point 7. | — |
| Template (rendered once a provider — including Custom — is chosen) | *(did not exist)* | Label **"Signed string"**; `Input` (monospace), pre-filled from the chosen preset (or, for Custom, pre-filled with the minimum valid template, `{body}`) — never blank. Help: **"{body}, {timestamp} and {id} are replaced with values from the request; everything else is sent as-is."** | *"The exact bytes your provider's HMAC ran over. Must include {body} — a scheme that doesn't sign the body isn't verifying anything (PRD-16 AC22)."* — the "why" for the one rule worth explaining, per PRD-16 UX Direction point 4's own instruction that `{body}`'s reason "is the feature." |
| Header (rendered once a provider is chosen, except Standard Webhooks) | *(did not exist as an axis for Standard Webhooks; existed only for the old `shared-secret`)* | Label **"Header"**; pre-filled from the preset (e.g. GitHub's `X-Hub-Signature-256`, Stripe's `Stripe-Signature` — exact shipped values are the Principal Engineer feasibility study's, not fixed here), editable. For **Shared secret only**, unchanged from today: pre-filled `X-Signature`, editable. | — |
| Secret value | Write-only, shared shape (`design-10`) | **Unchanged shape** — `Input type="password"`, Unset/Set states exactly as `design-10` specifies, reused for every provider/preset/Custom/Standard Webhooks/Shared secret only alike. Help trimmed to **"Paste the secret your provider issued — not generated here."** | — |
| Signing details (Algorithm, Encoding, Extraction shape + prefix/part, Timestamp source + header/part, Id source + header/part, Tolerance) | *(did not exist)* | `Collapsible` **"Signing details"** wrapping the six PRD-16 axes (AC15–AC20), pre-filled from the preset. **Default expand state:** open when Provider = Custom, collapsed when Provider = a named preset — reusing `design-10`'s own reasoning for the destination Credential's default-expand rule ("open by default only when set… unconfigured opens collapsed"), read here as "open when there's nothing pre-filled to trust yet, collapsed when there is." Editing any value inside relabels the Provider `Select`'s display to **"Custom (from {Preset})"** (PRD-16 AC8), computed, not a separate control the member sets. | Tolerance's help stays on-form (not tooltip) per PRD-16 AC20: **"A larger tolerance weakens replay protection."** — decision-relevant, not background. |
| Signed-string preview | *(did not exist)* | Read-only block, rendered once a sample is pasted **and** a provider/template is chosen, live-updating on every edit: the template with its tokens substituted from the pasted sample, directly beside the signature value the sample's own header actually carried. No label needed beyond **"Computed"** / **"Received"** — this is PRD-16 UX Direction point 2's substitution-teaching device, adopted literally. | — |
| Proof status | *(did not exist)* | Inline status line beneath the preview, three states — **Not yet checked** (neutral); **Proven — verified against your sample just now** (success, PRD-10-style status-line treatment); **Failed — {stage}** (error, expandable — see `## The Inbound control` § point 6). Phrased as a guarantee, not a gate, per UX Direction point 5 — see that section for the exact copy reasoning. | — |
| Standard Webhooks — "what your sender must send" block | Full paragraph plus a bulleted list plus a closing tolerance sentence | **Unchanged from the prior revision**: "Sender must send:" then the three fixed header names, tightened. Standard Webhooks renders **none** of the Template/Header/Signing-details/preview/proof fields above — see `## The Inbound control` § Q2 for why. | — |
| Replace (editing a set secret, any provider) | "Secret set — changed {date}" + Replace, with the 24-hour-overlap disclosure appearing once Replace is clicked (`design-10` C5 / Amendment ruling 2a) | **Unchanged.** A state disclosure with real operational consequences, not descriptive prose — full sentences, both branches, exactly as `design-10` specifies. | — |

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

## The Inbound control — from two schemes to a template model (revised against PRD-16, Draft)

*(This section replaces "The Verification control," this proposal's original
title, which resolved the Owner's brief against PRD-10 alone. That resolution is
kept below, condensed, because `docs/standards/documentation.md`'s house rule —
named explicitly in PRD-16 itself — is to retain history rather than rewrite a
ruling silently; the live conclusions are the ones in § Q1/Q2 resolved.)*

### Where this stood before this revision

`design-10` (approved) specifies a single `Select` with three items: `none`
("Not required," the default), `standard-webhooks` ("My sender already implements
Standard Webhooks"), and `shared-secret` ("My sender sends a shared secret in a
header"). `ADR-022` fixed this as a **closed two-case scheme registry** — two
mechanisms that verify differently, with nothing between them. The Owner asked for
"a plain on/off… and when it is on the member sees a header field pre-filled with
the specification's default header name, which they can keep or edit." The
original tension: a single on/off cannot represent a choice between two
mechanisms that differ, and "a header field pre-filled with the specification's
default" describes a field only one of the two schemes actually has. This
proposal's original resolution kept the checkbox as a literal on/off and kept the
scheme choice underneath it, shortened to two words each, with the pre-filled
header behavior applied only to the shared-secret scheme. That resolution is
superseded below, not because it was wrong against PRD-10, but because PRD-16
changes the thing it was reasoning about.

### What PRD-16 changes

PRD-16 (Draft) reverses PRD-10 AC50's closed scheme list. In its place: a
**template scheme** — a signed-string template built from three closed tokens
(`{body}`, `{timestamp}`, `{id}`) plus literal characters, together with seven
chosen axes (algorithm, encoding, header name, extraction shape, timestamp
source, id source, tolerance). **A preset (GitHub, Stripe, Slack, …) is a saved
template scheme — data, not a second code path (AC6).** Critically for this
container, PRD-16 keeps `shared-secret` and `standard-webhooks` standing
**exactly as PRD-10 shipped them, unmigrated** (AC44, AC45) — they are not
folded into the template model, they sit beside it. So the real shape a member
chooses from, once verification is on, is no longer "two schemes." It is: **a
provider (a preset, or Custom, all one template mechanism) — or one of two
untouched legacy options (Standard Webhooks, Shared secret only).**

### Q1 — resolved against PRD-16 (Draft)

> *Original question: does the Owner's "plain on/off" mean removing the scheme
> choice from the member entirely, or only fixing the mechanics of a checkbox
> replacing the `Select`'s `none` item?*

**Resolved: the checkbox stands, unchanged, exactly as this proposal's prior
revision designed it.** There is still a choice underneath it — PRD-16 does not
remove the need to say *which* provider or scheme applies, and could not:
`shared-secret` and `standard-webhooks` still verify differently from a template
scheme and from each other (AC44, AC45), and different presets still resolve to
different templates. What PRD-16 removes is the part of the original tension that
made the choice feel like it was hiding something: under the closed two-scheme
model, picking "Standard Webhooks" versus "Custom header" was a choice between
two opaque, hand-written mechanisms a member had to already understand. Under the
template model, picking "GitHub" or "Stripe" is closer to what a plain on/off
*feels* like from the member's side — recognizing a name, not evaluating a
mechanism — even though a real, meaningful choice still happens underneath. **The
choice is preserved; what changes is that most members now make it by
recognizing their provider's name, not by reading a sentence about how
verification works.** This is a Draft-PRD-dependent resolution: if PRD-16 is
declined, Q1 reopens exactly as originally stated.

### Q2 — resolved against PRD-16 (Draft), with the Standard Webhooks residual named explicitly

> *Original question: does "a header field pre-filled with the specification's
> default header name, editable" apply only to the shared-secret scheme, or was
> it meant to extend to Standard Webhooks' fixed headers?*

**Resolved, in two parts, because the coordinator's brief is right that one part
survives and one does not.**

- **For every template scheme — every preset and Custom alike — header names are
  now genuinely a configured, editable axis, pre-filled with sensible defaults.**
  PRD-16 AC17 makes the signature header name a member-supplied axis for every
  template scheme, and AC5 requires each preset to fill every axis including it.
  This is exactly what the Owner described, generalized from "the one scheme that
  happened to have a header field" to "every scheme in the new model" — the
  `## Copy rewrite pass` table above renders this as the **Header** field,
  pre-filled per preset, always editable.
- **Standard Webhooks is the one case that survives unchanged, and PRD-16 says so
  by name (AC45): it is kept as its own scheme, not folded into the template
  model, specifically because outbound signing (PRD-10 AC54–AC64) is defined
  against that same specification and folding it in would drag outbound signing
  with it.** Its three headers (`webhook-id`, `webhook-timestamp`,
  `webhook-signature`) remain fixed by the external specification, not a
  per-proxy setting, and this proposal does **not** give it an editable Header
  field. **How this proposal handles the resulting asymmetry**, since the
  coordinator asked directly: Standard Webhooks stays listed in the same
  **Provider** `Select` as the template-model presets — to a member deciding
  "what does my provider look like," it belongs in the same list, the same way it
  did conceptually before — but selecting it renders **none** of the
  Template/Header/Signing-details/preview/proof fields the other items render. It
  falls straight to the same static "what your sender must send" block and
  Secret field `design-10` already ships, unchanged. **Fewer fields for an
  already-fully-specified scheme is not an inconsistency to smooth over — a
  scheme with nothing left to configure should show nothing left to configure.**
  The one thing worth flagging rather than asserting as obviously fine: a member
  who has just filled in a Header field for a GitHub or Stripe preset and then
  switches Provider to Standard Webhooks watches that field disappear. This
  proposal reads that as acceptable (the two are genuinely different amounts of
  configuration, and the static block replacing it says what's fixed instead), but
  it is exactly the kind of live-usability call the Principal Engineer's
  forthcoming feasibility study — which will show the real field count per
  provider — is positioned to sanity-check once it lands.

This resolution, like Q1, rests on PRD-16 being approved as Draft. If the Owner
declines it, `standard-webhooks` and `shared-secret` return to being the *only*
two options, Q2 reopens in its original form, and this container's Provider
picker collapses back to this proposal's prior committed shape.

### The container, concretely

The full field-by-field shape is in `## Copy rewrite pass` → *Inbound — Webhook
secret*; summarized here as a state machine:

```
Checkbox unchecked → nothing else renders. (unchanged)

Checkbox checked →
  Sample request Textarea (always rendered first — see next section, point 1)
  Provider Select: GitHub | Stripe | Slack | [more, Q-16-02] | — | Standard Webhooks | Shared secret only | — | Custom

  Provider = (a preset, or Custom) →
    Signed string Template Input (pre-filled, never blank)
    Header Input (pre-filled from preset)
    Secret value (write-only, shared shape)
    Collapsible "Signing details" (Algorithm, Encoding, Extraction, Timestamp
      source, Id source, Tolerance) — open if Custom, collapsed if a preset
    [if Sample pasted] Signed-string preview (Computed vs. Received)
    Proof status (Not yet checked | Proven | Failed — {stage})

  Provider = Standard Webhooks →
    Secret value (write-only, shared shape)
    Static "what your sender must send" block (unchanged from design-10)
    [no Template, Header, Signing details, preview, or proof — AC45]

  Provider = Shared secret only →
    Header Input (pre-filled X-Signature, editable — unchanged from design-10)
    Secret value (write-only, shared shape)
    [no Template, Signing details, preview, or proof — AC44]
```

No field here is new *machinery* beyond what `## Copy rewrite pass` already
specifies — this is a state diagram of the same fields, included so the
container's shape is checkable at a glance rather than only recoverable by
reading every table row.

## Custom-template entry UX — responding to PRD-16's UX Direction

PRD-16 names the custom-template experience as "the open problem in this feature"
and sets nine points of direction rather than layout. Each is answered below —
agree, disagree, or refine — because PRD-16 itself says this is design authority,
not requirements: "Screens, states, components and copy belong to the Designer."

1. **"The real request is the centre of the form, not a test at the end."**
   **Agree, adopted literally, refined toward *first field*.** The Sample request
   `Textarea` renders immediately below the Provider `Select` — before a provider
   is even chosen, not after — because a pasted sample can *drive* the provider
   suggestion (point 7) rather than only checking a choice already made. This is
   a stronger reading than "don't gate it at the end": it makes the sample the
   thing that can shortcut the provider choice, not merely accompany it.
2. **"Show the member the string that will be signed, filled in from their
   sample" — vocabulary taught by substitution, not a legend.** **Agree, adopted
   literally.** The Signed-string preview block (Computed / Received) is exactly
   this, rendered live on every template or axis edit once a sample exists. No
   separate token-vocabulary legend is added anywhere on this form — the preview
   is the only explanation the three tokens get, matching the direction's own
   instruction that the list is short enough not to need one.
3. **"Nobody should start from an empty field… editing a near preset is the
   preferred path into custom."** **Agree, refined.** This proposal keeps an
   explicit **Custom** item in the Provider list — PRD-16 explicitly leaves this
   choice open ("whether 'custom' is a separate option… is the Designer's call")
   — for the member whose provider matches nothing, because an always-visible,
   named entrance is easier to find than "discover that editing any field on a
   preset quietly becomes custom." But **Custom is never blank**: it pre-fills
   the same minimum valid scaffold every truly-from-scratch case needs — template
   `{body}`, HMAC-SHA256, hex, bare extraction, no timestamp/id source — which is
   itself "the simplest preset," not a distinct empty state. Editing *any*
   preset's fields reaches the same place by the other door (AC8), and both
   doors are implemented identically underneath: there is exactly one editor,
   entered either by name (Custom) or by consequence (touching a preset's
   values).
4. **"A malformed template fails at the field, before saving, and names the rule
   it broke."** **Agree, adopted literally.** The Template `Input`'s error state
   uses the existing `InputError` pattern, but with the four rule-specific
   messages PRD-16 names rather than a generic failure — missing `{body}`
   (AC22, with the tooltip carrying *why*, per this direction point's own
   instruction), an unrecognized token, `{timestamp}` with no timestamp source,
   `{id}` with no id source (AC14). Each is a distinct, sayable string, not one
   shared "Invalid template."
5. **"The safety rules must read as what the product guarantees, not obstacles."**
   **Agree, adopted in copy.** The Proof status line's **Not yet checked** state
   is phrased forward — *"Not yet checked against a real request — paste one
   above to prove this"* — never *"Cannot enable."* The enable/save mechanics
   this status gates are Q-16-01(b), the Principal Engineer's open question about
   how proving composes with PRD-10 AC26's write-only secrets; this proposal
   designs the status line's states and copy, not the save-time gate itself,
   which depends on that answer.
6. **"A failed proof is a diagnosis, not a verdict."** **Agree, adopted
   literally.** The **Failed — {stage}** state expands to name which of PRD-16
   AC28's five stages failed (header absent, extraction failed, timestamp
   absent/out of tolerance, id absent, signature mismatch), and may show the
   computed signed string beside the received signature — never the secret, per
   AC28's own closing rule. This reuses the same "Computed / Received" framing
   the live preview already establishes, so a failure state is a variant of a
   pattern the member has already seen succeed, not a new visual language.
7. **"The product may suggest, but must never decide."** **Agree, adopted
   literally.** The provider-suggestion note (`## Copy rewrite pass`) requires an
   explicit **Use** click; dismissing it or ignoring it leaves the Provider
   `Select` exactly as it was. No suggestion pre-fills the `Select`'s value on
   its own.
8. **"A preset must not read as a certification."** **Agree, adopted in copy.**
   No field or state on this container claims a preset is current or guaranteed
   — the Signing-details `Collapsible`'s collapsed state for a fresh preset is a
   density choice (§ above), not a trust signal, and the same Proof status line
   every Custom scheme gets is required of a preset selection too: selecting
   GitHub does not itself mark anything Proven.
9. **"Readable months later by someone who did not write it."** **Agree in
   principle, out of this proposal's immediate scope.** Everything AC43 requires
   be readable (provider/preset name, derivation, template, every resolved axis,
   last-proof date) is **non-secret** — unlike the secret field, nothing here
   needs a write-only collapsed state, so Edit already shows all of it inline by
   construction, satisfying the *form's* half of this requirement for free. A
   dedicated read-only summary on the proxy **Show** page (`design-10` Screen 4's
   Verification card, or its PRD-16-era successor) is the natural place for the
   "did not write it" reader who never opens Edit at all, but that card is out of
   this proposal's stated scope (`resources/js/pages/proxies/ProxyForm.vue` and
   its siblings only) and belongs to the full `design-16` once PRD-16 is
   approved — noted under `## Open Questions` rather than designed here.

**Where the forthcoming Principal Engineer feasibility study fits.** Its worked
examples (GitHub, Stripe, Slack, Shopify, and others, with real header names and
signed strings) will state concretely how many fields a member fills per
provider — which is the number this container's "collapsed Signing details by
default for a preset" design is a bet on staying small. Nothing in this revision
is built to require re-architecture if that number comes back larger than
expected: the `Collapsible` already exists as the pressure valve, and its
default-open state for Custom already covers the "this needs everything visible"
case. This is noted rather than re-litigated because the study does not exist
yet.

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
      Textarea "Sample request from your provider"
      [suggestion note, if a sample is pasted and no Provider chosen]
      Select "Provider" — GitHub | Stripe | Slack | … | Standard Webhooks | Shared secret only | Custom
      [provider-conditional fields — see ## The Inbound control § The container, concretely]
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

**States.** Every field's default/empty/loading/error/success states outside the
Webhook secret fieldset are exactly those already specified — `design-10` for
Sensitive fields and Credential; `design-07` for Mode and the downgrade
disclosure; `design-06` for Retry policy; the current shipped behavior for Name,
Processing, Response, and Destinations. **No field outside Webhook secret gains
or loses a state in this proposal.** Inside Webhook secret, this revision adds
real new states beyond the prior revision's checkbox-only addition, all detailed
in `## The Inbound control` § The container, concretely and § Custom-template
entry UX: the Sample `Textarea`'s empty/pasted states, the dismissible provider
suggestion, the Template `Input`'s four rule-named error states, the
Signing-details `Collapsible`'s open/collapsed default (conditional on Provider),
the live preview's "no sample yet" (not rendered) versus "rendered, live" states,
and the Proof status line's three states (Not yet checked / Proven / Failed —
{stage}). None of these is a new *kind* of state this app hasn't shown before
(text input, error, collapsible, live-computed read-only text, and a status line
are all reused patterns) — what's new is that they compose into one fieldset for
the first time.

## Components

| Element | Component | Notes |
|---|---|---|
| Card sections | `Card` (×5, was ×1) | Reused, same primitive, more instances. |
| Card/fieldset headings | plain `h2`/`legend`, `text-base font-semibold` (h2) or existing `legend` weight | Reuses the exact pattern `proxies/Show.vue` already uses for its own stacked cards — no new heading component. |
| Verification on/off | `Checkbox` (`ui/checkbox`) | **New usage** — this primitive exists and is generated, but today is only used for row-selection in `teams/Index.vue`/`Edit.vue`. Using it as a standalone settings toggle is a new usage pattern for this app, flagged per `docs/standards/design.md`'s "new components are flagged in the spec" convention (it is not a new *component*, only a new *use* of an existing one). No `Switch` primitive exists in this codebase and this proposal does not introduce one — `Checkbox` + `Label` is the existing accessible on/off idiom already generated here. |
| Provider picker | `Select`/`SelectTrigger`/`SelectContent`/`SelectItem`/`SelectValue` | Reused primitive. **A plain `Select` is proposed rather than a searchable combobox** — no `Command`/`Combobox`/`Popover` primitive exists in this codebase today, and AC5's floor (GitHub, Stripe, Slack) plus Q-16-02's still-open "how many more" is a small enough list for a scrollable `Select` to stay usable, matching this app's "reuse before inventing" standard. **Flagged, not decided:** if the Principal Engineer's feasibility study or Q-16-02's answer implies a long provider list, a searchable combobox becomes a real candidate and would be a genuinely new primitive — left for a later revision once that count is known, not designed speculatively here. |
| Sample request | `Textarea` | **New usage for this app in a settings form** — no existing primitive-level `Textarea` component was found reused elsewhere on this form; the shadcn-vue/Reka UI `Textarea` generator is the same class of primitive as every other `ui/*` wrapper here (flagged per `docs/standards/design.md`'s "new components are flagged" convention — a new *generated wrapper*, not a hand-rolled control). |
| Signed-string preview, Proof status | plain read-only text, `text-sm`/`font-mono` where the computed/received strings render, `aria-live="polite"` on the Proof status line | No new primitive — reuses this app's existing "live async feedback needs `aria-live`" convention (`docs/standards/design.md` → Screen-reader requirements, the same rule the Ingest URL `CopyField`'s "Copied" feedback already follows). |
| Signing details | `Collapsible`/`CollapsibleTrigger`/`CollapsibleContent` | Reused verbatim from `design-10`'s destination Credential subsection — same primitive, same default-expand reasoning, applied to a new field group. |
| Tooltips | `Tooltip`/`TooltipTrigger`/`TooltipContent`/`TooltipProvider` (`ui/tooltip`) | **New usage for this purpose.** The primitive is generated and already imported (`AppHeader.vue`, `SidebarMenuButton.vue`) for icon-button affordances, but this proposal is the first place it carries explanatory field copy. Trigger is an `Info` icon (`@lucide/vue`, already imported in `ProxyForm.vue` for the downgrade `Alert`) inside a small, keyboard-focusable button — never a bare hover-only element (see `## Accessibility`). |
| Everything else | unchanged | `Input`, `InputError`, `Alert`/`AlertTitle`/`AlertDescription`, `Checkbox`, `Badge`, `Button` — all reused exactly as `design-10`/`design-07`/current `ProxyForm.vue` already specify them. |

## Interactions

- **Verification checkbox toggling off** clears the in-session `verification_scheme`
  (or its PRD-16 equivalent), the Sample `Textarea`, and any in-session, unsaved
  provider-conditional field values — the same "hidden field can never carry a
  stale value into submit" rule `ProxyForm.vue` already applies to the
  Retry-policy fieldset on a Mode change, and `design-10` already applies to a
  scheme change. No new discard rule is introduced; this is the existing rule,
  applied to one more control and a larger field set underneath it.
- **Toggling the checkbox back on** does not restore a prior in-session choice —
  the Provider `Select` opens with no provider selected (placeholder, not a
  default), the same "never silently pick a scheme for the member" stance
  `design-10`'s AC23 already establishes and PRD-16 AC37 restates for the wider
  model.
- **Selecting a Provider** (a preset, Standard Webhooks, Shared secret only, or
  Custom) pre-fills that provider's fields per `## The Inbound control` § The
  container, concretely, and discards any in-session values from a previously
  selected provider — the same discard rule as above, applied on every Provider
  change, not only on the checkbox.
- **Editing any pre-filled Template or Signing-details value** (for a preset,
  never for Custom, which starts editable) relabels the Provider `Select`'s
  display to "Custom (from {Preset})" per PRD-16 AC8 — a computed display state,
  not a separate action the member takes.
- **Pasting into the Sample `Textarea`** triggers, in order: the provider
  suggestion note (if no Provider is chosen yet and the pasted sample resembles a
  known preset); and, once a Provider is also chosen, the live Signed-string
  preview and an automatic proof check against the current Template/axis values.
  Every subsequent edit to the Template, Header, or any Signing-details value
  re-runs the preview and the proof check — "continuously," per PRD-16 UX
  Direction point 1, not only on an explicit "Test" click. **A live re-check on
  every keystroke versus a debounced/on-blur re-check is left to the Principal
  Engineer's plan** — this is a performance/mechanism detail, not a UX one, and
  is noted rather than specified here.
- Every other interaction (destination row add/remove/focus, secret Replace/
  Remove-credential flows, badge add/remove) is **unchanged** — this proposal
  moves fields between containers and shortens their copy; it does not touch a
  single piece of `useForm()` state-transition logic outside Webhook secret.

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
  primitive used here (`Card`, `Checkbox`, `Select`, `Tooltip`, `Textarea`,
  `Collapsible`, `fieldset`) is an already-vetted Reka UI primitive or plain
  semantic HTML, composed the same way this app already composes them; nothing
  here introduces a new interaction pattern needing separate a11y validation.
- **The Sample request `Textarea`** carries a programmatically associated
  `Label` ("Sample request from your provider") and `aria-describedby` pointing
  at its retention-promise help text, the same wiring every other field on this
  form already has.
- **The Signed-string preview and Proof status are live-updating, non-visual-only
  feedback** — both use `aria-live="polite"` regions (the Proof status
  transition from Not yet checked → Proven/Failed is exactly the kind of
  async-feedback update `docs/standards/design.md` already requires an
  `aria-live` region for, citing the `CopyField` "Copied" precedent) so a
  screen-reader user is told the result without having to re-poll the field.
  Colour is never the sole carrier of Proven versus Failed — both pair with text.
- **The Signing-details `Collapsible` trigger** states its own open/closed
  state via the same `ChevronDown`/`ChevronRight` + text-label pattern
  `design-10`'s Credential subsection already established, satisfying the same
  accessibility bar without inventing a second disclosure idiom.

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

1. ~~**To the Product Manager: does the Owner's "plain on/off" for Verification
   mean only the mechanics addressed here, or does it mean the member should
   never be asked to choose between the two schemes at all?**~~ **RESOLVED
   against PRD-16 (Draft) — see `## The Inbound control` § Q1.** The checkbox
   stands; PRD-16's template model changes what the underlying choice feels like
   to make (recognizing a provider's name) without removing it. **This
   resolution rests on PRD-16 being approved. If the Project Owner declines
   PRD-16, this question reopens in its original PRD-10-only form.**
2. ~~**To the Product Manager: does "a header field pre-filled with the
   specification's default header name, editable" apply only to the
   Custom-header scheme, or was it meant to make Standard Webhooks' three fixed
   headers editable too?**~~ **RESOLVED against PRD-16 (Draft) — see `## The
   Inbound control` § Q2.** Header names become a genuinely editable, per-preset
   axis for every template scheme under PRD-16 AC17. Standard Webhooks is the
   one named exception (PRD-16 AC45 keeps it its own scheme, outside the
   template model, because outbound signing is defined against that same
   specification) and its headers stay fixed and non-editable, as `design-10`
   already ships them. **This resolution likewise rests on PRD-16's approval and
   reopens with it if declined.**
3. **To the Product Manager: should renaming the form's "Verification" legend to
   "Webhook secret" also rename the proxy Show page's "Verification" card
   (`design-10` Screen 4)?** Unchanged by this revision — still open. Leaving the
   form saying "Webhook secret" while the Show page still says "Verification"
   would reintroduce exactly the kind of inconsistency this brief is trying to
   remove. This proposal does not touch Screen 4 (out of its stated scope), but
   the naming decision, if accepted, should be applied consistently by whoever
   amends `design-10`.
4. **New, to the Product Manager: PRD-16 UX Direction point 9 (readable months
   later) is answered for the create/edit form here, but the dedicated read-only
   summary on the proxy Show page is out of this proposal's stated scope.** Is a
   Show-page update to the Verification/Signing cards part of the same `design-16`
   effort PRD-16 routes to next, or a separate follow-on? Not blocking this
   proposal, since the form itself satisfies AC43's readability requirement on
   its own (nothing PRD-16 adds is write-only except the secret) — raised so the
   Show-page work isn't silently assumed to be included here.

**One item for the Principal Engineer, not the Product Manager, raised as an Open
Question rather than resolved here per this role's escalation rule:** the
Signing-details `Collapsible`'s collapsed-by-default state for a preset is a
density bet that the Principal Engineer's forthcoming feasibility study (worked
examples with real field counts) can confirm or overturn once it lands — see
`## The Inbound control`'s closing note. This is not a feasibility *doubt* about
whether the design works technically; it is a request that the study's findings
be checked against this specific layout call once available.

Every other control proposed reuses an existing, already-shipped primitive
(`Checkbox`, `Select`, `Tooltip`, `Collapsible`, `fieldset`) or a newly-flagged
but unremarkable one (`Textarea`), and no data model, API, or dispatch-time
behavior changes are proposed by this document.

## Consequences

If the Project Owner accepts this proposal, the following **already-approved**
artifacts describe the current shape of fields this proposal regroups and
rewrites, and would need amending to stay accurate. None of them is edited by this
document; this is a list for whoever the Owner directs to carry the amendment:

- **`design-10-sensitive-data-handling.md`** — the largest single amendment, and
  larger after this revision than before it. Screen 1's Verification section:
  legend text, the "off by default" help sentence (cut), and its placement
  (moves from a standalone section into the new Inbound card, alongside
  Response) are unchanged from the prior revision. **Superseded further by this
  revision**: the `Select`'s item shape is no longer "three items → a Checkbox
  plus a two-item Select" but "a Checkbox plus a Provider Select carrying
  presets, Standard Webhooks, Shared secret only, and Custom" — which in turn
  depends on PRD-16's own not-yet-written `design-16` for the presets' actual
  data (names, header defaults, template values) rather than being fully
  specified by this proposal alone. Screen 2's help copy (trimmed). Screen 3's
  help copy (left as-is per this proposal, but its container — now a card
  wrapping the existing `DestinationRows.vue` `fieldset` — should be confirmed).
  If Open Question 3 is ruled in favor of consistency, Screen 4's "Verification"
  card title on the Show page as well.
- **`prd-10-sensitive-data-handling.md` and `ADR-022`** — not edited by this
  design proposal, but named because `## The Inbound control` now designs
  against a container shape that assumes PRD-16's reversal of PRD-10 AC50 is
  approved. If PRD-16 is declined, this proposal's Webhook secret container
  reverts to this document's prior committed shape (`acc3325`), which depended
  only on PRD-10/`ADR-022` as they stand today.
- **`design-16` (not yet written — PRD-16 is still Draft)** — once PRD-16 is
  approved and a full `design-16` is commissioned, this proposal's `## The
  Inbound control` and `## Custom-template entry UX` sections are offered as a
  starting container, not a substitute for that spec: `design-16` still owns
  the complete flows, every state (including error/loading states this proposal
  does not enumerate in full — e.g. what the proof check's in-flight state looks
  like), and the presets' actual shipped data. This proposal deliberately stops
  short of writing that spec, consistent with PRD-16's own routing ("Next Agent:
  Designer" — after the Owner approves the PRD, not before).
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
