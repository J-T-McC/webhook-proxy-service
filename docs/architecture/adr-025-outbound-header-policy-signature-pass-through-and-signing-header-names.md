# ADR-025: Outbound header policy — provider signature pass-through, branded signing headers, and no provenance headers

- **Status:** **ACCEPTED**, approved by the Project Owner on 2026-08-28. Both items at
  § *Owner-approval flags (✋)* were put to the Owner and both were approved as ruled: Decision 1
  unconditional rather than member-opt-in, and Decision 2 renaming all three headers before item #10
  merges. Decision 3 carried no gate. **Decision 2 is time-critical against item #10's merge** — see
  § *Sequencing*.
- **Author:** Principal Engineer
- **Date:** 2026-08-28
- **Feature:** cross-cutting. There is no PRD behind this ADR: it governs the outbound header set that
  ADR-008 established at item #1 and that item #10 extends, and it is written because the product
  position below changes what that header set should contain.
- **Relationship to existing ADRs:** **supersedes in part** `adr-008-inbound-header-forwarding-policy.md`
  (one named position — Decision 1) and **supersedes in part**
  `adr-023-outbound-request-contract.md` (the emitted signing header names — Decision 2). Both keep
  their files, their status and their full text, and gain inline pointers at the affected passages.
  See § *Positions superseded*.
- **Companions:** ADR-022 (inbound verification — untouched by every decision here, and the reason
  Decision 1 is safe) · ADR-021 (secret storage and rotation) · ADR-006 (the ingest URL, whose token
  is the reason for the constraint in Decision 3) · ADR-013 (the dispatched-output store, which
  determines when a forwarded signature is still verifiable) · ADR-015 (retry, same).
- **Evidence base:** `docs/architecture/prd-16-template-model-feasibility.md` — twenty-two providers'
  verification constructions, written up so that each one's answer to "does forwarding this header let
  the destination verify it?" is checkable rather than asserted. The file keeps its existing name so
  existing references resolve; its subject is now the header pass-through evidence base.

## The product position this ADR renders

The Project Owner ruled on 2026-08-28:

> "our proxy is not a security layer, it's to support fan out."

Three consequences follow, and they are the whole of why this ADR exists.

1. **Inbound verification is a convenience a member may use, not a guarantee the product makes.**
   The product offers it, it works as specified, and nothing about it is withdrawn — but the product
   is not underwriting a claim about the traffic it relays.
2. **The proxy's job is to deliver the webhook faithfully.** A recipient who wants to establish that
   a payload came from the original provider should be able to do so with that provider's own
   library, which requires the provider's own signature header to arrive.
3. **Security concerns are iterated on deliberately, later, rather than designed for up front.**
   That is a reason to defer, not a reason to design something provisional now — which is what
   Decision 3 rules.

Nothing in this ADR weakens a control that protects the product's own secrets or its members'. The
distinction it draws throughout is between a header that carries **key material** (never forwarded)
and a header that carries a **digest** (forwarded, because a digest discloses nothing and is the only
thing a recipient can check).

## Question

Three linked questions about what leaves this service in an outbound request's headers, none of which
any approved requirement answers:

1. **Should the five provider signature header names ADR-008 strips continue to be stripped**, given
   that stripping them is the single thing preventing a destination from verifying the original
   provider's signature — and if they are forwarded, what remains stripped and on what grounds?
2. **Under what names does this service emit its own signing headers**, given that the three names
   ADR-023 specifies are the same three names a Svix-family sender puts on an inbound request, and
   that `OutboundHeaders::build()` silently destroys a forwarded header colliding with an added one?
3. **Should the outbound request identify the upstream source** — under RFC 7239 `Forwarded`, under
   the `X-Forwarded-*` family, under product-prefixed headers of our own, or not yet?

## Positions superseded

Each row names a position, quotes it, and states what replaces it. Both source ADRs keep their files,
their Accepted status and their full text.

