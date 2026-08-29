# PRD: Configurable inbound verification — **WITHDRAWN**

- **Status: WITHDRAWN, 2026-08-28.** **Reason: inbound verification is removed from the product, so
  there is nothing left for this document to configure.**
- **Withdrawn by:** Product Manager, on the Project Owner's ruling of 2026-08-28, accepted the same
  day as `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`
  and rendered into requirements by `docs/product/prd-10-sensitive-data-handling.md`
  `## Amendment C`, which withdraws PRD-10 AC23–AC28 and AC50–AC53.
- **This is not pending work.** It is not deferred, not parked, not blocked and not awaiting an
  approval. **It was never approved and it will not be**, so it is withdrawn rather than amended:
  every criterion below describes how a member would express a verification construction for a
  capability the product no longer has. **Nothing in it is operative, and no part of it should be
  implemented, designed, planned or scheduled.** The file is retained, not deleted, per
  `docs/standards/documentation.md` — a withdrawn document keeps its file and its full text so the
  record of what was proposed survives.
- **Also withdrawn with it, and not by this document:** `design-17`, the proxy-form restructure on
  branch `design/proxy-form-restructure`, which was written against this PRD and its feasibility
  study. It is the Designer's to withdraw. The Owner-directed extra gate it carried — a Principal
  Engineer technical sign-off on the design — **lapses with the document, and no sign-off is owed.**
- **Retained, and explicitly NOT withdrawn:**
  `docs/architecture/prd-16-template-model-feasibility.md`. **It keeps its filename and all
  twenty-one of its provider findings**, which are the evidence base for ADR-025 Decision 1 and
  ADR-026 Decision A. Its findings are about verification **constructions** — which providers'
  signature headers a destination can verify once forwarded — and that record survives this
  withdrawal intact. Only its original question, whether a bounded template vocabulary could express
  those constructions, is moot. **It is the Principal Engineer's document and is not touched by this
  withdrawal.**
- **The numbered questions this PRD routed to the Product Manager lapse with it**, and no answer is
  owed on any of them. **Roadmap item #16 was never added** — `docs/product/roadmap.md` still runs to
  #15 — so nothing needs removing from the roadmap.
- **If inbound verification is ever wanted again, this document is a starting point and not a
  specification.** Reinstating the capability at all is a Project Owner decision, taken against the
  position ADR-026 records: the ingest URL's token is the only authenticator on the ingest path, and
  a second factor of any kind needs a new Owner ruling.

**Everything below this line is the document as it stood when it was withdrawn. It is retained
unedited as the record of what was proposed, and none of it is in force.**

---

- **Status *(pre-withdrawal, retained)*:** **Draft — awaiting Project Owner approval.** Not approved, and not approvable by the
  Product Manager. Four things in this document need the Owner specifically rather than riding along
  with an ordinary requirements sign-off:
  1. **§ The reversal — PRD-10's closed scheme list is superseded.** PRD-10 AC50 and the "the scheme
     is selected, never described" clause of PRD-10 AC23 were **ruled by the Project Owner on
     2026-08-27** and **reversed by the Project Owner on 2026-08-28**. Approving this PRD ratifies
     the reversal. This is a security-shaped decision and should be approved deliberately rather
     than absorbed, exactly as PRD-10 § V2 was.
  2. **§ Consequences for approved documents.** Approving this PRD **supersedes PRD-10 AC50**,
     **revises PRD-10 AC23**, and leaves **PRD-10 AC51 and AC52 standing unchanged** — the last two
     on Product Manager rulings recorded in AC44 and AC45 and open to the Owner's correction.
  3. **Three gaps in the settled direction, filled by the Product Manager and marked as such.** The
     Owner's axis list named a source for the timestamp but not for `{id}` (AC19); named a tolerance
     without a default value (AC20); and did not say whether selecting a preset copies its values or
     tracks them (AC7). Each is ruled here on stated grounds and each is the Owner's to correct.
  4. **The roadmap does not yet carry item #16.** `docs/product/roadmap.md` runs to #15. This PRD
     does not add the line, because adding a backlog item is the Owner's act; see § Consequences.
- **Author:** Product Manager
- **Date:** 2026-08-28
- **Approved by / date:** —
- **Backlog item:** Roadmap **#16** — *proposed*, not yet on the roadmap. It descends from **V2**
  (webhook verification-token standards), which PRD-10 § V2 settled and this item reopens on the
  Owner's ruling.
- **Depends on:** **#10 (in implementation)** — this item extends #10's inbound verification and
  does not replace it. Every criterion here composes with PRD-10 AC23–AC29 and AC51–AC53 as
  approved, and **nothing here may ship before #10 does**. Also **#1 (Done)** for the ingest path
  and raw capture, **#2 (Done)** for the permission AC41 reuses, and **#3 (Done)** for the
  user-defined response AC38 keeps withheld from a rejected sender.
- **Build-ahead status:** written against PRD-10 as approved on 2026-08-27, including Amendment A
  and Amendment B. **#8 is Owner-deferred and #9 has not started**, and nothing here depends on
  either — inbound verification runs on the raw request body before any transform, which PRD-10 § V2
  established and AC13 restates. **#15 is Draft and unrelated**; pausing dispatch has no bearing on
  verifying an inbound request.
- **Next gate: the Designer.** `## UX Direction` is present, so a PM-approved `design-16` is a
  prerequisite for Technical Design. **The UX of entering a custom template is the open problem in
  this feature**, and the Owner named it as such.

