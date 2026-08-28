# PRD-16 feasibility study: does the signed-string template model actually work?

- **Status:** **Study — informational. Not a decision, not a technical plan, and not an ADR.**
  It records evidence, not rulings. Nothing in it approves PRD-16, changes any acceptance criterion,
  or commits the product to a design.
- **Author:** Principal Engineer
- **Date:** 2026-08-28
- **Requested by:** the Project Owner, as a precondition for approving PRD-16. Their words:

  > "I would like the principal engineer to create some pseudo examples for some popular platforms
  > webhooks as a POC that our plan is going to work. It should be relatively pain free and easy for
  > someone to integrate. So lets let it show us what that looks like. What the string looks like,
  > and the source headers."

- **Subject:** `docs/product/prd-16-configurable-inbound-verification.md` — **Draft, awaiting Project
  Owner approval.** This study reads it and does not edit it.
- **Reads:** `docs/architecture/adr-022-inbound-verification-at-the-ingest-boundary.md` (Accepted —
  the seam a template scheme would land on) · `docs/architecture/adr-010-raw-payload-capture.md` ·
  `docs/architecture/adr-006-ingest-url-generation-security.md` · `docs/stack/stack.md` ·
  `app/Http/Controllers/IngestController.php`, `app/Http/Middleware/EnforceIngestBodyLimit.php`,
  `app/Services/WebhookEventCapture.php`, `routes/ingest.php`, `bootstrap/app.php`.
- **Writes nothing else.** No ADR is written, because no decision is being taken. If PRD-16 is
  approved, the decisions this study surfaces are taken in `plan-16` and in whatever ADR that plan
  warrants, against an approved requirement rather than a Draft one.

## What this study is testing, and what would count as failure

PRD-16 asserts that a bounded vocabulary generates the unbounded set of provider constructions
(§ *The reversal*). That assertion is testable and this study tests it, by writing out real
providers in the vocabulary and seeing which ones come out whole.

The model under test, restated so the tests below have something exact to fail against:

| Axis | PRD-16 | Values |
|---|---|---|
| Signed-string template | AC10, AC12 | `{body}`, `{timestamp}`, `{id}`, plus literal characters |
| Algorithm | AC15 | HMAC-SHA256, HMAC-SHA1 (legacy) |
| Encoding | AC16 | hexadecimal, base64 |
| Signature header | AC17 | member-supplied name |
| Signature extraction | AC17 | bare · after a fixed prefix · a named part of a delimited list |
| Timestamp source | AC18 | nowhere · a named header · a named part of the signature header |
| Id source | AC19 | the same three options |
| Tolerance | AC20 | positive seconds, default 300 |
| Safety rules | AC22, AC23, AC25 | `{body}` mandatory · secret is the key, never signed · no scheme goes live unproven |

The model **fails** if a provider that ought to be supportable cannot be written down in it, or can
be written down only by adding an axis PRD-16 has not named. Three such axes were found. They are
small, they are named in § *Missing axes*, and none of them is fatal — but they are not in the PRD,
and adding an axis is the Product Manager's or the Owner's act, not this study's.

## Method, and how confident each example is

Each provider below is written from working knowledge of its published verification scheme. That is
good enough to establish whether a construction **shape** fits the vocabulary, which is the question
the Owner asked. It is not good enough to be copied into a shipped preset.

- **Confidence: High** — the construction shape and the header names are ones I am confident of.
  Exact header casing and the current algorithm still get checked against live documentation before
  a preset ships.
- **Confidence: Medium** — the shape is right in outline; at least one element (a separator, an
  encoding, a header name) must be verified against the provider's live documentation before use.

**Every signature value shown below is illustrative and is not a computed digest.** They are
plausible-looking strings of the right length and alphabet, present so a reader can see the shape of
the header. No example should be used as a test vector.

**Every body shown is short so the substituted string stays readable.** Real bodies are kilobytes;
the mechanism is identical.

---

# Part 1 — providers the model expresses

## GitHub

- **Signed string:** `{body}` — the raw request body, nothing else.
- **Headers:** `X-Hub-Signature-256` carries the signature. **No timestamp header is signed**;
  `X-GitHub-Delivery` carries a delivery UUID but GitHub does not sign it, so it is not an `{id}`
  source here.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal (lowercase).
- **Extraction:** after a fixed prefix — `sha256=`.
- **Timestamp source:** nowhere. **Id source:** nowhere. **Tolerance:** not applicable (AC20 requires
  one only when a timestamp source is configured).
- **Confidence: High.**

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-GitHub-Event: push
X-GitHub-Delivery: 72d3162e-cc78-11e3-81ab-4c9367dc0958
X-Hub-Signature-256: sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17

{"zen":"Non-blocking is better than blocking.","hook_id":109948940}
```

**The string that gets signed**, with `{body}` substituted:

```
{"zen":"Non-blocking is better than blocking.","hook_id":109948940}
```

**Fields the member fills in:** **1** with the preset (the secret). **7** from empty: template,
algorithm, encoding, header name, extraction shape, prefix value, secret.

**Note.** GitHub also sends the legacy `X-Hub-Signature: sha1=…` alongside. A member could configure
either; AC15's HMAC-SHA1 exists for senders that offer only the legacy form, and GitHub is not one
of them. The preset should point at SHA-256.

## Stripe

- **Signed string:** `{timestamp}.{body}`.
- **Headers:** `Stripe-Signature` carries **both** the signature and the timestamp, as named parts of
  one comma-separated list.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Extraction:** a named part of a delimited list — part name `v1`, pairs separated by `,`, key and
  value separated by `=`.
- **Timestamp source:** a named part of the signature header — part name `t`. **Id source:** nowhere
  (the event id is in the body, and Stripe does not sign it separately).
- **Tolerance:** 300 seconds is the value Stripe's own libraries default to, which is also AC20's
  default.
- **Confidence: High.**

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
Stripe-Signature: t=1756382400,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd

{"id":"evt_1PabcXYZ","type":"payment_intent.succeeded"}
```

**The string that gets signed**, with `{timestamp}` and `{body}` substituted:

```
1756382400.{"id":"evt_1PabcXYZ","type":"payment_intent.succeeded"}
```

**Fields the member fills in:** **1** with the preset. **10** from empty: template, algorithm,
encoding, header name, extraction shape, part name `v1`, timestamp source kind, timestamp part name
`t`, tolerance, secret.

**Note.** Stripe sends `v0=` entries as well for some accounts, and can send more than one `v1=`
entry during a signing-secret rotation. See § *Missing axes, A4*.

## Slack