| Document | Position | Verbatim | Now |
|---|---|---|---|
| **ADR-008**, § Decision, fifth strip bullet — call it **P3** | Provider signature headers are stripped | "Inbound **webhook signature / verification headers** — provider signatures (e.g. `Stripe-Signature`, `X-Hub-Signature` / `X-Hub-Signature-256`, `X-Signature`, `X-Webhook-Signature` and equivalents) are computed over the original body for the original recipient; they are meaningless-to-misleading at a destination and can leak verification material." | **Superseded by Decision 1.** The five names are removed from `DeliveryUnit::STRIPPED_HEADERS` and forwarded. The premise that they "can leak verification material" is corrected in Decision 1: a provider signature header carries a digest, not a key. The premise that they are "meaningless-to-misleading at a destination" is the position the product ruling reverses — at a destination holding the provider's secret they are the only means of verification available. |
| **ADR-008**, § Reasoning, second bullet — call it **P4** | Provider signatures are grouped with `Cookie` and `Authorization` | "The stripped set is precisely the headers that are either **transport-scoped** (hop-by-hop, `Host`, `Content-Length`) or **secret/authenticator material scoped to the inbound leg** (`Cookie`, `Authorization`, provider signatures)." | **Superseded by Decision 1, in the grouping only.** `Cookie` and `Authorization` carry credentials and stay stripped for exactly the stated reason. Provider signatures do not belong in that category and are removed from it. The sentence is otherwise correct and the rest of the strip list stands on it. |
| **ADR-023**, Decisions 1 (step 3), 3 and 4 — the emitted names | The outbound signing headers are the specification's three names | "`webhook-id` is derived, not stored: `msg_{delivery.dispatch_uuid}_{delivery.destination_id}`" · "`webhook-signature` carries **one space-delimited `v1,<base64>` entry for each member of the proxy's live signing set**" | **Superseded by Decision 2, in the header names only.** The emitted names become `WebhookProxy-Id`, `WebhookProxy-Timestamp` and `WebhookProxy-Signature`, under a branded, non-`X-` prefix hard-coded at the single build point. **Everything else about these decisions is unchanged and remains operative**: the `msg_{dispatch_uuid}_{destination_id}` derivation, the per-attempt timestamp, one `v1,<base64>` entry per live secret space-delimited, the signed content `<id>.<timestamp>.<body>`, HMAC-SHA256, base64, and the single build point. |
| **ADR-023**, Decision 5 | Which names `STRIPPED_HEADERS` must not gain | "`DeliveryUnit::STRIPPED_HEADERS` is **not** extended with the three `webhook-*` names." | **Amended by Decisions 1 and 2, not superseded.** Its reasoning stands unchanged and now applies to the renamed trio: our own signing header names never go in the constant, because that would strip them from destinations of proxies that do not sign. Decision 1 additionally removes five names from that constant, which Decision 5 did not contemplate. |

**Not superseded, and named because each looks as though it should be:**

- **ADR-008's safe-allowlist policy itself** — forward everything except a stripped set — stands
  whole and is what Decision 1 operates within. The policy is unchanged; five entries leave the list.
- **ADR-008's `Host`, `Content-Length`, hop-by-hop, `Cookie` and `Authorization` strips** stand
  verbatim, each on grounds that have nothing to do with verification. Decision 1 restates those
  grounds so they are not read as collateral of the change.
- **ADR-022 in full.** Inbound verification is untouched by every decision here: the same schemes,
  the same seam, the same specification-named inbound headers, the same rejection behaviour. In
  particular `App\Verification\StandardWebhooksScheme` continues to read `webhook-id`,
  `webhook-timestamp` and `webhook-signature` from an inbound request, because those are the names a
  specification-conforming sender sends.
- **ADR-022 Decision 7 and PRD-10 AC27's per-proxy strip** stand and are load-bearing for Decision 1.
- **ADR-023 Decisions 2, 6, 7 and 8** — precedence by removal-then-addition, the dispatched bytes
  being unchanged, secrets never reaching a queued job, and the `withTrashed()` loads — all stand
  verbatim.

## Decision

### (1) The five provider signature header names are removed from the strip list and forwarded to destinations, unconditionally.

`DeliveryUnit::STRIPPED_HEADERS` loses `stripe-signature`, `x-hub-signature`,
`x-hub-signature-256`, `x-signature` and `x-webhook-signature`. A destination receives whatever
signature header the original provider sent, exactly as the provider sent it, so a recipient holding
that provider's secret can verify it with that provider's own library.

**This is unconditional, not member-opt-in.** A per-proxy toggle would be a column, a form control, a
resource field, a default nobody can choose well, and a decision presented to the person who does not
benefit from it: the value of pass-through accrues to the *destination operator*, and the toggle
would be shown to the *proxy owner*. Defaulted off, the useful case never happens; defaulted on, the
toggle is inert configuration that exists only to be found in a support conversation. Recorded here
so it is not re-proposed as a compromise.

**The change is smaller than it reads, and the evidence base says by how much.** The strip list
matches five exact header names, not a category. Against the twenty-one providers in
`docs/architecture/prd-16-template-model-feasibility.md`, those five names catch **three** —
Stripe, GitHub and Intercom. Slack, Shopify, Zoom, PagerDuty, WooCommerce, Xero, Linear, Twitch,
Paddle, Square, Discord, SendGrid, Twilio, PayPal and every Svix-family sender have been delivering
their signature headers to destinations since item #1, because none of those names is in the
constant. The current policy is therefore not "provider signatures are stripped" but "these five
names are stripped", and which side a member's provider falls on is decided by the name the provider
happened to choose. Decision 1 removes an inconsistency as much as it reverses a position.

**What remains stripped, and why each is unrelated to verification.** These grounds are restated in
full because Decision 1 removes the only entries in the list that were there for verification
reasons, and a reader should not infer that the rest are weaker for being adjacent to them.

- **`host`** — the destination's own host must be used, never the inbound one. A forwarded `Host` is
  a routing and request-smuggling hazard, and it is the ADR-006 guard in the outbound direction.
  It is also the header that would carry this proxy's own ingest hostname, which is the first half
  of a URL whose second half is a bearer credential. It never leaves.
- **`content-length`** — recomputed by the outbound HTTP client for the body actually sent. A
  forwarded value is a framing bug waiting for the first proxy that transforms a payload.