## Feature
A member can configure how their proxy verifies an incoming webhook, in one of two ways: by
selecting a **preset** for a known provider, or by describing their provider's construction as a
**custom scheme** built from a fixed vocabulary. A preset is a saved custom scheme, so both use the
same machinery. Three rules keep the freedom from becoming a way to earn a meaningless "verified"
badge: **every template must sign the body**, **the secret is always the key and never part of what
is signed**, and **no scheme goes live until it has verified a real request from the member's own
provider**.

## Definitions
Fixed vocabulary. Every criterion below uses these words exactly.

| Term | Meaning |
|---|---|
| **Scheme** | PRD-10's word, unchanged: the rule by which an incoming request is verified. This PRD adds a third kind of scheme alongside PRD-10's `shared-secret` (AC51) and `standard-webhooks` (AC52). |
| **Template scheme** | A scheme defined by a **signed-string template** plus the axes in AC15–AC20. The kind of scheme this PRD adds. |
| **Signed-string template** | The description of what the sender signed, composed of **tokens** and **literal characters** (AC10). GitHub is `{body}`. Stripe is `{timestamp}.{body}`. Slack is `v0:{timestamp}:{body}`. Standard Webhooks is `{id}.{timestamp}.{body}`. |
| **Token** | One of exactly three placeholders — `{body}`, `{timestamp}`, `{id}` — that resolve to values taken from the incoming request (AC10, AC13). The list is closed (AC10). |
| **Literal characters** | Any other characters in the template, which stand for themselves — the `.` in `{timestamp}.{body}`, the `v0:` in Slack's. They are never parsed and never evaluated (AC11). |
| **Preset** | A template scheme the product ships, filled in for a named provider (AC5). Data, not a second code path (AC6). |
| **Custom scheme** | A template scheme a member fills in themselves, either from empty or by editing a preset (AC8). |
| **Axis** | One of the settings that, together with the template, fully determines verification: algorithm, encoding, signature header name, signature extraction, timestamp source, id source, tolerance (AC15–AC20). Each is chosen from a list or is a name or a number — never free-form logic (AC21). |
| **Sample request** | One real request from the member's own provider — its headers and its body — pasted by the member so the configuration can be checked against it (AC24). |
| **Proven** | The state of a scheme definition that has successfully verified a sample request (AC24). A definition that is not proven cannot be enabled (AC25). |
| **Cosmetic variant** | Two different spellings of the same bytes — hex case, base64 padding, the base64 URL-safe alphabet, a signature prefix present or absent. Resolved silently (AC31–AC34). |
| **Semantic variant** | A different claim about what was signed — `{body}` versus `{timestamp}.{body}`. Never resolved by trying both (AC35). |

## Problem

Four gaps, each traceable to a document or to the shipped behaviour of #10 rather than asserted.

1. **PRD-10's two schemes verify neither of the two providers a member is most likely to bring.**
   `shared-secret` (AC51) is a constant-time equality check on a header value with **nothing computed
   over the body**. `standard-webhooks` (AC52) implements the published specification, which signs
   `{id}.{timestamp}.{body}` and carries three specification-named headers. **GitHub** signs the raw
   body only, sends `X-Hub-Signature-256: sha256=<hex>`, and carries **no timestamp at all**.
   **Stripe** signs `{timestamp}.{body}` and packs both parts into one `Stripe-Signature:
   t=…,v1=…` header. Neither is verifiable under either scheme. This was checked against what #10
   specifies and implements, not assumed.
2. **The escape hatch PRD-10 left is the wrong shape for the problem.** AC50 makes each vendor
   scheme "a Project Owner decision at that time", which is correct governance for a small number of
   large integrations and unworkable for a long tail. A member whose provider is not on the list has
   two options today: use `shared-secret`, which verifies nothing about the body, or wait for the
   product to add their provider.
3. **The gap is widest exactly where the product's value is highest.** A member who reaches for a
   webhook proxy is by definition integrating a third-party sender they do not control. The set of
   such senders is not one the product can enumerate, and PRD-10 § V2's own grounds concede the
   point: "the variable that matters is how the signed string is constructed, and those genuinely
   differ."
4. **Freedom here is dangerous in a specific, nameable way.** A member who can describe a
   construction can describe a worthless one — signing only a timestamp, or signing nothing at all —
   and earn a green "verified" badge that means nothing. **This is the reason the three safety rules
   in AC22, AC23 and AC25 exist**, and it is why this PRD does not simply widen AC23 and stop.

## The reversal — PRD-10's closed scheme list is superseded

Recorded openly, because it reverses a ruling the Project Owner made and this PRD's own house
standard (`docs/standards/documentation.md`) is to retain history and never rewrite a ruling
silently.

**The superseded ruling, quoted so the record is complete.** PRD-10 AC50, as revised by Amendment A
and approved by the Project Owner on 2026-08-27:

> **The verification scheme list is closed at two, and stays closed until an Owner decision opens
> it.** `standard-webhooks` (AC52) and `shared-secret` (AC51) are the whole of MVP.
> **Vendor-specific schemes are explicitly not in MVP and are named so they are not re-argued:
> `github`, `stripe`, `slack`, and any other per-vendor construction.** Each is added only when a
> real integration needs it, and **each is a Project Owner decision at that time**.

And PRD-10 § V2's ground for it: "The Owner's resolution is a **closed, named scheme list** (AC23,
AC50): **the member selects a scheme, they do not describe one.**"

**What changed the Owner's mind.** The Project Owner reversed the ruling on **2026-08-28**, on the
ground that no preset list can cover a long tail the product does not control. Their words:

> "we need to make it so a user can adapt to their provider, we wont be able to make a preset for
> everything."