- **Signed string:** `v0:{timestamp}:{body}` — `v0:` and the two colons are literal characters.
- **Headers:** `X-Slack-Signature` carries the signature; `X-Slack-Request-Timestamp` carries the
  timestamp as a separate header.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Extraction:** after a fixed prefix — `v0=`.
- **Timestamp source:** a named header — `X-Slack-Request-Timestamp`. **Id source:** nowhere.
- **Tolerance:** Slack documents five minutes; AC20's default is the same number.
- **Confidence: High.**

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-Slack-Request-Timestamp: 1756382400
X-Slack-Signature: v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503

{"type":"event_callback","event":{"type":"app_mention"}}
```

**The string that gets signed:**

```
v0:1756382400:{"type":"event_callback","event":{"type":"app_mention"}}
```

**Fields the member fills in:** **1** with the preset. **10** from empty.

**Note.** Slack's Events API sends JSON, as above. Slash commands and interactivity payloads arrive
as `application/x-www-form-urlencoded`, which is the same construction over different bytes but
touches the raw-body question in § *Is `{body}` really the raw bytes?*.

## Shopify

- **Signed string:** `{body}`.
- **Headers:** `X-Shopify-Hmac-Sha256` carries the signature, **bare** — no prefix, no list.
  `X-Shopify-Webhook-Id` and `X-Shopify-Topic` are present but unsigned.
- **Algorithm / encoding:** HMAC-SHA256 · **base64**. This is the cleanest illustration of why AC16
  needs both encodings: Shopify and GitHub sign identical strings and differ only in how the digest
  is spelled.
- **Extraction:** bare.
- **Timestamp source:** nowhere. **Id source:** nowhere.
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

**The string that gets signed:**

```
{"id":820982911946154508,"financial_status":"paid"}
```

**Fields the member fills in:** **1** with the preset. **6** from empty (no prefix or part name to
supply, no timestamp, no id).

## Intercom

- **Signed string:** `{body}`.
- **Headers:** `X-Hub-Signature` — the GitHub-shaped header name, with a `sha1=` prefix.
- **Algorithm / encoding:** **HMAC-SHA1** · hexadecimal. The key is the app's client secret.
- **Extraction:** after a fixed prefix — `sha1=`.
- **Timestamp source:** nowhere. **Id source:** nowhere.
- **Confidence: Medium** — the shape is GitHub's; whether Intercom now also offers a SHA-256 variant
  must be verified against live documentation before a preset ships, and if it does, the preset
  should point at that instead.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-Hub-Signature: sha1=7d38cdd689735b008b3c702edd92eea23791c5f6

{"type":"notification_event","topic":"conversation.user.created"}
```

**The string that gets signed:**

```
{"type":"notification_event","topic":"conversation.user.created"}
```

**Fields the member fills in:** **7** from empty.

**Why Intercom is worth including.** It is the concrete answer to "why is HMAC-SHA1 in AC15's list at
all". Without it, a member on Intercom has no scheme. With it, AC15's requirement that SHA-1 be
labelled legacy and never defaulted is doing exactly the work it was written to do — the member
selects it deliberately for a sender that offers nothing better.

## Zoom

- **Signed string:** `v0:{timestamp}:{body}` — the same construction as Slack, different header names.
- **Headers:** `x-zm-signature` (signature), `x-zm-request-timestamp` (timestamp).
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Extraction:** after a fixed prefix — `v0=`.
- **Timestamp source:** a named header. **Id source:** nowhere.
- **Confidence: Medium** — construction is confidently Slack-shaped; header casing and the current
  prefix should be verified against live documentation.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
x-zm-request-timestamp: 1756382400
x-zm-signature: v0=e0e2fb4f1a7d5c3b9e8a6f2d4c1b0a9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a

{"event":"meeting.started","payload":{"object":{"id":"84…"}}}
```

**The string that gets signed:**

```
v0:1756382400:{"event":"meeting.started","payload":{"object":{"id":"84…"}}}
```

**Fields the member fills in:** **10** from empty. **This is the case the whole reversal is for.**
Zoom is not on the launch preset list, and under PRD-10 AC50 a member on Zoom waits for an Owner
decision. Under PRD-16 they start from the Slack preset, change two header names and the prefix, and
prove it — which is the "my provider is like Slack but the header is different" path UX Direction
point 3 rules as preferred, working exactly as intended.

## PagerDuty (v3 webhook subscriptions)

- **Signed string:** `{body}`.
- **Headers:** `X-PagerDuty-Signature`, carrying one or more comma-separated `v1=…` entries.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Extraction:** a named part of a delimited list — part name `v1`, `,` separator, `=` key/value.
- **Timestamp source:** nowhere. **Id source:** nowhere.
- **Confidence: Medium** — verify the header name and whether multiple entries are comma- or
  space-separated before shipping a preset.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
X-PagerDuty-Signature: v1=cf7d3e2f7bd8e4b83ba9d6f5b1e2ab3b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f

{"event":{"id":"01D…","event_type":"incident.triggered"}}
```

**The string that gets signed:**

```
{"event":{"id":"01D…","event_type":"incident.triggered"}}
```

**Fields the member fills in:** **7** from empty.

**Note.** PagerDuty sends **two** `v1=` entries while a subscription's secret is being rotated. See
§ *Missing axes, A4* — this is the second provider in this study to do so, which is what makes it a
real gap rather than a Stripe quirk.

## WooCommerce

- **Signed string:** `{body}`.
- **Headers:** `X-WC-Webhook-Signature`, bare.
- **Algorithm / encoding:** HMAC-SHA256 · base64.
- **Extraction:** bare. **Timestamp / id:** nowhere.
- **Confidence: Medium** — shape is confidently Shopify's; verify the header name.

**The string that gets signed** is the body verbatim. **Fields:** **6** from empty. Structurally
identical to Shopify, which is the point worth taking: once one base64-bare provider is expressible,
every base64-bare provider is, and there are many.

## Xero

- **Signed string:** `{body}`.
- **Headers:** `x-xero-signature`, bare.
- **Algorithm / encoding:** HMAC-SHA256 · base64.
- **Confidence: Medium** — verify the header name and whether the configured webhook key is used as
  ASCII bytes or decoded first. The second half matters: see § *Missing axes, A3*.

## Linear

- **Signed string:** `{body}`.
- **Headers:** `Linear-Signature`, bare, hexadecimal.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Confidence: Medium** — verify the header name.
- **Note.** Linear also puts a `webhookTimestamp` field inside the JSON body for replay protection.
  That field is **inside** the signed bytes, so the signature check works; but the replay window
  cannot be enforced by this model, because AC18's timestamp sources are all headers. This is a
  partial fit: signature yes, replay window no, and the member is not told which they are getting.
  Flagged under § *Missing axes, A5*.

