# ADR-024: Field obfuscation at the single egress boundary, and the revealed-payload envelope (partially supersedes ADR-017 Decision 6)

- **Status:** **Accepted — Project Owner, 2026-08-27.** This ADR was **Owner-approval flag 4** of
  `docs/plans/plan-10-sensitive-data-handling.md`. It changes the response shape of the one
  content-bearing endpoint this system has — a surface ADR-017 marks *"Security-sensitive
  (Owner-gated ✋): the first user-facing egress of stored payload content"* — and it **adopts an
  alternative ADR-017 rejected by name**. Both made this the Owner's rather than the plan gate's,
  and the Owner accepted it on 2026-08-27, which supersedes ADR-017's rejection of that
  alternative for this endpoint.
- **Author:** Principal Engineer
- **Date:** 2026-08-27
- **Feature:** prd-10-sensitive-data-handling (AC12–AC22, AC49; design-10 Screen 7 and corrections
  C3, C6, C8, C9)
- **Relationship to ADR-017:** **partially supersedes** — one named position of Decision 6, plus
  one § Alternatives bullet now adopted. ADR-017 otherwise stands, Accepted and operative: replay as
  a dispatch identity, the four routes and their gates, fetch-on-reveal, the cleaned/410 mapping,
  no-store, never logged, never a prop, and text rendering only (never `v-html`). See § *Positions
  superseded*.
- **Companions:** ADR-014 (the cleaned signal AC21 must stay distinguishable from) · ADR-010
  (the raw capture this endpoint reads) · ADR-009 (the permission this reuses and does not extend)
- **Design authority:** `design-10` § *Screen 7* and its approval record fix the **UX outcome** and
  explicitly leave the **transport** to technical design (design-10 § *Open Questions*,
  § *Carried forward to the Principal Engineer* items 1 and 2). This ADR is that decision. It
  changes no approved copy and no approved state.

## Question

PRD-10 requires that a permitted member who requests payload content **by any route** receives it
with sensitive values already obfuscated (AC18), that field **names** and the payload's structure
stay visible (AC15), that an obfuscated value discloses nothing about itself — not a character, not
its length, not whether two of them are equal, not whether it is empty (AC16), that the treatment is
distinguishable from a **cleaned** payload (AC21), that there is no per-field reveal for any role
(AC20), and that no field-level claim is made where the payload is not parseable JSON (AC22).

`design-10` fixes what the member sees: pretty-printed structure, and in place of each sensitive
value one inert, distinctly-styled `[Hidden]` token carrying one of **two** accessible descriptions
— one for a product-default match, which offers no removal remedy because AC12 forbids removing a
default, and one for this proxy's own AC13 addition, which does (correction C3). A sensitive value
that is an object or an array is replaced **whole**, never walked into (correction C6).

Three things follow that no upstream artifact decides:

1. **What does the endpoint return?** Pre-rendered obfuscation-safe markup, or a structured
   representation the client renders — and, either way, how does a per-value "which list matched"
   flag travel with it unambiguously?
2. **What is the default sensitive-field list, literally?** `design-10` correction C4 requires
   Screen 2 to render the names the product actually matches, and states that the content of the
   list "is fixed at technical design". AC12 sets the floor (password, token, credit card, and their
   common spellings and separators) and forbids a member removing any of them.
3. **What is "matching by name, case-insensitive, at any depth" (AC14)** in precise terms —
   exact or substring, and what counts as the same name?

## Positions superseded

One position of ADR-017 Decision 6, and one § Alternatives bullet.

| ADR-017 position | Verbatim | Superseded to |
|---|---|---|
| **P1 — Decision 6, response hardening** | "Response hardening (binding): `Content-Type: text/plain; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`; the client renders the body exclusively as text (Vue text interpolation — never `v-html`)." | **Narrowed to the non-JSON path.** A stored payload that parses as JSON is returned as `application/json` carrying the envelope in Decision 2 below. `nosniff`, `no-store, private`, never-logged, never-cached, never-a-prop and never-`v-html` are **unchanged and apply to both paths**. The `text/plain` half survives verbatim for every payload that does not parse as JSON. |
| **P2 — § Alternatives, the JSON envelope** | "**A JSON envelope (`{"body": …}`) for the payload endpoint** — `json_encode` fails on non-UTF-8 bytes (captured bodies are arbitrary), forcing base64 and a decode hop. Raw `text/plain` + `nosniff` is exact, binary-safe-enough for display, and simpler. Rejected." | **`[ADOPTED]` for the JSON path only — and the original reasoning was correct, which is why it still governs the other path.** The rejection rested on captured bodies being arbitrary bytes. On the JSON path that premise does not hold: a body that `json_decode` accepts is by definition valid UTF-8, so re-encoding it cannot fail and needs no base64 hop. On the non-JSON path the premise holds exactly as written, and that is why the raw `text/plain` response is kept there rather than wrapped. The bullet's reasoning is retained, not deleted, with this correction attached. |