**Why the original ground was right and is nevertheless answered.** The 2026-08-27 ground was that
supporting each vendor's construction is unbounded work. That was correct **about writing a class
per vendor**, which is what the list was closed against. It is not correct about a **fixed
vocabulary**: the four constructions PRD-10 § V2 itself names as the reason vendors differ —
`{body}`, `{timestamp}.{body}`, `v0:{timestamp}:{body}`, `{id}.{timestamp}.{body}` — are four
arrangements of the same three tokens. The unbounded set of vendor constructions is generated by a
bounded vocabulary, so the work is bounded once, not per vendor. **The reversal therefore does not
discard the original concern; it satisfies it by a different route**, and the presets the original
ruling ring-fenced as separate Owner decisions become data rows under it (AC6).

**What the reversal does not concede.** Free-form does not become unlimited. The vocabulary is
closed (AC10), nothing is parsed as an expression or executed (AC11), the axes are chosen from lists
(AC21), and three safety rules bound what a member can describe (AC22, AC23, AC25). PRD-10 § V2's
ruled-out list survives intact except for the one clause named here: **IP allow-listing, mutual TLS
and asymmetric schemes remain out** (AC48, AC50).

## Goals
- A member whose provider is not on any list can still verify it, without the product shipping code
  for their provider.
- The common providers are one selection, not a translation exercise: a member using GitHub, Stripe
  or Slack picks a preset and supplies their secret.
- **A "verified" badge means something specific.** Every scheme the product will enable signs the
  request body, uses the secret as the key, and has demonstrably verified a real request from the
  member's own provider.
- One mechanism, not two. A preset and a custom scheme are the same thing at different stages of
  being filled in, so a preset can never behave in a way a custom scheme cannot be checked against.
- Different spellings of the same bytes never cost a member an afternoon; different claims about
  what was signed are never guessed at on their behalf.
- The product promises **the large majority of providers** — those that HMAC something that includes
  the request body — and says plainly which families it does not cover (AC48–AC50).

## Users
- **Team member** — reads their provider's documentation, translates it into a scheme, and proves it
  against a real request. The developer this feature is entirely for.
- **Team Owner / Admin** — the same, without the Member ownership limit on configuration changes
  (Q-02-01, ADR-009 Amendment A2.2).
- **A second team member, months later** — did not configure the proxy, is debugging why a sender is
  being rejected, and needs to read what the proxy currently requires (AC43).
- **Upstream sender** — a third-party system, unaffected: it signs what it always signed, and is
  accepted or rejected under PRD-10 AC25 exactly as it is today.
- **The product (system)** — holds the scheme as configuration, computes exactly one signed string
  per request (AC35), and never executes anything a member typed (AC11).

## User Stories
- As a team member whose provider is GitHub, I want to pick GitHub and paste my secret, so I am not
  reading a signature specification to use a proxy.
- As a team member whose provider is on nobody's list, I want to describe what it signs, so I can
  verify it without waiting for the product to add it.
- As a team member reading my provider's documentation, I want to see the exact string the product
  will sign, filled in from a request I actually received, so I can compare it against what the
  documentation says rather than guessing.
- As a team member, I want the product to refuse to enable a scheme that has never verified a real
  request, so I never believe I am protected when I am not.
- As a team member, I want the product to stop me signing only a timestamp, because I would not
  necessarily notice that I had.
- As a team member whose provider sends an uppercase hex signature, I want that to just work, because
  it is the same signature.
- As a team member whose scheme is rejecting requests, I want to be told which part failed — the
  header, the extraction, the timestamp, or the signature itself — rather than "verification failed".
- As a team member who configured a proxy in March, I want to open it in June and read what it
  currently requires and when it was last proven, because I will not remember.
- As a team member already using `shared-secret`, I want this feature to change nothing about my
  working proxy until I choose to change it.

## UX Direction
Direction only. Screens, states, components and copy belong to the Designer (`design-16`). **This
section is longer than usual because the Owner named the custom-template experience as the open
problem in this feature**, and because a form that is merely correct will still fail here: the
member is competent but is translating a document they are reading for the first time.

**The primary flow, and it has two entrances that must not feel like two products.** A member opens
their proxy's verification configuration, and either recognises their provider and selects it, or
does not and describes it. The second entrance is the one that decides whether this feature works.

**What the experience optimises for, in priority order.**

1. **The real request is the centre of the form, not a test at the end.** The member has exactly one
   piece of ground truth: a request their provider actually sent. Direction: **let them paste it
   early and let everything else be checked against it continuously**, rather than filling in seven
   fields blind and pressing "test" at the end. Whether pasting a sample is literally the first step,
   an always-visible panel beside the form, or something else, is the Designer's — but a design in
   which the sample arrives last, as a gate, is not what this PRD is asking for. AC25 means the
   member cannot finish without it, so making it the last step converts the feature's best
   affordance into its most annoying one.
2. **Show the member the string that will be signed, filled in from their sample.** The single
   hardest thing to get right is the template, and the single most useful thing the product can do is
   render the member's template with the tokens substituted from their pasted request, next to the
   signature that request carried. That is how `{timestamp}.{body}` becomes legible without a manual,
   and it is how the token vocabulary is discovered — by seeing what each token resolved to, in
   place. **Direction: the vocabulary is taught by substitution, not by a legend the member has to
   read first.** The list is only three tokens long and is closed, so all of it can be visible.
3. **Nobody should start from an empty field.** A blank template box asks the member to author a
   construction from memory. **Starting from a near preset and editing it is the preferred path into
   a custom scheme** — "my provider is like Stripe but the header is different" is a far easier
   thought than "my provider signs the following string". AC8 makes an edited preset a first-class
   custom scheme that remembers where it came from, precisely so this path is available. Whether
   "custom" is a separate option, or simply what happens when you edit a preset, is the Designer's
   call — but the empty-field-first design is ruled against here.