## Twitch EventSub — fits, but needs one axis PRD-16 has not named

- **Signed string:** `{id}{timestamp}{body}` — **three tokens, adjacent, with no separators at all.**
  This is a good stress test of the template: zero literal characters, and it must be legible to a
  member who is reading it back.
- **Headers:** `Twitch-Eventsub-Message-Signature` (signature), `Twitch-Eventsub-Message-Id` (id),
  `Twitch-Eventsub-Message-Timestamp` (timestamp) — three separate headers, which exercises AC19's
  "a named header" id source for the first time in this study.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Extraction:** after a fixed prefix — `sha256=`.
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

**The string that gets signed:**

```
befa7b53-d79d-478f-86b9-120f112b0442026-08-27T12:00:00.464253059Z{"subscription":{"type":"channel.follow"},"event":{"user_name":"cli"}}
```

That run-together string is exactly why UX Direction point 2's "show the member the string, with the
tokens substituted from their own sample" is the right call — nobody would derive it from a
description.

**Where it breaks the model.** `Twitch-Eventsub-Message-Timestamp` is an **RFC 3339 string**, not a
Unix epoch integer. Stripe, Slack, Zoom and Standard Webhooks are all epoch seconds. AC18 names a
timestamp *source* and AC20 names a *tolerance in seconds*, and neither says what format the value is
in — but a tolerance cannot be computed without parsing the value, and the value cannot be parsed
without knowing the format. See § *Missing axes, A1*.

**Fields the member fills in:** **12** from empty — the highest count in this study, and the number
worth quoting when asking whether the custom path is "relatively pain free".

## Paddle (Billing) — fits, but needs a second axis PRD-16 has not named

- **Signed string:** `{timestamp}:{body}` — Stripe's construction with a colon instead of a dot.
- **Headers:** `Paddle-Signature`, carrying `ts` and `h1` as named parts.
- **Algorithm / encoding:** HMAC-SHA256 · hexadecimal.
- **Extraction:** a named part of a delimited list — part name `h1`, pairs separated by **`;`**, key
  and value separated by `=`.
- **Timestamp source:** a named part of the signature header — part name `ts`.
- **Confidence: Medium** — verify the separator and part names against live documentation.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
Paddle-Signature: ts=1756382400;h1=eb4d0dc8853be92b7f063b9f3ba5233eb920a09459b6e6b2c26705b4364db151

{"event_type":"transaction.completed","data":{"id":"txn_01h…"}}
```

**The string that gets signed:**

```
1756382400:{"event_type":"transaction.completed","data":{"id":"txn_01h…"}}
```

**Where it breaks the model.** AC17's third extraction shape is illustrated with `v1=` inside
`t=…,v1=…` — a comma-separated list of `key=value` pairs. Paddle uses a semicolon. Standard Webhooks
uses a space, and pairs its key to its value with a comma rather than an `=`. Three providers, three
different separator pairs. See § *Missing axes, A2*.

## Square — fits only by putting the ingest URL in the template

- **Signed string:** the **notification URL** followed by the raw body → in the vocabulary,
  `https://hooks.example.com/ingest/8f3c1b…{body}`, where everything before `{body}` is literal
  characters (AC11).
