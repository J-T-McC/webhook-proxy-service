# Provider webhook signature constructions — the evidence base for outbound header pass-through

- **Status:** **Evidence — informational. Not a decision, not a technical plan, and not an ADR.**
  It records what twenty-two providers actually do — twenty-one of which sign something — so that
  decisions taken elsewhere can be checked against facts rather than against assertions. Nothing in it approves anything, changes any
  acceptance criterion, or commits the product to a design.
- **Author:** Principal Engineer
- **Date:** 2026-08-28
- **The question it answers:** for each provider, **where the signature lives, what is signed, and
  whether a destination of this proxy could verify that signature if the provider's header reached
  it.** That is the question
  `docs/architecture/adr-025-outbound-header-policy-signature-pass-through-and-signing-header-names.md`
  Decision 1 turns on, and this document is its evidence base.
- **Also used by:** `docs/product/prd-16-configurable-inbound-verification.md` (Draft). The
  per-provider constructions below are equally evidence about what an inbound verification model
  would have to express; that document is the Product Manager's and is not read into or edited from
  here.
- **Reads:** `docs/architecture/adr-008-inbound-header-forwarding-policy.md` ·
  `docs/architecture/adr-022-inbound-verification-at-the-ingest-boundary.md` ·
  `docs/architecture/adr-010-raw-payload-capture.md` ·
  `docs/architecture/adr-006-ingest-url-generation-security.md` · `docs/stack/stack.md` ·
  `app/Pipeline/DeliveryUnit.php`, `app/Http/Controllers/IngestController.php`,
  `app/Http/Middleware/EnforceIngestBodyLimit.php`, `app/Services/WebhookEventCapture.php`,
  `routes/ingest.php`, `bootstrap/app.php`.

## The test each provider is put to

A destination of this proxy can verify a provider's original signature when **all four** of the
following hold. The first two are properties of the provider's construction, the third is a property
of this proxy's header policy, and the fourth is a property of the proxy's configuration.

1. **The signature material survives the hop.** It is carried in a request header that reaches the
   destination, or in the body, which is forwarded in full.
2. **The signed content derives only from what survives the hop** — the body bytes and the request
   headers. A construction that includes the request URL, or a value scoped to the original
   recipient, cannot be checked by a party that is neither at that URL nor that recipient.
3. **The header is not stripped on the way out.**
4. **The body is byte-identical to what the provider signed** — true on a proxy with no transform
   configured, false by design on one that transforms the payload.

Condition 4 is uniform across every provider and is stated once here rather than repeated in each
block. Conditions 1 and 2 are what actually distinguish providers, and they are what the tables
record.

### What the strip list catches today, which is less than its description suggests

`DeliveryUnit::STRIPPED_HEADERS` removes five exact header names:
`stripe-signature`, `x-hub-signature`, `x-hub-signature-256`, `x-signature` and
`x-webhook-signature`. Matched against the providers below, **that catches three of
them** — Stripe, GitHub and Intercom — plus two generic names no provider in this sample uses
verbatim.

Every other provider's signature header has been reaching destinations since item #1:
`x-slack-signature`, `x-shopify-hmac-sha256`, `x-zm-signature`, `x-pagerduty-signature`,
`x-wc-webhook-signature`, `x-xero-signature`, `linear-signature`,
`twitch-eventsub-message-signature`, `paddle-signature`, `x-square-hmacsha256-signature`,
`webhook-signature`, `x-signature-ed25519`, `x-twilio-signature`,
`x-twilio-email-event-webhook-signature` and `paypal-transmission-sig` are none of them in the
constant. **The current policy is therefore not "provider signatures are stripped" but "these five
names are stripped"**, and the difference between a Stripe proxy and a Slack proxy is which name the
provider happened to choose. Recorded because it bears directly on how large a change removing the
five names is: for eighteen of twenty-one providers, nothing changes at all.

## Method, and how confident each entry is

Each provider below is written from working knowledge of its published verification scheme. That is
good enough to establish the **shape** of a construction, which is what conditions 1 and 2 turn on.
It is not good enough to be copied into shipped configuration.