4. **A malformed template fails at the field, before saving, and names the rule it broke.** Four
   rules can be broken and each has a specific, sayable cause: the template does not contain
   `{body}` (AC22); it uses something that is not a token; it uses `{timestamp}` with no timestamp
   source, or `{id}` with no id source (AC14). "Invalid template" satisfies none of these. **The
   member must be told which rule and why it exists** — particularly for `{body}`, where the reason
   *is* the feature.
5. **The safety rules must read as what the product guarantees, not as obstacles it puts up.** "You
   cannot enable this until it has verified a real request" is the same sentence as "we checked this
   against a request you actually received before we let you rely on it", and the second is what the
   member should experience. The proving step is the feature's best moment, not its gate.
6. **A failed proof is a diagnosis, not a verdict.** The proving surface is the **only** place in
   this feature where a verification failure is explained to a member — live rejections stay silent
   under PRD-10 AC25 and AC46, which is a real cost this PRD does not fix (AC51). So the proof
   failure has to carry the weight: which stage failed, what the product looked for, and what it
   found instead (AC28). It may show the signed string it computed and the signature it compared
   against, because the member supplied both; it must never show the secret.
7. **The product may suggest, but must never decide.** If the product can tell from a pasted sample
   that it looks like Stripe, offering that as a suggestion is good design. **Silently adopting it is
   not** (AC37). A suggestion the member confirms is a shortcut; a configuration the member never
   chose is a scheme nobody can explain later.
8. **A preset must not read as a certification of the provider.** A preset is a template that was
   right when it was written, copied onto the proxy at selection (AC7). The product does not track
   providers and does not update a proxy when a provider changes. The interface must not imply
   otherwise — and the member still proves it against their own request, the same as everyone else.
9. **The configuration must be readable months later by someone who did not write it.** Preset name
   or custom, which preset it was derived from if any, every resolved axis, the template, and when
   it last passed a proof (AC43). PRD-10 UX Direction point 7 already established that a rejected
   inbound request is the hardest thing to debug in this area; this is the surface that makes it
   possible at all.

**Not the Designer's to decide, because they are ruled here:** that the token vocabulary is exactly
`{body}`, `{timestamp}` and `{id}` and is closed (AC10); that `{body}` is mandatory (AC22); that the
secret is never a token and never appears in a template (AC23); that a scheme cannot be enabled
until it is proven (AC25) and an edit does not take effect until it is re-proven (AC26); that
exactly one signed string is computed per request and alternatives are never tried (AC35); that
selecting a preset copies its values rather than tracking them (AC7); that the algorithm and
encoding are chosen from closed lists (AC15, AC16); that the sample request never becomes an event
and is not retained after the check (AC29); and that `shared-secret` and `standard-webhooks` remain
as they are (AC44, AC45).

## Acceptance Criteria

> **Numbering is append-only**, following the house rule set by PRD-05 and PRD-11. Criteria here are
> numbered from 1 within this PRD; a reference to a PRD-10 criterion is always written "PRD-10 ACnn".

### The two ways in

1. **A proxy's inbound verification may be configured either by selecting a preset or by defining a
   custom scheme.** Both produce a **template scheme**, and a template scheme is verified at ingest
   by the same rules whichever way it was produced. There is no behaviour available to a preset that
   is not available to a custom scheme.
2. **A template scheme sits alongside PRD-10's two schemes, and does not replace either.** A proxy's
   verification is one of: not configured, `shared-secret` (PRD-10 AC51), `standard-webhooks`
   (PRD-10 AC52), or a template scheme. Exactly one applies to a proxy at a time.
3. **Verification remains optional and off by default.** A proxy with no verification configured
   behaves exactly as it does today (PRD-10 AC24, unchanged). Nothing is migrated and no proxy is
   opted in.
4. **A template scheme is fully determined by its template and its axes.** The template (AC10) plus
   algorithm, encoding, signature header name, signature extraction, timestamp source, id source and
   tolerance (AC15–AC20) are the whole of what verification depends on, together with the secret.
   Nothing else about the request or the proxy affects the outcome.

### Presets

5. **Presets ship for at least GitHub, Stripe and Slack.** Each preset fills every axis and the
   template; **the member supplies only the secret**. *(These three are named because the Project
   Owner named them. Which further providers ship at launch is Q-16-02, for the Owner.)*
6. **A preset is a saved template scheme, not a second code path.** Every preset is expressible
   entirely in the vocabulary of AC10 and AC15–AC20. **A provider whose construction cannot be
   expressed in that vocabulary is not shipped as a preset**; it is out of scope (AC48–AC50) or it is
   a separate Owner decision, and it is never a special case hidden behind a preset name. *(This is
   the criterion that keeps the reversal's promise: adding a provider adds data, not behaviour. A
   Reviewer verifies it by checking that each shipped preset is a set of values in the same shape a
   member could have typed.)*
7. **Selecting a preset copies its values onto the proxy; it does not track them.** After selection
   the proxy holds its own template and axes. **A later correction to a shipped preset does not
   change any proxy already configured from it.** *(Product Manager ruling, filling a gap the Owner's
   direction left. Grounds: a proxy's verification behaviour must never change without a member
   action, which is the same posture PRD-10 AC24 takes toward existing proxies. **The cost is real
   and is stated rather than glossed:** if a preset was wrong, or a provider changes its scheme,
   proxies configured from the old values keep the old values and the member finds out when requests
   are rejected. There is no notification — AC51.)*
8. **A preset's values may be edited, and the result is a custom scheme that records the preset it
   came from.** Editing does not silently modify a shared preset, and the origin is retained as
   provenance so AC43 can show it. *(This is the path UX Direction point 3 rules as preferred.)*