- **The RFC 7230 §6.1 hop-by-hop set** — `connection`, `keep-alive`, `proxy-authenticate`,
  `proxy-authorization`, `te`, `trailer`, `transfer-encoding`, `upgrade`. These are scoped by the
  standard to a single transport connection. Forwarding them is a protocol error, not a policy
  choice.
- **`cookie`** — inbound session state addressed to this service's origin. It is meaningless at a
  destination and is credential material.
- **`authorization`** — the sender's credential to *us*, not the destination's. Forwarding it hands
  a third party a secret that opens our own ingest.

**The per-proxy strip is unchanged and is what makes Decision 1 safe.** `OutboundHeaders::build()`
removes, per request, the header name or names this proxy's own verification configuration uses
(PRD-10 AC27, ADR-022 Decision 7, ADR-023 Decision 1 step 2). Under `shared-secret` that header
carries **the member's actual secret in plaintext**, and it must keep being stripped regardless of
anything in this ADR. Under `standard-webhooks` the three inbound specification headers are stripped
by the same mechanism. The strip is resolved from the proxy and applied before the added headers are
merged, so a member who names their `shared-secret` header `x-webhook-signature` — one of the five
names Decision 1 removes from the constant — is still protected: the constant no longer contains it,
and the per-proxy strip does.

**The only header that has ever carried key material rather than a digest is the one AC27 already
removes**, and that is the whole technical argument for Decision 1. A provider signature is
`HMAC(secret, content)`. Publishing it to a party who does not hold the secret discloses nothing that
party could not compute if it did.

**The constant's character changes and its docblock must say so.** After this decision
`DeliveryUnit::STRIPPED_HEADERS` is a **transport-scoped and credential-to-us deny-list**, not a
verification-artefact list. ADR-008's Impact note that the list is a maintained security control
carries forward for the entries that remain. A provider signature header name never goes back into
it; if a provider ever ships key material in a signature-shaped header, that is a per-proxy AC27
concern for that member's proxy, not a global constant.

**Sequencing.** `App\Support\OutboundHeaders` and the AC27 per-proxy strip arrive with item #10 and
do not exist on `main`. Removing the five names from the constant before that strip exists would
forward a `shared-secret` member's own secret to every destination. **Decision 1 therefore lands on
or after item #10's branch, and never before it.**

### (2) The outbound signing headers are renamed to a branded, non-`X-` prefix: `WebhookProxy-Id`, `WebhookProxy-Timestamp`, `WebhookProxy-Signature`.

`OutboundHeaders::signingHeaders()` emits those three names. **Nothing else about outbound signing
changes.** The value formats are exactly the Standard Webhooks ones:

```
WebhookProxy-Id:         msg_{dispatch_uuid}_{destination_id}
WebhookProxy-Timestamp:  <unix seconds, taken at this attempt>
WebhookProxy-Signature:  v1,<base64>  [space]  v1,<base64>   — one entry per live signing secret
```

The signed content stays `<id>.<timestamp>.<body>`, HMAC-SHA256, base64-encoded, computed over the
exact bytes about to be dispatched. A recipient can therefore still verify with a Standard Webhooks
library, provided that library allows the three header names to be configured — which is the ordinary
case, because the specification's own reference implementations parameterise the prefix.

**The three headers are renamed together.** They are one contract: a recipient reads all three or
verifies nothing. A partial rename is not an available option at any point.

#### The brand token is `WebhookProxy`, and the naming principle it satisfies

The token is the Project Owner's, supplied on 2026-08-28. It is not derived from anything in the
repository, and deliberately so: `config/app.php` reads `env('APP_NAME', 'Laravel')` and
`.env.example` sets `APP_NAME=Laravel`, both starter-kit residue and neither a product name.

**`WebhookProxy` is a single unhyphenated word, which matters for one reason worth recording.**
The emitted names therefore begin `WebhookProxy-`, not `Proxy-`, and so stay clear of the `Proxy-`
namespace RFC 9110 defines — `Proxy-Authenticate` and `Proxy-Authorization`, both of which this
service already strips from every outbound request as hop-by-hop fields. A hyphenated brand ending
in `Proxy` would have put the product's own headers next to a reserved namespace for no benefit.

The principle the token satisfies, stated so a later addition to the header set inherits it rather
than re-deciding it:

1. **The prefix is a brand token, not a description.** This is the requirement's own logic rather
   than a stylistic preference. Collision-freedom is what the prefix is for, and we cannot know what
   headers an upstream provider sends — the sender set is unbounded and not ours to enumerate. A
   descriptive prefix such as `Webhook-Proxy-` is therefore only *unlikely* to collide, and its
   likelihood is not something anybody can bound; a brand token cannot realistically collide at all,
   because a name that is nobody else's product name is a namespace nobody else has a reason to
   enter. **Branding is not the goal — it is the only available mechanism for the guarantee.**
2. **No `X-` prefix.** RFC 6648 (2012) deprecates the `X-` convention for new header field names.
   The providers still carrying it are the legacy ones — GitHub's `X-Hub-Signature-256`, Slack's
   `X-Slack-Signature` — and every newer scheme has dropped it: `Stripe-Signature`,
   `Paypal-Transmission-Sig`, and Svix's own `svix-id` / `svix-timestamp` / `svix-signature`. A
   product adding header names in 2026 has no reason to adopt a convention that was deprecated
   fourteen years ago.