- **Confidence: High** — the construction shape and the header names are ones I am confident of.
  Exact header casing and the current algorithm still need checking against live documentation
  before any of this becomes data in the product.
- **Confidence: Medium** — the shape is right in outline; at least one element (a separator, an
  encoding, a header name) must be verified against the provider's live documentation before use.

**Every signature value shown below is illustrative and is not a computed digest.** They are
plausible-looking strings of the right length and alphabet, present so a reader can see the shape of
the header. **No example here is a test vector.** Every body is short so substituted strings stay
readable; real bodies are kilobytes and the mechanism is identical.

---

# Part 1 — providers a destination can verify

Each of these carries its signature in a header, over content derived only from the body and the
headers. A destination holding the provider's secret can verify it, given a byte-identical body.

## GitHub

- **Signed content:** the raw request body, nothing else.
- **Headers:** `X-Hub-Signature-256` carries the signature. `X-GitHub-Delivery` carries a delivery
  UUID and `X-GitHub-Event` the event name; neither is signed.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal (lowercase), after the fixed prefix `sha256=`.
- **Stripped today:** **yes** — `x-hub-signature-256`.
- **Confidence: High.**

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-GitHub-Event: push
X-GitHub-Delivery: 72d3162e-cc78-11e3-81ab-4c9367dc0958
X-Hub-Signature-256: sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17

{"zen":"Non-blocking is better than blocking.","hook_id":109948940}
```

The signed content is the body verbatim. GitHub also sends the legacy `X-Hub-Signature: sha1=…`
alongside, which is stripped today by the same list.

## Stripe

- **Signed content:** `<timestamp>.<body>`.
- **Headers:** `Stripe-Signature` carries **both** the signature and the timestamp, as named parts of
  one comma-separated list — `t=` for the timestamp, `v1=` for the signature.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Stripped today:** **yes** — `stripe-signature`.
- **Confidence: High.**

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
Stripe-Signature: t=1756382400,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd

{"id":"evt_1PabcXYZ","type":"payment_intent.succeeded"}
```

**Signed content, substituted:**

```
1756382400.{"id":"evt_1PabcXYZ","type":"payment_intent.succeeded"}
```

Stripe sends `v0=` entries as well for some accounts, and can send more than one `v1=` entry during
a signing-secret rotation. A verifier must therefore read the list rather than the first entry — a
property it shares with PagerDuty and with the Standard Webhooks specification.

## Slack

- **Signed content:** `v0:<timestamp>:<body>` — `v0:` and the two colons are literal.
- **Headers:** `X-Slack-Signature` carries the signature after the prefix `v0=`;
  `X-Slack-Request-Timestamp` carries the timestamp as a separate header.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal. Slack documents a five-minute replay window.
- **Stripped today:** no.
- **Confidence: High.**

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-Slack-Request-Timestamp: 1756382400
X-Slack-Signature: v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503

{"type":"event_callback","event":{"type":"app_mention"}}
```

**Signed content, substituted:**

```
v0:1756382400:{"type":"event_callback","event":{"type":"app_mention"}}
```

Slack's Events API sends JSON, as above. Slash commands and interactivity payloads arrive as
`application/x-www-form-urlencoded`, which is the same construction over different bytes and touches
§ *Does the body survive byte-identical?* below.

## Shopify

- **Signed content:** the raw body.
- **Headers:** `X-Shopify-Hmac-Sha256` carries the signature **bare** — no prefix, no list.
  `X-Shopify-Webhook-Id` and `X-Shopify-Topic` are present and unsigned.
- **Algorithm / encoding:** HMAC-SHA256 · **base64**. Shopify and GitHub sign identical strings and
  differ only in how the digest is spelled.
- **Stripped today:** no.
- **Confidence: High.**

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-Shopify-Topic: orders/create
X-Shopify-Shop-Domain: example.myshopify.com
X-Shopify-Webhook-Id: b54557e4-7ab1-4c4a-9c9c-2f9a1c0a1e10
X-Shopify-Hmac-Sha256: XWmrwMey6OsLMeiZKwP4FppHH3cmAiiJJAweH5Jo4bM=

{"id":820982911946154508,"financial_status":"paid"}
```