- **Headers:** `x-square-hmacsha256-signature`, bare.
- **Algorithm / encoding:** HMAC-SHA256 · base64. (The retired `X-Square-Signature` was HMAC-SHA1
  over the same string, which AC15's legacy algorithm would also cover.)
- **Confidence: Medium** — the URL-plus-body construction I am confident of; verify the exact header
  name and current algorithm before shipping anything.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
x-square-hmacsha256-signature: 1zTuAPZ4Bi0kAtLVBFCVJXjKKAMxOe4Vn9lQxJmZ3wU=

{"type":"payment.created","data":{"id":"tGpP…"}}
```

**The string that gets signed:**

```
https://hooks.example.com/ingest/8f3c1b…{"type":"payment.created","data":{"id":"tGpP…"}}
```

**This works, and it is the most interesting result in the study**, because it works by accident of
a property nobody designed for: the notification URL is a **per-proxy constant**, so it can be typed
as literal characters and never needs to be a token.

**But it drags a credential into displayed configuration.** The proxy's ingest URL contains the
ingest token, which is a bearer credential under ADR-006 — presenting it is the whole of ingest
authentication. AC43 requires the template to be readable by **anyone who can view the proxy**, and
argues that this is safe because "AC23 removes every legitimate reason to put [a secret] there".
Square is a legitimate reason to put a credential there. The credential is not the *verification
secret*, so AC23's letter is intact; but the practical effect is that the proxy's ingest token
appears on a read surface that was scoped assuming it would not.

This is not this study's to resolve. It is named for the Product Manager in
§ *Questions this study raises*, with two obvious shapes available — treat the ingest URL as a
fourth token so the product supplies it rather than the member typing it, or leave it as a literal
and change what AC43 displays — and no view stated on which is right.

**A footnote that matters if a `{url}` token is ever considered.** `bootstrap/app.php:36` calls
`trustProxies(at: '*', headers: … | HEADER_X_FORWARDED_HOST | …)`, deliberately and for a documented
reason (review-01 finding #2). With host forwarding trusted from any source, `$request->getHost()`
and therefore `$request->fullUrl()` are **caller-controllable**. A `{url}` token resolved from the
live request would let a sender choose part of the signed string, which is a signature-oracle shape.
A literal typed by the member has no such problem. If the token route is taken, the value must come
from the proxy's stored configuration, never from the request.

---

## Summary table — providers the model expresses

| Provider | Template | Signature header | Extraction | Timestamp source | Enc. | Fits? |
|---|---|---|---|---|---|---|
| GitHub | `{body}` | `X-Hub-Signature-256` | prefix `sha256=` | none | hex | **Yes** |
| Stripe | `{timestamp}.{body}` | `Stripe-Signature` | part `v1` (`,` `=`) | part `t` of same header | hex | **Yes** |
| Slack | `v0:{timestamp}:{body}` | `X-Slack-Signature` | prefix `v0=` | header `X-Slack-Request-Timestamp` | hex | **Yes** |
| Shopify | `{body}` | `X-Shopify-Hmac-Sha256` | bare | none | base64 | **Yes** |
| Intercom | `{body}` | `X-Hub-Signature` | prefix `sha1=` | none | hex | **Yes** (HMAC-SHA1) |
| Zoom | `v0:{timestamp}:{body}` | `x-zm-signature` | prefix `v0=` | header `x-zm-request-timestamp` | hex | **Yes** |
| PagerDuty | `{body}` | `X-PagerDuty-Signature` | part `v1` (`,` `=`) | none | hex | **Yes** |
| WooCommerce | `{body}` | `X-WC-Webhook-Signature` | bare | none | base64 | **Yes** |
| Xero | `{body}` | `x-xero-signature` | bare | none | base64 | **Yes** |
| Linear | `{body}` | `Linear-Signature` | bare | none | hex | **Yes**, signature only — replay window not expressible (A5) |
| Twitch EventSub | `{id}{timestamp}{body}` | `Twitch-Eventsub-Message-Signature` | prefix `sha256=` | header, **RFC 3339** | hex | **Needs A1** |
| Paddle Billing | `{timestamp}:{body}` | `Paddle-Signature` | part `h1` (**`;`** `=`) | part `ts` of same header | hex | **Needs A2** |
| Square | `<ingest URL>{body}` | `x-square-hmacsha256-signature` | bare | none | base64 | **Yes**, but see the credential note |
| Standard Webhooks / Svix | `{id}.{timestamp}.{body}` | `webhook-signature` | part `v1` (**` `** **`,`**) | header `webhook-timestamp` | base64 | **Needs A2 + A3** — and is out under AC45 |

## Standard Webhooks / Svix, treated separately

PRD-16 AC12 makes `{id}.{timestamp}.{body}` an adequacy test for the vocabulary, and AC45 keeps
`standard-webhooks` as its own scheme rather than a preset. Both are correct, and the reason they are
both correct is worth stating precisely, because it is the sharpest evidence in this study about
where the model's edge actually is.

```http
POST /ingest/8f3c1b… HTTP/1.1
Content-Type: application/json
webhook-id: msg_2b1c3d4e5f6g7h8i
webhook-timestamp: 1756382400
webhook-signature: v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=

{"type":"invoice.paid","data":{"id":"in_01h…"}}
```

**The string that gets signed:**

```
msg_2b1c3d4e5f6g7h8i.1756382400.{"type":"invoice.paid","data":{"id":"in_01h…"}}
```

- **The signed string is expressible.** AC12 claims exactly this and no more, and it is true.
- **The scheme is not.** Two elements fall outside the axes:
  1. `webhook-signature` is a **space-delimited list** whose entries are `v1,<base64>` — key and value
     joined by a **comma**, entries joined by a **space**. AC17's third shape as written pairs with
     `=` inside a `,` list. (A2.)
  2. The **key is not the stored secret**. It is `base64_decode(the secret with a leading "whsec_"
     stripped)`. ADR-022 Decision 4 records this, and the template model has no key-preprocessing
     axis. (A3.)

**So AC45 is not merely a scoping preference — it is load-bearing.** If `standard-webhooks` were
folded into the template model as AC44 and AC45 decline to do, the model would have had to grow both
axes immediately. It does not have to, because AC45 keeps the specification scheme separate. That is
a good decision that this study confirms from the mechanism side.

**The residual risk is real and should be named to the Owner.** A large number of products ship
Svix-backed webhooks under **their own header names** (`x-vendor-id`, `x-vendor-timestamp`,
`x-vendor-signature`) rather than the specification's. A member on such a provider cannot select
`standard-webhooks` — the header names are wrong — and cannot express it as a template scheme
either, because of A2 and A3. **This is the most likely real-world hole in the model**, and it is
invisible from a list of famous providers.

---

# Part 2 — providers the model cannot express

Given equal weight, because the PRD's promise under AC54 is "the large majority", and the value of
this study is in showing exactly where the line falls rather than asserting that it falls somewhere
comfortable.

## Discord (interactions endpoint)

- **What it does:** verifies an **Ed25519** signature over `timestamp + body`, using the
  application's **public key**.
- **Headers:** `X-Signature-Ed25519` (hex, 128 characters), `X-Signature-Timestamp`.
- **Axis violated:** **algorithm (AC15) only.** The template `{timestamp}{body}` fits, the timestamp
  source (a named header) fits, the encoding (hex) fits, the extraction (bare) fits. Every axis but
  one is satisfied.
- **What supporting it would require:** an `Ed25519` entry in AC15's algorithm list, plus something
  the model has no vocabulary for at all — **the credential stops being a secret**. AC23 says "the
  secret is always the HMAC key". A public key is not a secret, is not an HMAC key, and is not
  written into the same storage under the same rules (ADR-021, PRD-10 AC26's write-only rule makes no
  sense for a value that is public). Also `sodium_crypto_sign_verify_detached` rather than
  `hash_hmac`, which is in PHP core, so the primitive is not the obstacle.
- **Why it is the sharpest example:** it shows the model's failure is not "some providers are
  complicated" but "the model has exactly one trust shape". Discord is *simpler* than Stripe in every
  respect except the one that matters.
- **Confidence: High.**

## SendGrid (Event Webhook, signed events)

- **What it does:** **ECDSA** over the NIST P-256 curve, over `timestamp + body`, verified with a
  **public key** the member copies from the SendGrid console.
- **Headers:** `X-Twilio-Email-Event-Webhook-Signature` (base64 DER), `X-Twilio-Email-Event-Webhook-Timestamp`.
- **Axis violated:** **algorithm (AC15), and the same trust-shape problem as Discord.** Additionally
  the signature is a **DER-encoded structure inside base64**, not a raw digest — so AC16's "encoding"
  axis, which means "how the digest bytes are spelled", does not describe it either.
- **What supporting it would require:** an ECDSA entry, public-key credential storage, DER parsing,
  and `openssl_verify` instead of `hash_equals`. AC36's constant-time-comparison criterion does not
  apply to asymmetric verification at all, so the safety framing would need restating.
- **Confidence: High** on the algorithm and shape; **Medium** on the exact header names.

## PayPal

- **What it does:** **RSA-SHA256** against a certificate **fetched at verification time** from a URL
  the sender supplies.
- **Headers:** `PAYPAL-TRANSMISSION-ID`, `PAYPAL-TRANSMISSION-TIME`, `PAYPAL-TRANSMISSION-SIG`,
  `PAYPAL-CERT-URL`, `PAYPAL-AUTH-ALGO`.
- **Signed string:** `<transmission id>|<transmission time>|<webhook id>|<CRC-32 of the raw body>`.
- **Axes violated — four, and they are worth enumerating because it is the clearest case of a
  provider that no amount of widening reaches:**
  1. **Algorithm** — asymmetric, and AC48 already rules it out by name.
  2. **The signed string contains a function of the body, not the body.** `crc32(raw body)` is
     computation, and AC11 forbids computation outright — correctly, since a member-authored function
     is precisely the "code, not data" line the PRD is built on. Expressing PayPal needs a
     `{crc32(body)}` token, which is a function wearing a token's clothes.
  3. **The signed string contains a `webhookId`** — a per-account identifier that is neither of the
     three tokens nor a literal the member reliably knows.
  4. **Verification performs an outbound HTTPS fetch on the ingest hot path**, to a URL taken from
     the request, before the upstream response. ADR-022 Decision 1 puts verification synchronously
     before capture, so this would add a network round trip inside the sender's latency budget and a
     sender-influenced outbound request. Both are new failure and security surfaces.
- **Confidence: High** on the shape and the CRC-32 element; **Medium** on the exact field order.

## AWS SNS

- **What it does:** **RSA** (SHA-1 for `SignatureVersion` 1, SHA-256 for 2) against a certificate
  fetched from `SigningCertURL`, over a canonical string built by concatenating **named JSON fields
  of the parsed body** in a fixed order.
- **Where the signature lives:** **in the body**, as a `Signature` field — not in any header.
- **Axes violated:** algorithm (asymmetric, AC48); certificate fetch; the signed content is a
  **re-serialisation of parsed body fields**, so `{body}` is not what is signed and AC13's "raw bytes
  exactly as received" is not the input; and the signature source is not a header at all, so AC17's
  entire extraction axis has nothing to bind to. SNS additionally requires answering a
  `SubscriptionConfirmation` handshake, which is a protocol obligation rather than a verification
  scheme and is not in scope for any part of this product today.
- **Confidence: High.**

## Twilio

- **What it does:** HMAC-SHA1, base64, key is the account auth token — **and the signed string is the
  full request URL followed by every POST parameter, sorted by name, concatenated as
  `name` + `value` with no separators.**
- **Header:** `X-Twilio-Signature`.
- **Axis violated:** **the signed content**, which PRD-16 AC49 already names. Note what does fit:
  algorithm (HMAC-SHA1 is in AC15), encoding (base64), extraction (bare), header naming. It fails on
  AC22 alone — `{body}` cannot appear, because the body is not signed; a canonical re-ordering of its
  parsed parameters is.
- **What supporting it would require:** a `{url}` token (with the `X-Forwarded-Host` caveat above), a
  canonical form-parameter serialiser, and a rule for what happens when the body is JSON — Twilio
  switches to signing the URL plus a `bodySHA256` query parameter in that case, which is a second
  construction under one provider name. That is one class per vendor, which is the exact thing the
  reversal's reasoning says the vocabulary avoids. **Twilio is correctly excluded and would remain
  correctly excluded even under a much wider model.**
- **Confidence: High** on the form-encoded construction; **Medium** on the JSON variant's details.

## Mailgun — the one on the Owner's "should fit" list that does not

Included deliberately, because it was named as a provider to work through and the honest answer is
that it fails.

- **What it does:** HMAC-SHA256, hexadecimal, key is the webhook signing key — over
  **`timestamp` concatenated with `token`**. The **request body is not signed at all**.
- **Where the values live:** **in the body**, not in headers. Modern Mailgun events POST JSON of the
  shape `{"signature":{"timestamp":"…","token":"…","signature":"…"},"event-data":{…}}`; the legacy
  form posts the same three as form fields.
- **Axes violated — three, and any one is fatal:**
  1. **AC22.** `{body}` is mandatory and Mailgun's signed string contains none of it. This is not a
     technicality: Mailgun's scheme proves the request came from Mailgun and proves nothing about the
     payload, so a template claiming to sign the body would be a false claim.
  2. **The signature source is the body**, not a header — AC17 has nothing to bind to.
  3. **The timestamp and the `token` are body fields**, so AC18 has nothing to bind to either, and
     `token` is a fourth value the vocabulary has no name for.
- **Why it matters more than the others:** Mailgun *looks* like an HMAC-SHA256-hex provider. A member
  who reads "we support providers that HMAC a string containing the request body" would reasonably
  expect it to work, attempt it, and be unable to make it pass. **AC22 stops them, correctly, with a
  message about a rule rather than about Mailgun.** Whether that is a good enough experience is a
  product question, not a technical one, and it is raised in § *Questions this study raises*.
- **Confidence: High** on the construction; **Medium** on the exact JSON field names.

## Adyen

- **What it does:** HMAC-SHA256, base64, over a **colon-joined projection of specific notification
  fields** (merchant account, PSP reference, amount, currency, event code, success flag) — not the
  body — with a key that is **decoded** from its stored form rather than used as ASCII.
- **Axes violated:** the signed content is a projection of parsed fields (AC22 cannot express it), and
  the key needs preprocessing (A3).
- **Confidence: Medium** — the field set and separator escaping should be verified before this is
  quoted anywhere.

## Mailchimp, and the shape it represents

Mailchimp's webhooks carry **no signature at all**; the shared secret lives in the callback URL. The
product already covers this case, and covers it correctly: the ingest URL's own token is the
credential (ADR-006), so a Mailchimp-shaped sender needs no verification scheme, and PRD-10's
`shared-secret` covers the variants that put a fixed value in a header. Named so it is not counted as
a miss.

---

# Coverage — the honest figure

**Of the 21 providers examined: 13 are expressible as written, 3 more become expressible with one of
the small named axis additions, and 8 are excluded.**

| Outcome | Count | Providers |
|---|---|---|
| Expressible as PRD-16 defines the model | **10** | GitHub, Stripe, Slack, Shopify, Intercom, Zoom, PagerDuty, WooCommerce, Xero, Linear (signature only) |
| Expressible, with a consequence to rule on | **1** | Square (ingest URL as a literal) |
| Expressible only with a named axis addition | **3** | Twitch (A1) · Paddle (A2) · Standard Webhooks / Svix (A2 + A3) |
| Not expressible at any reasonable widening | **7** | Discord · SendGrid · PayPal · AWS SNS · Twilio · Mailgun · Adyen |
| Needs no scheme at all | **1** | Mailchimp (URL-secret; already covered) |

**About 60% of the sample fits as specified, rising to about 75% with three small additions, and
about a third is out.**

**Now the caveats, because that number is easy to over-read.**

1. **This is a convenience sample, not a market survey.** It is the providers I know well plus the
   ones named in the request. It is not weighted by how often a member actually brings a given
   provider, and I have no data that would let me weight it. **A share-of-provider-space figure is
   not something I can honestly produce**, and PRD-16 AC54's phrase "the large majority" should be
   read as a product claim the Owner is making, not as a measurement this study supports.
2. **Weighted by traffic rather than by name count, the fitting share is almost certainly higher.**
   The HMAC-over-body family is the dominant modern pattern; new providers overwhelmingly adopt it,
   and Standard Webhooks exists precisely to make it the default. The excluded set is dominated by
   older or unusual designs.
3. **But the misses are conspicuous.** PayPal, Twilio, SendGrid, Discord and AWS SNS are five of the
   most recognisable names in the sample. A member evaluating the product will likely check one of
   them. **The product's exposure here is reputational rather than functional**, which is exactly why
   AC54's "must not claim otherwise in any surface or copy" is the right criterion and why it should
   be held to literally.
4. **The most likely real hole is not in this table.** It is the Svix-backed provider using its own
   header names (§ *Standard Webhooks, treated separately*), which no list of famous providers
   surfaces and which A2 and A3 together would close.

---

# Part 3 — engineering assessment

## 1. Does the model hold up, and does any provider force an axis the PRD has not named?

**The model holds.** The core claim — that four constructions PRD-10 § V2 cited as evidence that
vendors genuinely differ are four arrangements of three tokens — is correct, and it generalises
further than the PRD claims. Every fitting provider in Part 1 is one template, one algorithm, one
encoding, one header and at most two source bindings. Nothing needed a fifth token, and nothing
needed a construction the three tokens plus literals could not spell.

**Three axes are missing, and one gap is unstated rather than missing.** None is fatal; all are
small; none is this study's to add.

### A1 — Timestamp format

**Found on:** Twitch (RFC 3339). Everything else in the sample uses Unix epoch seconds.

AC18 names a timestamp *source*. AC20 names a tolerance *in seconds*. Neither says what the value at
that source looks like, and the tolerance is uncomputable without knowing.

**The precise statement, because it is subtler than "add a format dropdown".** The timestamp has two
distinct uses and only one of them needs a format:

- **Substitution into the template** must use the **header's bytes verbatim**. Twitch signs
  `2026-08-27T12:00:00.464253059Z`, sub-second digits and all. Any normalisation before substitution
  breaks the signature.
- **The tolerance check** needs a parsed instant.

So the format axis governs the tolerance comparison only, and must never touch the substituted value.
Getting that backwards is a silent, total failure — every request rejected, with a correct-looking
configuration.

**Options, none chosen here:** a format axis (`unix-seconds` | `unix-milliseconds` | `rfc3339`); or a
lenient parse that tries integer-then-RFC-3339, which removes a field from the form at the cost of a
rule the member cannot see. Deciding which is a design call for `plan-16`; deciding *whether the
product supports non-epoch timestamps at all* is the Product Manager's, since it changes what AC18
and AC20 promise.

### A2 — Delimiters on the "named part" extraction

**Found on:** Stripe (`,` and `=`), Paddle (`;` and `=`), Standard Webhooks (` ` and `,`).

AC17's third extraction shape is described with one example. Three providers in a sample of this size
use three different separator pairs, so the shape is under-parameterised: it is really
`<pair separator>` plus `<key/value separator>` plus `<part name>`, and PRD-16 names only the last.

**This is the cheapest of the three to close** — two more member-supplied literal characters, which
AC21 already permits in kind (it allows "header names, prefix and part names, the tolerance number,
and the template's literal characters" as free-form). It adds two fields to the form for the
providers that need them.

**And it is the one with the largest payoff**, because it is half of what stands between the model
and every Svix-backed provider shipping under its own header names.

### A3 — Key preprocessing

**Found on:** Standard Webhooks / Svix (strip `whsec_`, then base64-decode), Adyen (decoded key).

AC23 states "the secret is always the HMAC key". For these providers the secret is not the key; a
decoding of it is. There is no axis for that, and AC23's phrasing forecloses one by implication
rather than by decision.

**AC45 keeps this from biting at launch**, because Standard Webhooks stays its own scheme. It bites
the moment a member brings a Svix-backed provider under vendor header names.

**Note the safety property is not weakened by adding it.** A key-encoding axis (`raw` | `base64` |
`hex`) changes how the secret is turned into key bytes; it does not put the secret anywhere near the
signed string, so AC23's actual guarantee — that the secret is never signed — is untouched. The
phrasing would need widening; the rule would not.

### A4 — Multiple signatures in one header (unstated, not missing)

**Found on:** Stripe (multiple `v1=` during rotation), PagerDuty (two `v1=` during rotation),
Standard Webhooks (a list by design).

AC35 rules that exactly one signed string is computed and compared once, on strong grounds. It says
nothing about how many *candidate signatures* the header may carry. ADR-022 Decision 4 already
requires the `standard-webhooks` handler to loop the list and accept if any entry verifies.

Comparing one computed signature against several extracted candidates is **not** what AC35 forbids —
it is one construction, one computed string, several spellings offered by the sender, which is
cosmetic in AC31–AC34's sense rather than semantic in AC35's. But that reading is mine, and AC35 is
emphatic enough that a Reviewer could reasonably read it the other way.

**This is a requirement question for the Product Manager, not a design call**, and it matters
concretely: a member whose provider is mid-rotation is exactly the member most likely to be
debugging, and "we read only the first entry" would reject valid requests during precisely that
window.

### A5 — A signed timestamp inside the body

**Found on:** Linear (`webhookTimestamp` in the JSON body).

AC18's three timestamp sources are all headers. A provider that signs the body and puts its replay
timestamp inside it gets its signature verified and its replay window ignored. The member is not told
that they have half the protection they think they have.

**Small and arguably out of scope** — reading a value out of the body is a step toward the parsing
AC11 rules out. Named for completeness rather than pressed.

## 2. Where would a member most plausibly get it wrong, and does the paste-a-real-request rule catch it?

**The proving rule is the strongest thing in this PRD, and it catches almost everything.** Any error
that changes the computed bytes produces a mismatch against a signature the provider actually
generated: wrong template, wrong algorithm, wrong encoding, wrong header name, wrong extraction
shape, wrong prefix, wrong part name, wrong secret, wrong timestamp source. All of it fails, visibly,
before anything goes live. That is a genuinely excellent design property and the study found nothing
that weakens it.

**Its failure mode is a false negative, not a false positive** — a member can fail to prove a correct
configuration, but cannot prove an incorrect one. With one exception, below.

**Three concrete ways a member gets it wrong, ranked by how likely I think they are.**

### (a) The tolerance clock, and it is the most likely problem in the whole feature

A pasted sample is by definition a request that already happened. AC28 lists "the timestamp was
absent or **outside the tolerance**" as one of the stages a failed proof must distinguish, which
reads as the proof enforcing tolerance. If it does, then a member configuring Stripe, Slack, Zoom,
Paddle or Twitch must paste a request **less than five minutes old**, or the proof fails on a
configuration that is completely correct.

**And AC26 and AC29 compound it.** AC26 requires re-proving after every edit. AC29 forbids retaining
the sample after the check. So a member iterating on a template — which is the normal case, and the
case UX Direction point 3 designs for — needs a **fresh, in-tolerance request for every attempt**.
They cannot re-run the previous sample, because it is gone and would now be stale anyway.

For a proxy whose provider sends an event every few seconds this is invisible. For a proxy whose
provider sends an event when a customer pays, the member must trigger a real transaction per
iteration. **That is the opposite of the Owner's "relatively pain free".**

**This is not the study's to resolve** and the resolution is not obvious — skipping the tolerance
check during proof means the one axis the member most misconfigures is the one never proven, and
proving against a stale sample makes a "pass" mean less than AC25 promises. It is a requirement
question for the Product Manager and probably also a design problem for `design-16`.

### (b) A template that passes the proof and then fails forever — the one false positive

A member reading their provider's documentation sees `signed_payload = timestamp + "." + body` and,
looking at their own sample, types the sample's own timestamp as a literal:

```
1756382400.{body}
```

This template **contains `{body}`, so AC22 permits it. It contains no unrecognised characters, so
AC10 permits it. It uses no token without a source, so AC14 permits it. And it verifies the sample
perfectly**, because for that one request the literal happens to equal the timestamp. It is enabled.
Every subsequent request is rejected.

**This is the only construction found that defeats the proving rule**, and it is not an exotic one —
it is what a careful member does when they are unsure whether the timestamp is meant to be a token or
a value, and their sample confirms the wrong reading. AC22 is described as "the single rule that
makes the rest safe"; it does not make this safe, because the template does sign the body.

A cheap detection exists and the model permits it: a template whose literal characters contain a run
of digits equal to the value the configured timestamp or id source resolved to in the sample is
almost certainly a mis-typed token, and the product can say so. **Whether to warn or to refuse is not
this study's call**, and if it is a refusal it is a new rule and therefore the Product Manager's.

### (c) A body that was re-encoded before the member pasted it

The member copies the body out of a logging tool, their provider's dashboard, or a terminal that
pretty-printed the JSON. The bytes differ from what was signed by whitespace alone, and the proof
fails on a correct configuration.

This fails safe, and AC28's stage-naming helps — "the signature did not match" while every earlier
stage passed is a strong hint. But it is the second most likely source of a confusing failure, and it
is a design problem (what the paste surface accepts and how it warns) rather than a model problem.

### And one thing the proof rule structurally cannot catch

**The provider changing its scheme later.** AC7 rules that presets are copied rather than tracked and
states the cost honestly; AC51 confirms there is no notification surface for live rejections. So the
proof proves the configuration *was* right on the day. Nothing re-proves it. That is a stated,
accepted cost and this study raises no objection to it — only records that the proving rule's
guarantee is point-in-time, and any copy that implies otherwise would be overclaiming.

## 3. Is `{body}` the raw request bytes in every case, and where is that delicate?

**Checked against the code, not assumed.**

**The seam is already correct and already ruled.** `IngestController:53` reads
`$rawBody = $request->getContent()` exactly once and passes the same string to `WebhookEventCapture`
(`:66`), which writes it to `webhook_events.body` and computes `byte_size` from it before the
`encrypted` cast. ADR-022 Decision 1 places verification between those two points, and ADR-022
§ *Impact* already binds it: "**The raw body must be read exactly once** in `IngestController` and
passed to both the verifier and `WebhookEventCapture`. Re-reading or re-encoding it between the two
would make the bytes verified and the bytes stored different things." A template scheme inherits that
constraint unchanged and needs no new rule.

**Nothing in the request path mutates the body.** Verified rather than assumed:

- The ingest route is registered **outside the web group** (`bootstrap/app.php:20-23`,
  `routes/ingest.php`), so `TrimStrings` and `ConvertEmptyStringsToNull` never run on it. Those two
  mutate the parsed parameter bags in any case, never `Request::$content`.
- Route middleware is `EnsureIngestIsSecure`, `EnforceIngestBodyLimit`, `throttle:ingest`. Only the
  second touches the body, and only when `Content-Length` is absent
  (`EnforceIngestBodyLimit:23-24`) — and Symfony caches the content on first read, so there is no
  double-consumption hazard.
- Global middleware is `trustProxies` and `encryptCookies` only. Neither touches the body.
- **No Laravel Octane** in `docs/stack/stack.md`, so there is no long-lived, reused request object.

**Four places it gets delicate. Two are live properties of the path today.**

1. **`multipart/form-data` — `php://input` is empty, so `{body}` would be an empty string.** PHP does
   not make the raw body readable for multipart requests; `getContent()` returns `''`. This is
   already true of raw capture (`webhook_events.body` would store nothing for such a request), so it
   is a pre-existing property rather than something #16 introduces. What #16 changes is its
   character: an empty `{body}` still HMACs to a value, that value will not match, and the request is
   rejected — so it **fails closed**, which is the right direction. But it means multipart senders can
   be neither verified nor captured, and the member has no way to find out except that everything
   fails. Worth confirming at runtime against the deployed SAPI before any preset for a multipart
   provider is considered.
2. **`application/x-www-form-urlencoded`.** PHP does make `php://input` readable for urlencoded
   bodies, so `getContent()` should return the raw bytes and Slack slash commands, Twilio-shaped
   senders and Mailgun's legacy form should all capture correctly. **I am less certain of this than of
   the multipart case and it should be verified at runtime**, because it depends on
   `enable_post_data_reading` and the SAPI, and because a wrong answer here is silent.
3. **Content encoding in front of the app.** If a sender posts `Content-Encoding: gzip` and a load
   balancer or web server decompresses before PHP sees it, the bytes signed and the bytes read differ
   and nothing in the application can tell. Rare among webhook senders, and unfixable from inside the
   app — worth naming as a deployment property rather than a design problem.
4. **`INGEST_MAX_BODY_BYTES` defaults to 50 MiB, and verification runs on the request thread before
   the response.** ADR-022 § *Impact* already names this for `standard-webhooks`; a template scheme
   changes nothing except that the number of HMACs is still exactly one (AC35), so the cost does not
   grow. PRD-16 AC53 asserts no numeric target and this study asserts none.

**Conclusion:** `{body}` is the raw bytes wherever PHP makes the raw bytes available, and the one
place it is not — multipart — fails closed. The existing seam needs no change and the existing ADR-022
constraint covers it. This is the part of the model that is in the best shape.

## 4. Do the three safety rules survive contact with real providers?

### Rule 1 — `{body}` is mandatory (AC22)

**Survives, and excludes only providers that ought to be excluded.** Every provider it blocks —
Mailgun, Twilio, Adyen, AWS SNS — is one whose scheme genuinely does not authenticate the payload.
Letting them through would produce exactly the meaningless green badge the rule exists to prevent.
The rule is correct and this study found no provider that ought to work and cannot because of it.

**One correction to how it is described, offered as a factual observation rather than a requirement
change.** AC22 calls itself "the single rule that makes the rest safe". Case (b) above is a template
that satisfies AC22 and is still worthless. AC22 is necessary and it is not sufficient, and a reader
who takes the sentence literally will not go looking for case (b). Whether the PRD's wording should
change is the Product Manager's.

### Rule 2 — the secret is the key and is never signed (AC23)

**Survives against every provider in the study.** No HMAC provider examined puts the shared secret
into the signed string; the whole family relies on it being the key. There is no provider this rule
excludes.

**Two observations, neither of which breaks it.**

- **Square puts a different credential into the template.** The ingest URL contains the ingest token
  (ADR-006), and AC43 displays the template to anyone who can view the proxy. AC23's letter holds —
  the *verification secret* is still never in the template — but AC43's justification ("AC23 removes
  every legitimate reason to put one there") is weakened by a legitimate case. Raised as a question,
  not resolved.
- **A3's key-preprocessing axis would not weaken this rule.** Decoding the secret before using it as
  the key keeps it entirely on the key side. AC23's *wording* would need widening; its *guarantee*
  would not.

### Rule 3 — nothing goes live unproven (AC25, AC26)

**Survives, and is the feature's strongest idea. It is also the one that excludes providers it should
not — temporarily, on a clock.**

The exclusion is not by provider but by **timing**: every provider with a timestamp in its signed
string (Stripe, Slack, Zoom, Paddle, Twitch, and any Svix-backed sender) can only be proven from a
sample fresher than the tolerance, and AC26 plus AC29 make that requirement recur on every edit. See
§ 2(a). It is the single finding in this study most likely to make integration feel painful, and it
lands squarely on the axis the Owner asked about.

**Everything else about the rule holds.** AC42's carve-out for existing `shared-secret` and
`standard-webhooks` proxies is correct — applying the proof retroactively would take working proxies
offline. AC26's two halves (the edit does not take effect, and the previous definition keeps
verifying) are implementable and are a genuinely good property; how the proven state is represented
is Q-16-01(4)'s and belongs in `plan-16`.