3. **Prefixing is the specification's convention, not a departure from it.** Svix, the
   specification's originator, ships `svix-id`, `svix-timestamp` and `svix-signature` and treats the
   unprefixed `webhook-*` names as the generic alias for senders with no prefix of their own. A
   product that both forwards other senders' headers and emits its own is precisely the case a prefix
   exists for. *(No `standard-webhooks` or `svix` package is installed in this repository, so this is
   stated from the specification rather than verified against vendor code. The decision does not
   depend on it: even if no vendor prefixed, the unprefixed names are a shared namespace this product
   does not own, and the collision below is reason enough on its own.)*
4. **The Standard Webhooks value format is retained in full** — `v1,<base64>` entries,
   space-delimited, one per live signing secret — so the rename costs a recipient a configuration
   line and never a reimplementation.

**The three names are hard-coded.** They are a constant of `App\Support\OutboundHeaders`, the single
build point, and are not read from configuration, from the environment, from `APP_NAME`, or from any
per-proxy or per-team value. Two properties follow and both are wanted. The wire contract is
identical in every deployment, so a recipient's configuration is the same everywhere and support
questions have one answer. And **no member can influence what this service calls its own headers** —
a member-chosen prefix would let them name their outbound headers `webhook-`, reintroducing exactly
the collision this decision exists to remove, or name them after another sender's namespace at a
destination that trusts it.

#### Casing is a documentation convention and carries no behavioural meaning

The names are written above in Title-Case-With-Hyphens because that is how header names read in
documentation and in the design surfaces that will quote them. **That is all it is.** HTTP field
names are case-insensitive (RFC 9110 §5.1), and HTTP/2 and HTTP/3 transmit them lowercased on the
wire, so a recipient must match them case-insensitively regardless of what any document shows.

This service already behaves correctly on both sides of that, verified in the code rather than
assumed: `OutboundHeaders::withoutNames()` lowercases both the candidate name and every name it is
matching against (`array_map(strtolower(...), $names)` and `strtolower($name)`), and
`DeliveryUnit::forwardHeaders()` lowercases each inbound name before testing it against the constant.
**No behaviour anywhere depends on the casing of a header name**, and a Reviewer should treat a test
that asserts a particular casing as testing the wrong property.

**The collision this closes, precisely.** `OutboundHeaders::build()` drops any forwarded header whose
lowercased name matches an added one (ADR-023 Decision 2 — added headers always win, for good
reasons that are unchanged). Svix-family senders — and there are many, since a large number of
products ship Svix-backed webhooks — send `webhook-id`, `webhook-timestamp` and `webhook-signature`
inbound. On a proxy that has **no** verification configured, PRD-10 AC43 is explicit that nothing
strips them, so they are forwarded. If that proxy also signs, our three identically-named headers
displace the sender's three, **silently**: there is no log line, no delivery-attempt record, no
surface and no error. The destination receives a signature it cannot verify against the provider's
secret, in a header that says it should be able to. Under Decision 1 that forwarded header is
precisely the one the destination now needs.

**A second reason, independent of the collision.** The unprefixed names identify a *format*, not a
*sender*. A destination receiving traffic from more than one relay, or from this service and from a
Svix-backed provider, cannot tell whose signature it is holding. A prefixed name answers that
question in the name itself.

**The one cost, stated rather than discovered.** A recipient whose library hard-codes the
`webhook-*` names must configure the prefix, or read the three headers by name and call the library's
verification primitive directly. That is the entire ask the rename makes of a recipient, and it is
why Decision 2 has to land before anybody has configured a recipient at all.

**Inbound is untouched.** `App\Verification\StandardWebhooksScheme` continues to read `webhook-id`,
`webhook-timestamp` and `webhook-signature` from an inbound request, because that is what a
conforming sender sends; `DeliveryUnitResolver`'s AC27 strip map for the `standard-webhooks` scheme
continues to name those same three inbound header names. **A global find-and-replace of `webhook-`
across the codebase is the one wrong way to implement this decision**, and it would silently break
AC27's strip.
`App\Support\StandardWebhooks` is header-name-agnostic — `sign($id, $timestamp, $body, $secret)`
takes no header names at all — so PRD-10 AC55's "one implementation serves both directions" is
unaffected in substance.

#### Sequencing — this must land before item #10 merges

**Before #10 merges, this rename costs nothing.** Outbound signing has never shipped, so no recipient
anywhere has been configured to expect any header name from this service.

**After #10 merges, it is a breaking change for every member who has configured a receiver.** The
failure mode is the worst available: the destination stops verifying, silently, because the headers
it was told to look for are absent. There is no version negotiation on the wire, no notification
surface in the product (PRD-10 AC46 ships none), and no record on our side of which destinations are
verifying, so we could not tell the affected members even if we chose to.

**Therefore: the rename is applied on `feat/item-10-sensitive-data`, before that branch merges to
`main`.** It is a change to the T34 implementation and to the header-name assertions in the T35 and
T40 suites, not a new feature. If #10 merges first, the rename becomes an item of its own carrying a
member-notification requirement that nothing in the product currently supports.