## Intercom

- **Signed content:** the raw body.
- **Headers:** `X-Hub-Signature` — the GitHub-shaped name, with a `sha1=` prefix.
- **Algorithm / encoding:** **HMAC-SHA1** · hexadecimal. The key is the app's client secret.
- **Stripped today:** **yes** — `x-hub-signature`.
- **Confidence: Medium** — the shape is GitHub's; whether Intercom now also offers a SHA-256 variant
  should be verified against live documentation.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-Hub-Signature: sha1=7d38cdd689735b008b3c702edd92eea23791c5f6

{"type":"notification_event","topic":"conversation.user.created"}
```

## Zoom

- **Signed content:** `v0:<timestamp>:<body>` — Slack's construction, different header names.
- **Headers:** `x-zm-signature` (signature, prefix `v0=`), `x-zm-request-timestamp` (timestamp).
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Stripped today:** no.
- **Confidence: Medium** — construction is confidently Slack-shaped; header casing and the current
  prefix should be verified.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
x-zm-request-timestamp: 1756382400
x-zm-signature: v0=e0e2fb4f1a7d5c3b9e8a6f2d4c1b0a9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a

{"event":"meeting.started","payload":{"object":{"id":"84…"}}}
```

## PagerDuty (v3 webhook subscriptions)

- **Signed content:** the raw body.
- **Headers:** `X-PagerDuty-Signature`, carrying one or more comma-separated `v1=…` entries — two
  while a subscription's secret is being rotated.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Stripped today:** no.
- **Confidence: Medium** — verify the header name and whether multiple entries are comma- or
  space-separated.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-PagerDuty-Signature: v1=cf7d3e2f7bd8e4b83ba9d6f5b1e2ab3b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f

{"event":{"id":"01D…","event_type":"incident.triggered"}}
```

## WooCommerce

- **Signed content:** the raw body. **Headers:** `X-WC-Webhook-Signature`, bare.
- **Algorithm / encoding:** HMAC-SHA256 · base64. **Stripped today:** no.
- **Confidence: Medium** — shape is confidently Shopify's; verify the header name.

## Xero

- **Signed content:** the raw body. **Headers:** `x-xero-signature`, bare.
- **Algorithm / encoding:** HMAC-SHA256 · base64. **Stripped today:** no.
- **Confidence: Medium** — verify the header name, and whether the configured webhook key is used as
  ASCII bytes or decoded first. A verifier at the destination needs to know which.

## Linear

- **Signed content:** the raw body. **Headers:** `Linear-Signature`, bare, hexadecimal.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal. **Stripped today:** no.
- **Confidence: Medium** — verify the header name.
- **Note:** Linear also puts a `webhookTimestamp` field **inside** the JSON body for replay
  protection. Because it is inside the signed bytes, it survives the hop with the body and a
  destination can enforce the replay window from it — unusually, without needing any header at all.

## Twitch EventSub

- **Signed content:** `<id><timestamp><body>` — three values, adjacent, with no separators.
- **Headers:** `Twitch-Eventsub-Message-Signature` (signature, prefix `sha256=`),
  `Twitch-Eventsub-Message-Id`, `Twitch-Eventsub-Message-Timestamp` — three separate headers, all of
  which must reach the destination for it to verify.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal. **Stripped today:** no.
- **Confidence: High** on the construction; **Medium** on exact header casing.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
Twitch-Eventsub-Message-Id: befa7b53-d79d-478f-86b9-120f112b044e
Twitch-Eventsub-Message-Timestamp: 2026-08-27T12:00:00.464253059Z
Twitch-Eventsub-Message-Type: notification
Twitch-Eventsub-Message-Signature: sha256=e76c6bd1a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c

{"subscription":{"type":"channel.follow"},"event":{"user_name":"cli"}}
```

**Signed content, substituted:**

```
befa7b53-d79d-478f-86b9-120f112b0442026-08-27T12:00:00.464253059Z{"subscription":{"type":"channel.follow"},"event":{"user_name":"cli"}}
```