ADR-017 keeps its file, its Accepted status and its full text, and gains an inline pointer at each.
Nothing else in ADR-017 is disturbed — in particular the endpoint, its route, its `ProxyPolicy::view`
gate, the absence of a distinct reveal permission (AC20 re-confirms it), the 410-on-cleaned mapping
and the fetch-on-reveal posture are all unchanged.

## Decision

### (1) Obfuscation is applied server-side, in the endpoint, before the response is written — and there is exactly one endpoint.

`ProxyEventPayloadController` is the only content-bearing response in the system (ADR-017 Decision 5,
re-verified against the current code: no resource, prop or page carries `body` or `headers`). AC18's
"by any route, not only through the interface" is therefore satisfied by obfuscating in that one
place — there is no second route to also cover, and adding one would be a change to this ADR.

Obfuscation is never applied on any write, dispatch, retry or replay path. AC17 and AC59 both depend
on that: the stored payload is unchanged, and a destination receives the real values in original
order and structure on every dispatch.

### (2) The JSON path returns a three-key envelope; the client walks it and renders. Obfuscated values never leave the server in any form.

```jsonc
{
  "format": "json",
  "document": { ... the parsed payload, each sensitive value replaced by null ... },
  "obfuscated": { "/customer/password": "default", "/payment/token": "addition" }
}
```

- **`document`** is the decoded payload re-encoded, with **every sensitive value replaced by JSON
  `null`** — whatever its original type, objects and arrays included (correction C6). The real value,
  its length, its type, its emptiness and — for an object or array — its keys and shape never cross
  the boundary at all. AC16 and AC18 are satisfied by absence, not by masking.
- **`obfuscated`** maps an **RFC 6901 JSON Pointer** to one of exactly two values, `"default"` or
  `"addition"` — the C3 data point the accessible description branches on. A JSON Pointer is used
  rather than a dotted path because it defines escaping for `/` and `~` inside key names, so a
  payload with a key like `a/b` or `~x` cannot produce an ambiguous or colliding entry.
- The client `JSON.parse`s the envelope, walks `document`, and at each node whose pointer is present
  in `obfuscated` renders the `[Hidden]` token with the matching description instead of the `null`.
  Because the client computes the same pointers from the same structure it received, there is no
  string matching, no sentinel value inside the document, and no placeholder that a crafted payload
  could forge.

`null` is a safe sentinel precisely because it is **never read as data**: the client renders a token
wherever the pointer index says to, and consults the pointer index first. A real `null` in the
payload at a non-sensitive field renders as `null`, correctly, because its pointer is absent from
the index.