---

# Questions this study raises

Each is stated as a question and none is answered here. **The requirement questions go to the Product
Manager; this study takes no position on any of them and reinterprets nothing.** Following PRD-16's
own posture on Q-16-01, the question documents are raised at handoff **after** the Project Owner
approves the PRD — this study names them so the Owner can see, before approving, what the PRD would
be approved *with*.

**To the Product Manager (requirements):**

1. **Does the proof enforce the tolerance?** If yes, AC24 in practice requires a sample newer than
   the tolerance, and AC26 plus AC29 make that recur on every edit — for every timestamped provider.
   If no, the tolerance is the one axis that never gets proven. AC28's stage list implies yes.
   **This is the highest-value question in the study.**
2. **Non-epoch timestamps (A1).** Does AC18 promise to support a provider whose timestamp is an RFC
   3339 string, or is the model epoch-only? This changes what AC18 and AC20 cover, and Twitch is a
   real provider that turns on it.
3. **Delimiters on the named-part extraction (A2).** AC17 names a part name but not the two
   separators. Are the separators member-supplied (making Paddle and Svix-shaped senders expressible),
   or is the shape fixed to Stripe's `,` and `=`?
4. **Key preprocessing (A3).** Does AC23's "the secret is always the HMAC key" mean literally the
   stored bytes, or the key material derived from them? Standard Webhooks and Adyen decode; AC45
   keeps the first out of scope, but Svix-backed senders under vendor header names are not covered by
   AC45 and are the most likely real-world gap.