Twitch's timestamp is an **RFC 3339 string**, not Unix epoch seconds. That matters to a verifier at
the destination only in that it must use the header's bytes verbatim when reconstructing the signed
string — sub-second digits and all — and parse them separately if it wants to enforce a window.

## Paddle (Billing)

- **Signed content:** `<timestamp>:<body>` — Stripe's construction with a colon.
- **Headers:** `Paddle-Signature`, carrying `ts` and `h1` as named parts, pairs separated by **`;`**.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal. **Stripped today:** no.
- **Confidence: Medium** — verify the separator and part names.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
Paddle-Signature: ts=1756382400;h1=eb4d0dc8853be92b7f063b9f3ba5233eb920a09459b6e6b2c26705b4364db151

{"event_type":"transaction.completed","data":{"id":"txn_01h…"}}
```

## Standard Webhooks / Svix

- **Signed content:** `<id>.<timestamp>.<body>`.
- **Headers:** `webhook-id`, `webhook-timestamp`, `webhook-signature` — the last a **space-delimited
  list** whose entries are `v1,<base64>`.
- **Algorithm / encoding:** HMAC-SHA256 · base64. The key is the stored secret with a leading
  `whsec_` stripped and then base64-decoded.
- **Stripped today:** no. **`webhook-signature` is not in the constant** — only `x-webhook-signature`
  is, which is a different name.
- **Confidence: High** (this is the specification ADR-022 implements).

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
webhook-id: msg_2b1c3d4e5f6g7h8i
webhook-timestamp: 1756382400
webhook-signature: v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=

{"type":"invoice.paid","data":{"id":"in_01h…"}}
```

**This is the collision case, and it is the sharpest finding in this document.** These three header
names are exactly the three this service emits when it signs an outbound dispatch. Because a
forwarded header colliding with an added one is dropped, a Svix-sourced proxy that also signs
destroys the sender's three headers silently — no log line, no attempt record, no surface. **A large
number of products ship Svix-backed webhooks**, many of them under their own prefixed names
(`x-vendor-id`, `x-vendor-timestamp`, `x-vendor-signature`), and Svix itself ships `svix-id`,
`svix-timestamp` and `svix-signature`, treating the unprefixed names as the generic alias. That is
the evidence behind ADR-025 Decision 2.

## Discord (interactions endpoint)

- **Signed content:** `<timestamp><body>`.
- **Headers:** `X-Signature-Ed25519` (hex, 128 characters), `X-Signature-Timestamp`.
- **Algorithm:** **Ed25519**, verified with the application's **public key** — not an HMAC.
- **Stripped today:** no. `x-signature-ed25519` is not `x-signature`.
- **Confidence: High.**

**Notable for pass-through**: because the verifying material is a **public** key, a destination needs
no secret from anybody to verify a Discord signature. It is the cleanest case in the sample of a
signature whose value at a destination is unambiguous.

## SendGrid (Event Webhook, signed events)

- **Signed content:** `<timestamp><body>`.
- **Headers:** `X-Twilio-Email-Event-Webhook-Signature` (base64 DER),
  `X-Twilio-Email-Event-Webhook-Timestamp`.
- **Algorithm:** **ECDSA** over NIST P-256, verified with a **public key** the member copies from the
  SendGrid console. The signature is a DER structure inside base64, not a raw digest.
- **Stripped today:** no.
- **Confidence: High** on the algorithm and shape; **Medium** on the exact header names.

Same property as Discord: public verifying material, so a destination can check it without holding a
shared secret.

---

# Part 2 — providers a destination cannot verify, and why

Given equal weight, because the value of this document is in showing exactly where the line falls.
Two of these fail because the signature never depended on the hop at all, and three because the
signed content includes something the destination is not and does not have.

## Twilio — signs the request URL

- **Signed content:** the **full request URL** followed by every POST parameter, sorted by name,
  concatenated as `name` + `value` with no separators. HMAC-SHA1, base64, key is the account auth
  token. **Header:** `X-Twilio-Signature` (not stripped today).