Headers: `Content-Type: application/json`, plus ADR-017's unchanged `X-Content-Type-Options:
nosniff` and `Cache-Control: no-store, private`. The `payload.revealed` log line is unchanged —
identifiers only, never content. Nothing is cached and nothing enters an Inertia prop.

### (3) The non-JSON path is byte-for-byte what it is today.

If `json_decode` does not accept the stored body, the endpoint returns the raw bytes as
`text/plain; charset=utf-8` exactly as it does now, and the viewer renders design-06's raw block
with **no field-level treatment and no message implying one was attempted** (AC22). The client
branches on the response `Content-Type`, so the two paths are distinguishable without a second
route or a query parameter.

**A large but valid JSON payload is never routed down this path.** It is JSON, it has sensitive
fields, and returning it raw would be an AC18 breach. See § *Impact* for the consequence and
`plan-10` § *Risks* for the mitigation that is deliberately **not** taken at MVP.

### (4) Matching: normalise, then compare for exact equality, at any depth. No substrings, no values.

A field name matches when its **normalised** form equals a normalised entry in the effective list.
Normalisation is: lowercase, then remove every character that is not `a`–`z` or `0`–`9`.

- `Password`, `password`, `pass_word`, `pass-word` and `PASS WORD` all normalise to `password` — the
  "case and separators don't matter" rule Screen 2's help copy states.
- **Exact equality, never a substring.** `token` does not match `tokenizer_version`, and
  `access_token` is its own entry rather than something `token` covers. Substring matching would
  hide `token_count` and `password_reset_requested_at`, and AC12 makes a default unremovable, so a
  false positive is a value the member can never see again.
- **No stemming and no plurals.** `tokens` is not `token`.
- **Any depth**, walked through objects and arrays alike; an array index is a position, never a name,
  so it never matches.
- **The value is never inspected** (AC14): no card checksum, no entropy test, no key-shaped-string
  heuristic, anywhere.

**The effective list is the product default list ∪ this proxy's additions**, and when a name is in
both, **the default wins** for the purpose of the C3 flag. That is not arbitrary: removing the
addition would not unhide the value, so describing it as an addition would offer a remedy that does
not work — which is the exact defect correction C3 exists to prevent.

### (5) The product default list, fixed here — 23 names, three families, nothing beyond them.

Displayed literally on Screen 2 (correction C4) in the readable forms below; compared in normalised
form. `App\Support\SensitiveFields::DEFAULTS` is the single source, emitted to the create and edit
pages as a page prop.

| Family | Names |
|---|---|
| Password | `password`, `passwd`, `pwd`, `passphrase`, `current_password`, `new_password`, `old_password`, `password_confirmation` |
| Token | `token`, `access_token`, `refresh_token`, `id_token`, `auth_token`, `api_token`, `bearer_token` |
| Credit card | `credit_card`, `credit_card_number`, `card_number`, `cc_number`, `cvv`, `cvc`, `csc`, `card_security_code` |

**The list is deliberately confined to the three families AC12 names.** AC12 says "at minimum", so
adding `secret`, `api_key`, `private_key`, `client_secret` and similar would be permitted — and it is
still declined, because AC12 also forbids a member removing or editing a default. Every entry is a
value the member can never see again on any of that proxy's payloads, past or future, so the cost of
a wrong entry is unbounded and the member's remedy is nil. The cost of a *missing* entry is bounded
and entirely in the member's hands: AC13's per-proxy additions, on a screen (Screen 2) whose stated
purpose is letting them see that `api_key` is **not** covered and add it. `design-10` correction C4
names `cvv`, `pwd`, `secret` and `api_key` as the member's own worked examples; two of those four are
in the list above and two are deliberately not, which is the distinction Screen 2 exists to make
visible.

Two properties are pinned by test rather than by review: **no two entries collide after
normalisation**, and **every entry is already in normalised-equal form to its displayed spelling**.

### (6) The token is `[Hidden]`, fixed, inert, and identical for every value.

`design-10` correction C8 fixes the string, because AC21 makes it load-bearing: it must never read
as empty, missing, corrupt or — above all — cleaned. Its rendered width, character count and presence
are constant regardless of the real value's length, type or emptiness (AC16), and it carries no click
target, no focus stop and no role that announces as actionable (AC20). The accessible description is
one of the two strings `design-10` § *Accessibility* fixes, selected by the C3 flag; this ADR
authors none of that copy.

### (7) Pretty-printing is a consequence, not a requirement.

`design-10` correction C9 rules it as the requirements author: reformatting is what parsing-to-
obfuscate produces, not something AC15 asks for, and it is accepted in scope on that basis, confined
to the JSON path. The consequence is stated rather than discovered: **on the JSON path the revealed
view is no longer byte-faithful to what arrived.** It is a re-serialisation of the parsed document,
so insignificant whitespace is normalised, duplicate keys collapse to the last occurrence, and
integer-like object keys may be reordered by the JavaScript engine's own object semantics. None of
that changes what is stored, what is delivered, or what a signature is computed over (AC17, AC59);
the raw bytes remain exactly as captured, and the non-JSON path is untouched. This is the
display-only consequence of the PRD-06 AC25 narrowing the Owner accepted on 2026-08-27, named here —
as C9 asks — so nobody discovers it at review.

## Alternatives

- **Pre-rendered obfuscation-safe markup from the server.** Rejected. It puts copy, layout and
  accessibility wiring — all of them the Designer's, all of them fixed by `design-10` — into a PHP
  string builder, and it makes the endpoint's output un-reusable by anything but this one component.
  It also requires the server to emit HTML on a path whose entire hardening posture (ADR-017
  Decision 6: `nosniff`, text interpolation, never `v-html`) exists to guarantee the client never
  treats this response as markup.
- **A sentinel object inside the document** (`{"__obfuscated": "default"}` in place of the value).
  Rejected: a payload can legitimately contain a field named `__obfuscated`, so the marker is
  forgeable by the data it is describing, and distinguishing the two would need exactly the parallel
  index this ADR uses anyway.
- **A random per-response nonce string as the placeholder, checked for absence in the document.**
  Rejected: it works, but it makes correctness rest on a generate-and-check loop and on the client
  string-splitting a serialised document, where the pointer index needs neither.
- **Dotted paths (`customer.password`) instead of JSON Pointers.** Rejected: a key containing a dot
  makes two different paths collide, silently, and payload keys are arbitrary strings.
- **Return the values hashed, or truncated, so the client can tell equal values apart.** Rejected by
  AC16 outright — "not whether two obfuscated fields hold the same value" — and by § Out of Scope's
  rejection of partial disclosure.
- **Obfuscate client-side.** Rejected: AC18 requires obfuscation before content leaves the server;
  client-side filtering would ship every secret to the browser and rely on the renderer not to show
  it.
- **A second endpoint for the obfuscated shape, leaving the existing one raw.** Rejected: AC18 binds
  *any* route, so the raw one would have to be removed anyway, and two endpoints means two gates to
  keep in step.
- **Keep `text/plain` and wrap the envelope in it.** Rejected: the response would then be JSON that
  declares itself as text, defeating `nosniff`'s purpose and forcing the client to guess.
- **Substring matching on field names.** Rejected: see Decision 4. The failure is silent and
  unrecoverable for a default.
- **A team-level default list, or member-editable defaults.** Rejected by AC12 and AC13; § Out of
  Scope records that a team-level list, if ever wanted, is additive to the per-proxy one.

## Reasoning

- **The transport question is really "who owns the copy".** `design-10` fixes the token string, both
  accessible descriptions, the styling family and the inertness. A pre-rendered response would move
  all four into the backend, where the Designer cannot see them and the next change to any of them
  becomes a PHP edit. A structured response leaves them exactly where the design gate put them.
- **The parallel pointer index is what makes C3 and C6 cost nothing extra.** C3 is a value on an
  entry that has to exist anyway; C6 is the absence of recursion below a listed pointer. Both fall
  out of the shape rather than needing a rule.
- **The `null` sentinel is safe only because the index is consulted first**, and that is worth
  stating because the inverse — "render a token wherever you see `null`" — is a plausible
  misimplementation that would hide every legitimately-null field and satisfy no criterion.
- **Adopting an alternative a prior ADR rejected requires showing the premise changed, not that the
  judgement was wrong.** ADR-017's reasoning about arbitrary bytes was and is correct; it simply
  does not describe a body that has already parsed as JSON. Keeping the original bullet with the
  correction attached is what lets a later reader see which half still governs.
- **Confining the default list to AC12's three families is the conservative reading of an
  asymmetric cost.** A missing entry is a two-second fix by the member; a wrong entry is permanent
  and invisible to them. Screen 2 exists to make the boundary legible, so the boundary should be
  the one AC12 states.

## Impact

- **Security-sensitive (Owner-gated ✋):** the response shape of the system's only payload-content
  egress changes for JSON payloads, and an ADR-017 alternative is adopted. The gate is on the shape
  and the adoption; the endpoint, its route and its permission are unchanged.
- **Data-model:** one column, `proxies.sensitive_fields` (`longText NULL`, cast `array`) for the
  AC13 additions — part of `plan-10` § *Data Model*'s change set, not a separate gate. `longText`
  rather than `json` follows the `webhook_events.headers` lesson: a `json` column can never carry an
  `encrypted*` cast, and field names are the one member-typed value in this feature that a future
  item might want protected. Nothing else is added, and **no new payload store, cache, export or
  archive is created** (AC3).
- **Retroactive by construction (AC19):** nothing is rewritten and nothing is migrated, so adding a
  name hides it in payloads already stored on the next view, and removing one reveals it again.
- **Easier:** #8's `proxy_maps.output` and `proxy_map_conditions.value`, and any future header
  viewer (AC42), inherit `SensitiveFieldMatcher` and the same effective-list rule without
  re-deciding anything.
- **Constrained:**
  - **`ProxyEventPayloadController` remains the only content-bearing response.** A second one is a
    change to this ADR and to ADR-017 Decision 5.
  - **The obfuscator never inspects a value** (AC14) and **never walks into a listed value**
    (C6).
  - **A JSON payload is never served raw**, whatever its size — see the risk below.
  - The client must keep rendering through text interpolation, never `v-html` (ADR-017, unchanged).
- **The one genuinely new failure mode:** obfuscating requires decoding, and `INGEST_MAX_BODY_BYTES`
  defaults to 50 MiB, so a very large valid JSON payload can exhaust memory while being decoded —
  producing a 500, never a disclosure. The tempting mitigation, a byte ceiling above which the raw
  bytes are served instead, is **forbidden**: it would serve a JSON payload's secrets in the clear.
  Any ceiling therefore needs a distinct member-facing state, which is the Designer's, and `plan-10`
  § *Risks* records it as a follow-up rather than inventing one.
- **Within stack:** PHP's `json_decode`/`json_encode`, Vue rendering. **No new dependency, no stack
  change.**