5. **Multiple signatures in one header (A4).** Does AC35 permit comparing one computed signature
   against several candidates extracted from the header? Stripe and PagerDuty both send more than one
   during a rotation.
6. **The ingest URL as a template literal.** Square requires it. AC43 displays the template to every
   viewer of the proxy and AC23's rationale assumed no credential would be there. Should the URL be a
   product-supplied fourth token, should AC43 change what it shows, or is Square simply out?
7. **Is AC22's self-description "the single rule that makes the rest safe" one the PRD wants to
   keep**, given case (b) — a template that satisfies it and still passes a proof it should not? If
   the answer is a new rule rather than a warning, the rule is the Product Manager's to write.
8. **Mailgun's experience.** A member who tries Mailgun hits AC22 and is told about a rule. Is that
   the intended outcome, or does AC54's "the product must not claim otherwise" imply a surface that
   names it? **This study confirms Mailgun does not fit; it does not decide what the member is told.**

**To the Project Owner (product):** Q-16-02 already asks which providers ship as presets at launch.
This study offers, as evidence rather than as a recommendation: **Shopify** is a one-field preset with
the same shape as GitHub and adds a base64-bare exemplar the launch three lack; **Zoom** costs almost
nothing beside Slack; **Intercom** is the only sender in the sample that justifies AC15's HMAC-SHA1.
The choice remains the Owner's.