- **Why a destination cannot verify it:** condition 2 fails. The URL in the signed string is **this
  proxy's ingest URL**, not the destination's, so the destination is reconstructing a string for a
  request it did not receive. Forwarding the header changes nothing, and no header policy can make
  it verifiable through a proxy. It also cannot be repaired by disclosure: the ingest URL contains
  the ingest token, which is a bearer credential.
- Twilio switches to signing the URL plus a `bodySHA256` query parameter when the body is JSON,
  which is a second construction under one provider name and fails for the same reason.
- **Confidence: High** on the form-encoded construction; **Medium** on the JSON variant.

## Square — signs the notification URL plus the body

- **Signed content:** the notification URL followed by the raw body. HMAC-SHA256, base64.
  **Header:** `x-square-hmacsha256-signature`, bare (not stripped today).
- **Why a destination cannot verify it:** the same failure as Twilio, with a sharper consequence.
  The notification URL is **this proxy's ingest URL**, which contains the ingest token. A destination
  could only verify by being told that URL, which means being handed a credential that is the whole
  of ingest authentication under ADR-006. **This is the concrete case behind ADR-025 Decision 3's
  standing constraint** that the ingest path must never appear in a host, URL or referrer header.
- **Confidence: Medium** — the URL-plus-body construction I am confident of; verify the exact header
  name and current algorithm.

## PayPal — per-account identifier plus a certificate fetch

- **Signed content:** `<transmission id>|<transmission time>|<webhook id>|<CRC-32 of the raw body>`,
  RSA-SHA256, verified against a certificate **fetched at verification time** from a URL the sender
  supplies. **Headers:** `PAYPAL-TRANSMISSION-ID`, `-TIME`, `-SIG`, `PAYPAL-CERT-URL`,
  `PAYPAL-AUTH-ALGO` (none stripped today).
- **Why a destination cannot verify it:** the signed string contains the **`webhookId` of the
  original PayPal subscription**, which is scoped to the account that registered this proxy's ingest
  URL. A destination is not that account. It also requires an outbound certificate fetch to a
  sender-supplied URL, which is a network dependency in whatever code performs verification.
- **Confidence: High** on the shape and the CRC-32 element; **Medium** on the exact field order.

## AWS SNS — signature travels in the body, and needs a certificate

- **Signed content:** a canonical string built by concatenating **named JSON fields of the parsed
  body** in a fixed order; RSA, verified against a certificate fetched from `SigningCertURL`.
- **Where the signature lives:** **in the body**, as a `Signature` field — not in any header.
- **Consequence for header policy: none.** The signature survives the hop for free, because the body
  is forwarded in full. Whether a destination verifies it depends entirely on whether it fetches the
  certificate and reconstructs the canonical string; header policy has no bearing either way. SNS
  additionally requires answering a `SubscriptionConfirmation` handshake, which is a protocol
  obligation and is not in scope for any part of this product.
- **Confidence: High.**

## Mailgun — signs nothing about the payload

- **Signed content:** `<timestamp>` concatenated with `<token>`. HMAC-SHA256, hexadecimal. **The
  request body is not signed at all.**
- **Where the values live:** **in the body** — modern Mailgun events POST
  `{"signature":{"timestamp":"…","token":"…","signature":"…"},"event-data":{…}}`; the legacy form
  posts the same three as form fields.
- **Consequence for header policy: none**, and the signature is worth less than it looks. It proves
  the request came from Mailgun and proves nothing about the payload, so a destination that verifies
  it learns only that Mailgun sent *something*. It survives the hop for free with the body.
- **Confidence: High** on the construction; **Medium** on the exact JSON field names.

## Adyen — signs a projection of parsed fields

- **Signed content:** a colon-joined projection of specific notification fields (merchant account,
  PSP reference, amount, currency, event code, success flag) — not the body — with a key that is
  decoded from its stored form rather than used as ASCII. HMAC-SHA256, base64, carried **in the
  body**.
- **Consequence for header policy: none.** Survives with the body; verifiable by a destination that
  holds the key and implements the projection.
- **Confidence: Medium** — the field set and separator escaping should be verified before this is
  quoted anywhere.

## Mailchimp — no signature at all