9. **A member may define a custom scheme without starting from a preset.** The two entrances produce
   the same kind of scheme.

### The template

10. **The signed-string template is composed of exactly three tokens plus literal characters, and
    the token list is closed.** The tokens are **`{body}`**, **`{timestamp}`** and **`{id}`**. Any
    other characters are literals standing for themselves. **A template containing anything the
    product does not recognise as one of the three tokens or as a literal cannot be saved.**
11. **The template is data, never code.** No part of it is parsed as an expression and no part of it
    is executed. There are no functions, no conditionals, no arithmetic, no regular expressions, no
    nesting, and no member-supplied executable content of any kind, here or on any other axis. *(This
    is a requirement about what the product accepts, not about how it evaluates it. The mechanism is
    the Principal Engineer's — Q-16-01.)*
12. **These four constructions are expressible, and are the adequacy test for the vocabulary.**
    GitHub `{body}`; Stripe `{timestamp}.{body}`; Slack `v0:{timestamp}:{body}`; Standard Webhooks
    `{id}.{timestamp}.{body}`. *(Stated as a criterion so the vocabulary can be verified as
    sufficient rather than asserted to be. The fourth is listed as a vocabulary test only —
    `standard-webhooks` remains its own scheme under AC45.)*
13. **`{body}` resolves to the raw request body exactly as received**, before capture, parsing,
    normalisation or any pipeline step — the same property PRD-10 AC52 states and PRD-10 § V2
    established. **This is why #8 and #9 have no bearing on this feature.**
14. **A token used without a source cannot be saved.** A template containing `{timestamp}` requires a
    configured timestamp source (AC18); a template containing `{id}` requires a configured id source
    (AC19). The failure names the missing source.

### The other axes — chosen, not typed

15. **Algorithm is chosen from a closed list: HMAC-SHA256 and HMAC-SHA1.** **HMAC-SHA1 is offered
    only because legacy senders still use it, is labelled as legacy, and is never the default.**
    *(The two algorithms are the Owner's. That SHA-1 is labelled and non-default is a Product Manager
    call: offering it silently alongside SHA-256 would present them as equivalent choices.)*
16. **Encoding is chosen from a closed list: hexadecimal or base64.**
17. **The signature header name is supplied by the member**, and the signature is extracted from that
    header in one of exactly three ways, chosen from a list: **bare** (the whole header value is the
    signature), **after a fixed prefix** (such as `sha256=`), or **as a named part of a delimited
    list** (such as `v1=` within `t=…,v1=…`). The prefix or part name is supplied by the member; the
    three extraction shapes are not extensible by a member.
18. **The timestamp source is chosen from exactly three options:** **nowhere** (the template has no
    `{timestamp}`), **a named header**, or **a named part of the signature header** (such as `t=` in
    Stripe's).
19. **The id source is chosen from the same three options as the timestamp source.** *(Product
    Manager ruling, filling a gap. The Owner's direction named `{id}` as a token and named a source
    for the timestamp but not for the id. A token with no defined source cannot resolve, so the only
    coherent reading is that `{id}` takes a source on the same terms. Ruled symmetric rather than
    invented differently, and open to the Owner's correction.)*
20. **A tolerance in seconds is required whenever a timestamp source is configured**, is a positive
    number of seconds, and is member-editable. **It defaults to 300 seconds** — the five minutes
    PRD-10 AC53 already applies for `standard-webhooks` — and the surface states that a larger
    tolerance weakens replay protection. *(The axis is the Owner's; the default value is a Product
    Manager call, taken from an approved document rather than invented. **No maximum is ruled**: that
    was not decided and is not invented here.)*
21. **Nothing on the configuration surface is free-form except header names, prefix and part names,
    the tolerance number, and the template's literal characters.** Every other setting is a selection
    from a defined list.

### The three safety rules

> These are the heart of the feature. Each exists because a member who can describe a construction
> can describe a worthless one and earn a "verified" badge that means nothing.

22. **`{body}` is mandatory in every template. A template without it cannot be saved.** **This is the
    single rule that makes the rest safe**: it makes it impossible to sign only a timestamp, or only
    an id, or a fixed literal, and call the result verification. There is no override, no advanced
    mode, and no per-team exception.
23. **The secret is always the HMAC key and is never part of what is signed.** **No token expands to
    the secret**, and no configuration field other than the secret field accepts it. A member cannot
    place the secret into the signed string, by accident or otherwise.
24. **A member proves a scheme against one real request from their provider.** They supply the
    request's **headers and body**, and the product verifies the configuration as entered against it
    and reports **pass or fail**.
25. **Nothing goes live unverified. A scheme definition that has never passed a proof cannot be
    enabled.** Enabling is unavailable, with the reason given, until a proof passes — never a control
    that appears to work and enables nothing.
26. **A change to an enabled scheme's definition does not take effect until the changed definition
    passes a proof.** **The previously proven definition keeps verifying in the meantime**, so
    editing a live scheme never leaves a proxy unprotected and never silently starts rejecting a
    sender that was working. *(A Reviewer verifies both halves: the edit does not take effect, and
    the proxy does not stop verifying.)*

### Proving, and what the sample request is not

27. **Proving is possible in the same sitting as first entering the secret.** A member configuring a
    proxy for the first time must be able to prove the scheme without saving a secret, leaving, and
    coming back. *(PRD-10 AC26 makes secrets write-only after saving; how proving composes with that
    is the Principal Engineer's — Q-16-01(b).)*
28. **A failed proof names the stage that failed.** At minimum it distinguishes: the signature header
    was absent; the signature could not be extracted in the configured way; the timestamp was absent
    or outside the tolerance; the id was absent; the signature did not match. **It may show the
    signed string it computed and the signature it compared against, because the member supplied
    both. It never shows the secret.**
29. **A sample request is not an event, and is not retained.** Proving creates **no `webhook_events`
    row**, causes **no dispatch or delivery**, produces **no delivery-attempt record**, appears in
    **no analytics**, and its content appears in **no log line**. **The sample is not retained after
    the check completes**; what is retained is the proof record — that a definition passed, when, and
    who ran it. *(Product Manager ruling. Grounds: the sample is a real request that may contain a
    customer's data and does contain a real signature, and PRD-10 AC25 already establishes that an
    unverified inbound request must not be able to drive writes. A sample stored indefinitely would
    be a payload copy outside PRD-05's retention contract with nothing owning its erasure.)*
30. **The proof record is visible on the proxy** — that the current definition is proven, and when it
    last passed (AC43).

### Cosmetic versus semantic

> Normalise the cosmetic variants automatically; make the member state the semantic ones.

31. **Hexadecimal comparison is case-insensitive, done once.** Both sides are lowercased and compared
    once. **Not several comparisons of several candidate spellings** — one normalisation, one
    comparison.
32. **Base64 is never case-normalised.** Base64 is case-sensitive; lowercasing it would break valid
    signatures **and** risk matching invalid ones. This is stated as its own criterion because it is
    the one place where applying AC31's reasoning by analogy would be a security defect.
33. **Base64 padding and the URL-safe alphabet are resolved silently.** A signature that differs from
    the expected one only by `=` padding or by `-_` in place of `+/` is the same signature.
34. **A configured signature prefix is tolerated whether the sender includes it or not.** `sha256=`
    present and `sha256=` absent are the same signature.
35. **Exactly one signed string is computed per request, and compared once. The product never tries
    several constructions and accepts whichever matches.** `{body}` and `{timestamp}.{body}` are
    different claims about what the sender signed. *(Two reasons, both load-bearing: "verified" would
    stop meaning anything specific if any of several constructions could have produced it, and a
    failure could never be explained back to the member because there would be no single thing that
    failed.)*
36. **Signature comparison is constant-time**, as PRD-10 AC51 and AC52 already require for the two
    existing schemes.
37. **Nothing about a member's configuration is set by detection.** The product may **suggest** a
    preset or an axis value from a pasted sample; **a suggestion never becomes configuration without
    the member confirming it**, and once confirmed the confirmed scheme is the only one used at
    runtime (AC35).

### Composition with #10, and continuity for existing proxies

38. **A failed verification is rejected before capture, exactly as PRD-10 AC25 says.** HTTP 401, a
    fixed non-configurable body, no `webhook_events` row, no delivery, no dispatch, and **the
    proxy's user-defined #3 response is not used**. A template scheme changes none of this.
39. **Every header a template scheme names is stripped from the outbound header set**, extending
    PRD-10 AC27 to this scheme: the signature header, and the timestamp and id headers where they are
    separate. *(PRD-10 AC27's reasoning applies unchanged and more widely — a member-named header
    cannot be a constant in ADR-008's list, and forwarding an inbound signature would have a
    destination try and fail to verify it against our own signing secret.)*
40. **Secret rotation works for a template scheme exactly as PRD-10 AC29 defines it** — two secrets
    honoured, the older for a fixed 24 hours, at proxy grain. A request verifying against either is
    accepted. **No new rotation rule is introduced.**
41. **Configuring a template scheme is gated by the existing proxy update permission**, including the
    Member ownership rule (Q-02-01, ADR-009 Amendment A2.2). **No new permission.**
42. **A proxy already using `shared-secret` or `standard-webhooks` is entirely unaffected when this
    ships.** It keeps verifying exactly as it does, with **no migration, no reconfiguration, and no
    requirement to prove a scheme that is already running**. *(AC25's proving requirement applies to
    template schemes when they are created or edited. Applying it retroactively would take working
    proxies offline, which is not what any ruling asked for.)*
43. **The scheme in force is readable after the fact by anyone who can view the proxy.** The surface
    shows: which preset it is, or that it is custom; the preset it was derived from, if any (AC8);
    the template; every resolved axis (AC15–AC20); and when the definition last passed a proof
    (AC30). The secret is not shown (PRD-10 AC26). *(The template and the axes are **non-secret
    configuration**. The product cannot stop a member typing a secret into a literal, so the
    interface must be clear that the template is displayed configuration rather than a secret store,
    and AC23 removes every legitimate reason to put one there.)*

### Scope boundaries

44. **`shared-secret` (PRD-10 AC51) stands unchanged and is not migrated onto the template model.**
    It computes nothing over the body, and AC22 makes `{body}` mandatory, so it is **not expressible
    as a template scheme and must not be faked as one**. It remains what it is: a constant-time
    equality check on a member-named header, for senders that sign nothing. *(Product Manager ruling,
    on the ground that the two models make different claims and collapsing them would let a template
    scheme exist that does not sign the body.)*
45. **`standard-webhooks` (PRD-10 AC52) stands unchanged as its own named scheme, and no duplicate
    preset of it is shipped.** Its signed string is expressible in the vocabulary (AC12), but the
    specification is more than its signed string — a space-delimited signature list with version
    prefixes, its own key-material handling, and a tolerance the specification owns rather than the
    member (PRD-10 AC53). **Outbound request signing (PRD-10 AC54–AC64) is defined against this same
    scheme**, so folding it into the template model would drag the outbound direction with it.
    Offering both a specification scheme and a lookalike preset would give a member two ways to do
    one thing with different failure modes. *(Product Manager ruling; open to the Owner's
    correction.)*
46. **No change of any kind to outbound request signing.** PRD-10 AC54–AC64 are untouched. This
    feature is inbound only, and a member cannot describe an outbound construction.
47. **No member-supplied code, expressions or regular expressions**, on the template or on any axis
    (AC11). Restated as a scope boundary so it is not read as merely a current limitation.
48. **No asymmetric or certificate-based verification.** RSA-signed providers such as **PayPal** and
    **AWS SNS** need an outbound certificate fetch and a different trust model, and do not fit an
    HMAC template engine whatever shape it takes. **Separate work; not ruled in or out on merit.**
49. **No scheme that signs anything other than the request body.** **Twilio** signs the request URL
    and its sorted POST parameters rather than the body, which AC22 cannot express by construction.
    **Separate work.**
50. **No mutual TLS and no JWT-based verification.** Both are different trust models, and mutual TLS
    was already out under PRD-10 § V2. **IP allow-listing likewise stays out.**
51. **No analytics, notification or log surface for live rejections.** PRD-10 AC46's position is
    unchanged and this feature does not improve it. **The cost is real and stated:** outside the
    proving step, a member whose sender starts failing sees rejections only by looking. The remedy
    belongs to **#13** and **#11** and is not designed here.
52. **No sharing or reuse of a scheme across proxies, and no member-authored preset library.** A
    custom scheme belongs to the proxy it was configured on. *(Not ruled by the Owner and not
    invented here. It is a plausible next request and would be its own decision. Stated so it is not
    read into AC9.)*
53. **No numeric targets.** No throughput or latency figure is asserted for verification (**V8**
    remains deferred), and none is asserted for the proving step.
54. **The product promises the large majority of providers, not every provider.** Providers that
    HMAC a string containing the request body are covered. AC48, AC49 and AC50 name the families that
    are not, and the product must not claim otherwise in any surface or copy.

## Consequences for approved documents

Recorded so nothing is narrowed or dropped silently — the rule PRD-05 Amendment A and PRD-10 § V2
were both written under. **No document listed here is edited by this PRD.** Every change takes
effect only if the Project Owner approves it, and each is made by the role that owns the document.

- **PRD-10 AC50 is superseded in full.** The closed-at-two list, and the rule that each vendor scheme
  is a separate Owner decision at the time it is needed, do not survive this PRD. **What replaces it
  is not "the list is open" but "there is no list"**: providers are described in a bounded vocabulary
  (AC10) and shipped as data (AC6). **The named-and-not-re-argued part of AC50 partly survives**, in
  a different place: `github`, `stripe` and `slack` become presets (AC5), while IP allow-listing and
  mutual TLS stay out (AC50). *(Owned by the Product Manager. The edit to PRD-10 is made after the
  Owner approves this PRD, not before.)*
- **PRD-10 AC23 is revised in one clause.** The clause "**the list is closed and the scheme is
  selected, never described** … no member-composed signed string, no member-chosen digest or
  encoding" is exactly what this PRD reverses. The rest of AC23 — that verification is per proxy,
  configured by the proxy owner, with the secret the scheme needs — stands unchanged.
- **PRD-10 AC51 and AC52 are left standing, unchanged and unmigrated.** Grounds in AC44 and AC45.
  **They are not superseded, not deprecated, and not reimplemented on the template model.** A reader
  of PRD-10 who finds them should read them as still current.
- **PRD-10 AC24, AC25, AC26, AC27, AC28 and AC29 are relied on unchanged**, and this PRD's AC38–AC42
  restate how a template scheme composes with each rather than altering any.
- **PRD-10 § V2's ruled-out list is narrowed by exactly one entry.** "Member-composed or free-form
  verification configuration of any kind" is the entry this PRD reverses. **IP allow-listing and
  mutual TLS are not touched** and remain out.
- **ADR-022 is affected and the Principal Engineer owns the consequence.** It records a **closed
  `VerificationScheme` registry with one class per scheme**, and explicitly rejects "free-form
  verification configuration (member-chosen digest, encoding, signed-string template)" as an
  alternative. **This PRD does not edit it and states no view on how it should change** — whether a
  data-driven scheme fits behind that registry's existing seam or needs a superseding ADR is
  Q-16-01(a).
- **`design-10` is affected.** Its verification surfaces were designed against a two-option scheme
  selector (PRD-10 UX Direction point 6). **The design change is the Designer's**, belongs to
  `design-16`, and is not specified here.
- **`docs/product/roadmap.md` needs an item #16 and a revision note.** The roadmap runs to #15 and
  V2 is recorded there as settled by #10. **This PRD does not add the line.** Adding a backlog item
  is the Project Owner's act — that is how #15 was added — and the Product Manager records it once
  the Owner rules. **V2 itself is not reopened**: it asked which standards to support at MVP, and
  #10 answered it; this item changes how further ones are added, not that answer.
- **Nothing else is disturbed.** ADR-006's ingest URL, ADR-010's raw capture, ADR-008's strip list as
  PRD-10 AC27 already narrowed it, ADR-021's secret storage and rotation, PRD-05's retention
  contract, and PRD-02 / ADR-009's permission model are all relied on unchanged. This PRD adds **no**
  new permission (AC41), **no** new secret (AC23, AC40), and **no** new payload store (AC29).

## Out of Scope
Each names where it goes, or why nothing owns it yet.

- **RSA and certificate-based providers — PayPal, AWS SNS, and anything else asymmetric** — AC48.
  Different trust model, needs an outbound certificate fetch. Separate work; not rejected on merit.
- **Providers that sign something other than the body — Twilio (URL and sorted POST parameters)** —
  AC49. Incompatible with AC22 by construction. Separate work.
- **Mutual TLS, JWT-based verification, and IP allow-listing** — AC50. Already out under PRD-10 § V2
  and staying out.
- **Migrating `shared-secret` or `standard-webhooks` onto the template model** — AC44, AC45. This is
  a ruling, not a deferral.
- **Any change to outbound request signing** — AC46. PRD-10 AC54–AC64 stand.
- **Member-supplied code, expressions or regular expressions anywhere in the configuration** — AC47.
  A ruling, not a deferral.
- **Analytics, notifications or a log surface for live verification rejections** — AC51; **#13**,
  **#11**. Unchanged from PRD-10 AC46, and a stated cost.
- **Sharing a custom scheme between proxies, or a member-authored preset library** — AC52. Not ruled;
  would be its own decision.
- **Throughput or latency targets for verification** — AC53; **V8** deferred.
- **A new production dependency** — none is assumed. The Owner's instruction was to design as if no
  package exists; whether one should be used is Q-16-01(c), for the Principal Engineer.

## Open Questions

- **Q-16-01 (Principal Engineer — technical) — OPEN, raised by this PRD. Gates technical design;
  non-blocking for requirement approval.** Five items, none of which this PRD resolves or should:
  1. **How a data-driven template scheme composes with ADR-022's closed `VerificationScheme`
     registry**, which today has one class per scheme and explicitly rejected a member-composed
     signed string as an alternative. Whether that is an extension behind the existing seam or a
     superseding ADR is the Principal Engineer's call, not this PRD's.
  2. **How proving (AC24, AC27) composes with PRD-10 AC26's write-only secrets**, so a member can
     prove a scheme in the same sitting as first entering a secret without weakening the write-only
     rule.
  3. **Whether any production package should be used**, given the Owner's instruction to design as
     if none exists and check later. **Recorded here as a question rather than as a decision**; a new
     production dependency is an Owner approval in its own right under `CLAUDE.md`.
  4. **How the proven state is represented** so AC26 holds — an edit that has not passed cannot take
     effect, while the previously proven definition keeps verifying.
  5. **The cost of AC29's sample-handling constraints** — never an event, never dispatched, never
     logged, not retained — on the ingest and configuration paths.
  **If any finding contradicts a criterion in this PRD, it returns to the Product Manager as a
  requirement question, not a silent design change.** *(The question document is raised at handoff to
  the Principal Engineer, after the Owner approves this PRD.)*
- **Q-16-02 (Project Owner — product) — OPEN.** **Which providers ship as presets at launch beyond
  GitHub, Stripe and Slack?** Those three are named because the Owner named them (AC5). The rest is
  market knowledge the Product Manager does not have and will not invent. **Non-blocking for
  requirement approval**: AC5 sets the floor, and adding a preset is data under AC6, so the launch
  set can grow without reopening this PRD.
- **Nothing else is owed to the Project Owner as a question.** Every other product decision here is
  either the Owner's ruling of 2026-08-28 rendered into criteria, or a Product Manager call derived
  from an approved document and marked as such at the criterion. **The four items that need the
  Owner are ratifications rather than questions** and are listed in the Status block: the reversal,
  the consequences for PRD-10, the three filled gaps (AC7, AC19, AC20), and the missing roadmap line.

## Handoff
- **Inputs:** `docs/product/prd-10-sensitive-data-handling.md` (**AC23–AC29**, **AC50**, **AC51–AC53**,
  AC46, AC54–AC64, § V2, § UX Direction points 6 and 7 — the approved document this PRD extends and
  in one clause reverses) · `docs/architecture/adr-022-inbound-verification-at-the-ingest-boundary.md`
  (the closed scheme registry and the ingest seam — named in § Consequences, **not edited**) ·
  `docs/architecture/adr-021-secret-handling-and-rotation.md` (the rotation AC40 reuses) ·
  `docs/architecture/adr-010-raw-payload-capture.md` (the raw bytes AC13 runs over) ·
  `docs/architecture/adr-008-inbound-header-forwarding-policy.md` (the strip list AC39 extends) ·
  `docs/product/prd-02-role-based-collaboration.md` + `docs/architecture/adr-009-proxy-permission-mechanism.md`
  (the permission AC41 reuses) · `docs/product/prd-03-decoupled-upstream-response.md` (the response
  AC38 keeps withheld) · `docs/product/prd-05-payload-storage-retention.md` (the retention contract
  AC29 stays outside) · `docs/product/roadmap.md` (**V2**, and the missing #16 line) ·
  `docs/product/prd-15-pause-and-resume-dispatch.md` (format reference only) ·
  `docs/standards/documentation.md`.
- **Outputs:** this PRD. *(Q-16-01's question document is raised at handoff to the Principal Engineer,
  after approval.)*
- **Dependencies:** **#10 — in implementation. Nothing here may ship before #10 does.** #1, #2, #3,
  #5 — Done. **Independent of #8, #9, #11, #12, #13, #14 and #15**, and must not pre-empt any of
  them.
- **Outstanding Questions:** **Q-16-01** — Principal Engineer, technical, non-blocking for
  requirement approval. **Q-16-02** — Project Owner, product, non-blocking.
- **Next Agent:** **Designer.** `## UX Direction` is present, so under the mechanical routing rule a
  PM-approved `design-16` is a prerequisite for Technical Design — no exceptions. **The Designer must
  not start before the Project Owner has approved this PRD**, because the whole feature rests on a
  reversal the Owner has yet to ratify in writing, and because the custom-template experience the
  Designer would be designing is the part the Owner named as unsolved.