#### Consequences for approved documents — routed, not edited

Three approved documents describe the emitted header names and become stale on this decision. **None
is edited by this ADR**, and each belongs to a different role:

- **PRD-10 AC55** — "Same specification, same three headers, same signed content, same HMAC-SHA256
  and base64 encoding." The clause "same three headers" is the one clause Decision 2 displaces;
  the specification, the signed content, the algorithm and the encoding are all unchanged. **This is
  a Product Manager amendment.** AC64 — the signing headers take precedence over any forwarded
  inbound header of the same name — remains true and becomes trivially satisfiable, since no sender
  sends `WebhookProxy-*`; its stated scenario (a proxy verified under `standard-webhooks` receiving those
  three names inbound) is the collision Decision 2 removes.
- **`design-10` Screen 6** lists `webhook-id`, `webhook-timestamp` and `webhook-signature` verbatim
  in its member-facing disclosure copy (lines 534–536). **The copy correction is the Designer's**,
  routed through the Product Manager.
- **`plan-10`** cites the three names in § *Architecture*, § *Risks* (R9) and § *Validation*.
  It is this document's own artifact, and it gains a pointer rather than a rewrite — see
  § *Impact*.

### (3) No provenance headers. `Forwarded`, the `X-Forwarded-*` family and a custom equivalent are all deferred.

The outbound request carries no header whose purpose is to identify the upstream source or the relay
hop. This is a deferral with stated conditions for revisiting, not a rejection on merit.

**First: those header families are instructions to infrastructure, not description.** `Forwarded`
(RFC 7239) and `X-Forwarded-For` / `-Host` / `-Proto` / `-Port` are consumed by Laravel's own
TrustProxies middleware — this application does exactly that at `bootstrap/app.php:36`,
`trustProxies(at: '*')` over all four — and by nginx's real-ip module, by Cloudflare, and by most web
application firewalls. A value this service emits may be adopted by the destination's own
infrastructure as the client's identity, changing IP attribution, rate-limit keying and allow-list
evaluation on the far side. That is this service reaching into somebody else's security posture,
which is the opposite of what a fan-out relay should do, and it is a change whose effects we cannot
observe or test.

**Second: there is nothing true to put in them.** No source IP is captured anywhere.
`webhook_events` has no such column — verified against
`2026_08_04_000002_create_webhook_events_table.php` and the later
`2026_08_05_000001_alter_webhook_events_for_payload_erasure.php`, which are the only migrations that
shape that table — and `IngestController` never calls `$request->ip()`. Emitting a `for=` value
therefore requires new capture at ingest, a new column, and a retention position, because a client IP
is PII-adjacent and lands directly in the erasure and retention machinery item #10 has just built.
That is a data-model change and an Owner gate in its own right, incurred for a requirement nobody has
stated.

**Third: most of what "identify the upstream source" means is already delivered, by Decision 1 and by
what is already on the wire.** After Decision 1 the provider's own headers arrive intact — not only
its signature, but `x-github-event` and `x-github-delivery`, `x-shopify-topic`, `stripe-signature`,
and every other descriptive header ADR-008 has forwarded since item #1. A destination can identify
the provider in the provider's own vocabulary, which is more useful than anything this service could
synthesise. On a signing proxy, `WebhookProxy-Signature` identifies this service cryptographically and
`WebhookProxy-Id` identifies the specific delivery.

**A hard constraint that binds whatever a later decision does: the ingest path must never appear in a
host, URL or referrer header.** The path contains the ingest token, which is a bearer credential
under ADR-006 — presenting it is the whole of ingest authentication. So no `Forwarded: host=`, no
`X-Forwarded-Host`, no `X-Original-URL`, no `Referer`, and no product-supplied value derived from the
live request's URL. This compounds with a live property of the application:
`trustProxies(at: '*', … HEADER_X_FORWARDED_HOST …)` makes `$request->getHost()` and
`$request->fullUrl()` caller-controllable, so a host value read from the live request would be both a
credential leak and attacker-influenced. Any such value, if one is ever wanted, must come from stored
proxy configuration and never from the request. `host` remaining in ADR-008's strip list (Decision 1)
is what enforces this today.

**What would have to be true to revisit, in order:**

1. **A stated requirement naming what a destination does with the value** — attribution, allow-listing
   or audit. None exists today, and a provenance header with no named consumer is a header nobody can
   evaluate the correctness of.
2. **An Owner ruling to capture the client IP, carrying its retention and erasure position.**
   Carrying `for=` cannot precede that ruling, because the value is PII-adjacent and the erasure
   machinery has to own it from the first row written.
3. **A ruling that provenance travels under this product's own branded prefix** (`WebhookProxy-*`) and never under
   `Forwarded` or `X-Forwarded-*`, so it cannot be consumed by the destination's infrastructure as
   client identity.
4. **A value that provably cannot contain the ingest path or token**, per the constraint above.

## Scope boundaries

Two technical limits survive the product position because neither is a security argument, and a third
follows from Decision 1's own shape. All three bound what recipient-side verification can be, and
none of them is repairable by any header policy.