Mailchimp's webhooks carry no signature; the shared secret lives in the callback URL. Named so it is
not counted as a miss: for this product the ingest URL's own token already plays that role
(ADR-006), and there is nothing to forward or strip.

---

# Summary

| Provider | Signature carried in | Signed content | In the strip list today | A destination can verify |
|---|---|---|---|---|
| GitHub | `X-Hub-Signature-256` | body | **yes** | **yes**, once forwarded |
| Stripe | `Stripe-Signature` (`v1=`) | `<timestamp>.<body>` | **yes** | **yes**, once forwarded |
| Intercom | `X-Hub-Signature` | body | **yes** | **yes**, once forwarded |
| Slack | `X-Slack-Signature` + timestamp header | `v0:<timestamp>:<body>` | no | yes |
| Shopify | `X-Shopify-Hmac-Sha256` | body | no | yes |
| Zoom | `x-zm-signature` + timestamp header | `v0:<timestamp>:<body>` | no | yes |
| PagerDuty | `X-PagerDuty-Signature` (`v1=`) | body | no | yes |
| WooCommerce | `X-WC-Webhook-Signature` | body | no | yes |
| Xero | `x-xero-signature` | body | no | yes |
| Linear | `Linear-Signature` | body | no | yes |
| Twitch EventSub | three `Twitch-Eventsub-*` headers | `<id><timestamp><body>` | no | yes |
| Paddle Billing | `Paddle-Signature` (`h1=`) | `<timestamp>:<body>` | no | yes |
| Standard Webhooks / Svix | `webhook-signature` + two headers | `<id>.<timestamp>.<body>` | no — **but displaced by our own signing headers** | yes, once the collision is removed |
| Discord | `X-Signature-Ed25519` + timestamp header | `<timestamp><body>` | no | yes — public key |
| SendGrid | `X-Twilio-Email-Event-Webhook-Signature` | `<timestamp><body>` | no | yes — public key |
| AWS SNS | **body** | projection of parsed body fields | not applicable | independent of header policy |
| Mailgun | **body** | `<timestamp><token>` — not the payload | not applicable | independent of header policy |
| Adyen | **body** | projection of parsed fields | not applicable | independent of header policy |
| Twilio | `X-Twilio-Signature` | **request URL** + sorted parameters | no | **no** — URL-bound |
| Square | `x-square-hmacsha256-signature` | **ingest URL** + body | no | **no** — URL-bound, and the URL is credential-bearing |
| PayPal | `PAYPAL-TRANSMISSION-SIG` | includes a per-account `webhookId` | no | **no** — recipient-scoped |
| Mailchimp | — | — | not applicable | nothing to verify |

**Read as counts, with the caveats that stop them being over-read.** Of twenty-one providers with a
signature: **fifteen become or already are verifiable at a destination** once the header reaches it;
**three travel in the body and are unaffected by header policy** in either direction; **three cannot
be verified through a proxy under any header policy**, two because they sign a URL and one because it
signs a recipient-scoped identifier.

1. **This is a convenience sample, not a market survey.** It is not weighted by how often a member
   actually brings a given provider, and no such weighting is available here. Any statement of the
   form "X% of providers" derived from this table would be a claim the evidence does not support.
2. **Weighted by traffic rather than by name count, the verifiable share is almost certainly
   higher.** The HMAC-over-body family is the dominant modern pattern and Standard Webhooks exists to
   make it the default; the exceptions are older or unusual designs.
3. **The three that cannot be verified are conspicuous names.** Twilio, PayPal and Square are the
   kind of integration a member checks first. Their exclusion is by construction, not by choice, and
   nothing in the product's header policy can change it.

---

# Does the body survive byte-identical?

Condition 4 of the test, checked against the code rather than assumed. Every signature above except
Twilio's, Square's and PayPal's is computed over the body, so this is what determines whether a
forwarded signature is checkable at all.

**The ingest seam reads the body once and never re-encodes it.** `IngestController` reads
`$rawBody = $request->getContent()` exactly once and passes the same string to the verifier and to
`WebhookEventCapture`, which writes it to `webhook_events.body` and computes `byte_size` from it
before the `encrypted` cast. ADR-022 § *Impact* binds that: "**The raw body must be read exactly
once** in `IngestController` and passed to both the verifier and `WebhookEventCapture`. Re-reading or
re-encoding it between the two would make the bytes verified and the bytes stored different things."