# What this study does not do

- **It does not approve, revise, or reinterpret PRD-16.** The PRD is Draft. Where this study found a
  gap, it is named as a question to the Product Manager, never filled.
- **It does not write an ADR.** No decision is being taken, and Q-16-01(1) — whether a data-driven
  scheme fits behind ADR-022's registry or needs a superseding ADR — is answered in `plan-16` against
  an approved requirement, not here. **This study's only observation on that question is that ADR-022
  § Alternatives already rejects "free-form verification configuration (member-chosen digest,
  encoding, signed-string template)" by name.** That rejection stands on AC23 and AC50, both of which
  PRD-16 supersedes or revises, so it will need addressing — but only if and after the Owner approves
  the reversal.
- **It does not produce shipped preset values.** Every provider block above is marked with a
  confidence level, and every one of them — including the High-confidence ones — must be checked
  against the provider's live documentation before it becomes data in the product. **A preset that is
  confidently wrong is worse than no preset**, because the member proves it against one sample, it
  passes for the wrong reason or fails for an unexplainable one, and the product's own suggestion was
  the cause.
- **It does not touch #10.** PRD-10, `plan-10`, `design-10`, `docs/status.md` and every Accepted ADR
  are unmodified. `plan-10` is mid-implementation and nothing here depends on its outcome; PRD-16
  AC42's "nothing may ship before #10 does" is unaffected either way.