**(a) A forwarded provider signature is verifiable at the destination only where the signed content
derives from what survives the hop — body bytes and headers — and only where the body is
byte-identical.** Byte-identity holds on a proxy with no transform configured, and that is structural
rather than incidental: every payload-mutating step is enhanced-mode-only (`MapStep` for #8,
`NormalizeStep` for #9) and `CaptureDispatchedStep` is composed immediately before delivery, so a
simple-mode proxy dispatches the raw captured bytes and ADR-013's divergence gate resolves to the raw
capture. On an enhanced proxy with a transform configured, the dispatched bytes are by design not the
received bytes, and **every** body-derived signature fails at the destination — correctly, because
the payload genuinely is not the one the provider signed. Two secondary properties worth naming: a
**retry** re-sends the recorded dispatched bytes (ADR-015, ADR-013), so a forwarded signature stays
consistent with the body across retries; and a `multipart/form-data` request yields an empty
`$request->getContent()` under PHP, so nothing body-derived is verifiable downstream for such a
sender. That last one is a pre-existing property of the ingest path, not something this ADR
introduces.

**(b) Twilio signs the request URL together with its sorted POST parameters, not the body.** Its
signature is computed over a URL the destination is not serving, so it cannot be recipient-verified
through a proxy under any header policy. It is excluded by construction rather than by choice, and
nothing in Decision 1, Decision 2 or a future Decision 3 changes that. Two related cases from the
evidence base: **Square** signs its notification URL plus the body, and for this product that URL is
the token-bearing ingest URL — so it is not merely unverifiable at a destination, it is unverifiable
in a way that could only be repaired by disclosing a credential, which is why Decision 3's constraint
is stated as absolute; and **PayPal** includes a per-account `webhookId` scoped to whoever registered
the ingest URL, which a destination is not.

**(c) This ADR makes recipient-side verification possible where the provider's construction allows
it. It does not make it a product guarantee.** No surface claims it, no configuration exposes it, no
criterion is asserted about it, and a member cannot see from the product whether a given destination
can verify a given provider. The evidence base records which providers fall on which side; the
product says nothing.

## Consequence for PRD-16 — for the Product Manager

`docs/product/prd-16-configurable-inbound-verification.md` is Draft, has never been approved, and is
**not edited by this ADR**. It is the Product Manager's document. What follows is the technical
consequence of the product position, in enough detail to be acted on without reconstructing the
reasoning.

**The load-bearing change is to the justification, not to the mechanism.** PRD-16's § Problem item 4
states the danger the document is built around: "A member who can describe a construction can describe
a worthless one … and earn a green 'verified' badge that means nothing." Three of its criteria — AC22
(`{body}` is mandatory, with "no override, no advanced mode, and no per-team exception"), AC25/AC26
(nothing goes live unproven, and an edit does not take effect until re-proven) and AC44
(`shared-secret` must not be migrated onto the template model) — are justified by that danger, and
that danger is a danger *because the product is underwriting the badge*. Under the position that
inbound verification is a convenience a member may use rather than a guarantee the product makes,
the badge is a member's statement about their own configuration, and the argument that carries those
three criteria changes from safety to usability.

**Item by item, so the Product Manager can rule rather than re-derive:**

1. **AC22, AC25 and AC26 may still be wanted, but on different grounds.** An unproven template
   silently rejects every request, which is a bad member experience regardless of what the badge
   claims; that argument survives intact. What does not survive unchanged is AC22's absolute form.
   It excludes real providers by construction — Mailgun, Twilio, Adyen and AWS SNS are all documented
   in the evidence base as providers whose signed string contains no part of the body — and the
   reason for excluding them was that letting them through would produce a meaningless product
   claim. That reason is the one the ruling changes.
2. **AC23 — "the secret is always the HMAC key and is never part of what is signed" — is unaffected
   and stands on its own ground.** It is not a badge rule: AC43 displays the template to anyone who
   can view the proxy, so a secret placed in a template is a secret in displayed configuration. The
   evidence base records one legitimate case that pushes against AC43's supporting rationale — Square
   signs the notification URL, which for this product is the token-bearing ingest URL — and that is a
   separate question the Product Manager already has on the list.
3. **The template engine itself is untouched.** AC10, AC11 and AC15–AC21 describe a mechanism for
   expressing a construction. Nothing in the ruling bears on whether a bounded vocabulary can express
   a provider's signed string; the twenty-one-provider evidence answering that question is unchanged
   and survives in `docs/architecture/prd-16-template-model-feasibility.md`.
4. **PRD-16 AC39 remains required and is not affected by Decision 1.** AC39 extends PRD-10 AC27's
   strip to a template scheme's named headers. That is the **per-proxy** strip, not the constant
   Decision 1 shortens, and it must continue to strip every header a template scheme names — under
   a `shared-secret`-shaped configuration the named header carries the member's secret.
5. **Decision 1 creates one genuinely new interaction that PRD-16 has no criterion for.** Before this
   ADR, a provider's signature header was stripped whether or not the proxy verified anything. After
   it, the header is forwarded **unless** the proxy names it as its own verification header — in
   which case AC27, and PRD-16's AC39, strip it. The consequence for a member: **configuring inbound
   verification for a provider now removes that provider's signature from every outbound dispatch**,
   so the member gains inbound verification and their destinations lose the ability to verify the
   provider themselves. That trade is real, is invisible in the interface as currently specified, and
   is a requirement question rather than a design detail.
6. **The evidence base is reframed and should be read as evidence about constructions, not as a
   coverage claim.** `docs/architecture/prd-16-template-model-feasibility.md` retains all
   twenty-one provider findings and now organises them around whether a forwarded signature is
   verifiable at a destination. Its counts are a convenience sample and say so.

## Alternatives

Only options that would otherwise be re-proposed are recorded.

- **A per-proxy opt-in for provider-signature pass-through.** Rejected in Decision 1: the toggle
  would be shown to the party that does not benefit from it, and neither default is defensible.
- **Adding the outbound signing names to `STRIPPED_HEADERS` instead of renaming them.** Rejected
  twice over. ADR-023 Decision 5 already rejects it because it changes what an unsigned proxy's
  destination receives and breaches PRD-10 AC63; and after Decision 1 it would strip the *provider's*
  headers rather than ours, which is the exact outcome Decision 1 exists to prevent.
- **An `X-`-prefixed name** (`X-WebhookProxy-Signature`). Rejected: RFC 6648 deprecated the `X-`
  convention for new header field names in 2012, and every scheme newer than that has dropped it.
- **A descriptive prefix** such as `Webhook-Proxy-`. Rejected on the requirement rather than on
  taste: the sender set is unbounded, so a descriptive name is only unlikely to collide and nobody
  can bound how unlikely. See Decision 2.
- **Member-configurable outbound header names, per proxy.** Rejected in Decision 2, and worth
  recording separately because it is the version of the idea that is actively harmful rather than
  merely unnecessary: a member choosing their own prefix could name their outbound headers
  `webhook-` and reintroduce exactly the collision Decision 2 removes, or name them after another
  sender's namespace at a destination that trusts it.
- **Emitting `Forwarded` or `X-Forwarded-For` now, with a client IP captured at ingest.** Rejected in
  Decision 3 on three independent grounds, any one of which is sufficient.
- **A provenance header carrying the ingest URL or host.** Rejected outright and permanently: the
  ingest path contains a bearer credential.
- **Keeping the five names stripped and having the destination rely on our signature instead.**
  Rejected: our signature attests that the payload came from this service, not that it came from the
  provider. They are different claims, and only the provider's own signature makes the second one.
  We cannot re-sign on the provider's behalf, because we do not hold the provider's secret.

## Reasoning

- **The distinction that carries Decision 1 is digest versus key material, and it is checkable.**
  Every header the strip list retains carries either transport state or a credential. Every header it
  releases carries an HMAC output. That is a line a Reviewer can apply to a header they have never
  seen before, which the previous grouping — "verification headers" — was not.
- **Decision 2 is a naming decision with a deadline, and the deadline is what makes it an ADR.** The
  mechanism is a three-line change. What is significant, and irreversible in the ordinary sense, is
  that after #10 merges the names are a published contract held by parties we cannot enumerate or
  notify.
- **Decision 3 is a deferral with conditions rather than a rejection, because the requirement behind
  it is real and the mechanism is not.** "Identify the upstream source" is a legitimate thing to want.
  Every header family that would express it today either instructs infrastructure we do not own,
  requires data we do not capture, or carries a credential. Naming the four conditions makes the
  revisit cheap without committing the product to a shape now.
- **Nothing here is provisional.** The product position rules that security concerns are iterated on
  deliberately and later. Adding a provisional provenance header now, or a toggle that hedges Decision
  1, would be the opposite of that — a shape the product would have to keep supporting while the
  deliberate decision was being taken.

## Impact

### Code — the complete change set

**Decision 1 —** `app/Pipeline/DeliveryUnit.php`

- Remove the five entries `stripe-signature`, `x-hub-signature`, `x-hub-signature-256`,
  `x-signature`, `x-webhook-signature` from `STRIPPED_HEADERS`, together with the comment block
  above them. The remaining twelve entries and the `forwardHeaders()` implementation are unchanged.
- Rewrite the constant's docblock so it describes a **transport-scoped and credential-to-us**
  deny-list, citing this ADR. The current text describes it as covering "inbound webhook signature /
  verification headers", which after this change is false.

**Decision 2 —** `app/Support/OutboundHeaders.php`

- `signingHeaders()` returns the keys `WebhookProxy-Id`, `WebhookProxy-Timestamp` and
  `WebhookProxy-Signature`, as literals in this class and nowhere else. The
  `msg_{dispatchUuid}_{destinationId}` derivation, the `now()->getTimestamp()` call, the
  `'v1,'.StandardWebhooks::sign(…)` mapping and the `implode(' ', $entries)` join are unchanged.
- **No configuration key, no environment variable and no per-proxy value is added.** A reviewer
  finding a header name read from `config()` or from a model attribute should treat it as a defect
  against this decision.
- Update the class docblock and the `signingHeaders()` docblock, both of which name the old headers.

**Untouched, and each is a place a careless rename would break something:**

- `app/Verification/StandardWebhooksScheme.php` reads `webhook-id`, `webhook-timestamp` and
  `webhook-signature` from the **inbound** request. Unchanged.
- `app/Services/DeliveryUnitResolver.php`'s
  `VerificationScheme::StandardWebhooks => ['webhook-id', 'webhook-timestamp', 'webhook-signature']`
  map is the **AC27 inbound strip list**. Unchanged.
- `app/Support/StandardWebhooks.php` takes no header names. Unchanged.

**Tests.** The header-name and strip-set assertions in `tests/Unit/Pipeline/DeliveryUnitTest.php`,
in the T35 byte-identical signing regression and in the T40 outbound signing suite (AC54–AC64) are
**superseded, not weakened** — they assert the previous contract correctly and are updated to assert
the new one. One test is worth adding rather than editing: a proxy with **no** verification
configured, receiving a Svix-shaped inbound request and signing its dispatch, must deliver **both**
the sender's `webhook-*` trio and this service's `WebhookProxy-*` trio. That is the defect Decision 2
closes and nothing currently covers it.

**Data model:** none. **New dependency:** none. **Stack change:** none. **New config key:** none.
**Migration or backfill:** none.

### Documents

- **ADR-008** gains inline pointers at the two superseded positions and a sub-bullet under its Status
  line. Its Decision, Alternatives, Reasoning and Impact sections are otherwise untouched, and its
  Accepted status is unchanged.
- **ADR-023** gains inline pointers at Decisions 3, 4 and 5 and a sub-bullet under its Status line.
  Untouched otherwise.
- **`plan-10`** gains a pointer section naming the three places it states the old header names. It is
  a pointer, not a revision: no ruling, gate, milestone or approval in that plan changes, and nothing
  in it becomes false until this ADR is Accepted.
- **PRD-10, `design-10` and ADR-022 are not edited.** The PRD-10 AC55 amendment and the `design-10`
  Screen 6 copy correction are routed above and belong to the Product Manager and the Designer.
- **`docs/architecture/prd-16-template-model-feasibility.md`** is reframed as the evidence base for
  Decision 1. It keeps its filename, so existing references still resolve.
- **`docs/status.md`** needs a row for this ADR and its Owner gate. That is the Orchestrator's upkeep
  and is not done here.

### Constrained, carried forward

- **`OutboundHeaders` remains the only place an outbound header set is built** (ADR-023 § Impact,
  unchanged). A second build site is a review finding.
- **`DeliveryUnit::STRIPPED_HEADERS` is a transport-and-credential constant.** A provider signature
  header name never goes back into it, and a per-proxy name never goes into it (ADR-023 Decision 5,
  amended but operative).
- **This service's own outbound header names carry the branded `WebhookProxy-` prefix, never `X-`.** Any
  header this product adds to an outbound request in future uses it, so the namespace question is
  settled once rather than per header.
- **No behaviour depends on header-name casing.** Documentation uses Title-Case-With-Hyphens;
  matching is case-insensitive everywhere, as HTTP requires.
- **The ingest path never appears in an outbound header**, in any form, under any decision.
- **Inbound verification's header names are the specification's** and are not renamed with the
  outbound ones.

## Owner-approval flags (✋)

Two items need the Project Owner. Each reverses or displaces something the Owner has previously
ratified, which is why neither is self-certified under the delegated plan gate.

1. **Decision 1 — provider signature headers are forwarded to destinations, unconditionally.**
   This reverses ADR-008 position P3, which the Project Owner approved on 2026-07-30, and it changes
   what leaves this system for every existing proxy with no member action and no migration. The exact
   change is five names removed from `DeliveryUnit::STRIPPED_HEADERS`:
   `stripe-signature`, `x-hub-signature`, `x-hub-signature-256`, `x-signature`,
   `x-webhook-signature`. Everything else in that constant stays, and the per-proxy verification-header
   strip stays. **The alternative ruling available to the Owner** is member-opt-in, which Decision 1
   argues against on the grounds recorded there; a `no` on unconditional pass-through substitutes a
   per-proxy boolean, a form control, a resource field and a default the Owner would also need to
   rule on.
2. **Decision 2 — the outbound signing headers are renamed to a branded, non-`X-` prefix
   (`WebhookProxy-Id`, `WebhookProxy-Timestamp`, `WebhookProxy-Signature`), all three together, retaining the
   Standard Webhooks `v1,<base64>` value format, and the rename lands before item #10 merges.**
   This supersedes the emitted names in ADR-023 Decisions 3 and 4, ratified by the Owner's approval
   of PRD-10 on 2026-08-27, and it displaces the clause "same three headers" in **PRD-10 AC55** — a
   Product Manager amendment this ADR routes rather than makes. It also makes `design-10` Screen 6's
   disclosure copy stale, which is the Designer's correction. **The sequencing is part of what is
   being approved:** approving this after #10 merges converts a three-line change into a breaking
   change for members whose receivers are already configured, with no notification surface available
   to soften it.
**The brand token `WebhookProxy` is the Project Owner's, supplied on 2026-08-28, and is recorded in
Decision 2 as decided rather than carried here as a question.**

**Decision 3 carries no gate**, and its absence from this list is deliberate rather than an
oversight: it defers, changes no behaviour, adds no header, captures no data and closes no option.
The four conditions for revisiting it are recorded in the decision itself.