**Nothing in the request path mutates the body.** Verified rather than assumed:

- The ingest route is registered **outside the web group** (`bootstrap/app.php`, `routes/ingest.php`),
  so `TrimStrings` and `ConvertEmptyStringsToNull` never run on it. Both mutate the parsed parameter
  bags in any case, never `Request::$content`.
- Route middleware is `EnsureIngestIsSecure`, `EnforceIngestBodyLimit` and `throttle:ingest`. Only
  the second touches the body, and only when `Content-Length` is absent — and Symfony caches the
  content on first read, so there is no double-consumption hazard.
- Global middleware is `trustProxies` and `encryptCookies` only. Neither touches the body.
- **No Laravel Octane** in `docs/stack/stack.md`, so there is no long-lived, reused request object.

**Four places it gets delicate. Two are live properties of the path today.**

1. **`multipart/form-data` — `php://input` is empty, so the captured body is an empty string.** PHP
   does not make the raw body readable for multipart requests; `getContent()` returns `''`. This is
   already true of raw capture and is not introduced by any header decision. The consequence for
   pass-through is that a forwarded signature over a multipart body can never verify at a
   destination, because the bytes were never available to us either. It fails closed, which is the
   right direction, but it is silent.
2. **`application/x-www-form-urlencoded`.** PHP does make `php://input` readable for urlencoded
   bodies, so Slack slash commands and Twilio-shaped senders should capture correctly. **This is less
   certain than the multipart case and should be verified at runtime**, because it depends on
   `enable_post_data_reading` and the SAPI, and a wrong answer is silent.
3. **Content encoding in front of the application.** If a sender posts `Content-Encoding: gzip` and a
   load balancer decompresses before PHP sees it, the bytes signed and the bytes read differ and
   nothing in the application can detect it. Rare among webhook senders, and a deployment property
   rather than a design problem.
4. **A configured transform changes the body by design.** Every payload-mutating pipeline step is
   enhanced-mode-only, so a simple-mode proxy dispatches the captured bytes; an enhanced proxy with a
   transform configured dispatches different bytes, and every body-derived signature fails at the
   destination. That is correct behaviour — the payload genuinely is not the one the provider
   signed — and is the boundary ADR-025 states rather than something to repair.

**Conclusion:** the body is byte-identical wherever PHP makes the raw bytes available and no
transform is configured, and the one place it is not — multipart — fails closed.

# A property of this deployment that constrains any URL-bearing header

`bootstrap/app.php` calls `trustProxies(at: '*', headers: … | HEADER_X_FORWARDED_HOST | …)`,
deliberately and for a documented reason (review-01 finding #2 — there is no enumerable load-balancer
range yet). With host forwarding trusted from any source, `$request->getHost()` and therefore
`$request->fullUrl()` are **caller-controllable**.

Two consequences worth carrying:

- **A value resolved from the live request's URL is attacker-influenced**, so any construction that
  needs a URL must take it from stored proxy configuration, never from the request.
- **The ingest URL is credential-bearing** under ADR-006, so it must not be emitted in any outbound
  header under any circumstance. Square is the concrete case that makes this a real constraint rather
  than a theoretical one.

# What this document does not do

- **It does not decide anything.** It records constructions and the properties that follow from them.
  ADR-025 is where the header policy is decided; this is the evidence it cites.
- **It does not produce shipped configuration.** Every provider block is marked with a confidence
  level, and every one — including the High-confidence ones — must be checked against the provider's
  live documentation before it becomes data in the product. **A confidently wrong value is worse than
  no value**, because it fails for an unexplainable reason and the product's own suggestion was the
  cause.
- **It does not claim coverage on behalf of any product surface.** The counts above are a convenience
  sample and carry their caveats with them.
- **It does not read requirements into or out of `docs/product/prd-16-configurable-inbound-verification.md`.**
  That document is Draft and is the Product Manager's.
