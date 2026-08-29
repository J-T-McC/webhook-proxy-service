# ADR-026: The proxy fans out — inbound verification is removed from the product, and the outbound strip list reduces to the technically required minimum

- **Status:** **ACCEPTED — Project Owner, 2026-08-28.** Both decisions below are the Owner's own
  ruling, quoted verbatim in § *The product position this ADR renders*, recorded here rather than
  proposed here. See § *Owner-approval flags (✋)*, which carries no outstanding item and explains
  why the data-model change it would ordinarily gate is already approved by the words of the ruling.
  - **Amended 2026-08-29 — see § *Amendment A*: `design-17` was re-based, not withdrawn.** The
    amendment corrects one entry in § *Impact → Documents*, rules on the Owner-directed sign-off
    gate that entry had recorded as lapsed, and corrects one sentence about `docs/status.md`.
    **No decision in this ADR changes**, no superseded position is disturbed, no constraint is
    altered and no approval is reopened. The walk is in § *Amendment A*, ruling A3.
- **Author:** Principal Engineer
- **Date:** 2026-08-28 (amended 2026-08-29 — see § *Amendment A*)
- **Feature:** cross-cutting. There is no PRD behind this ADR. It removes a capability that
  PRD-10 introduced and that ADR-022 designed, and it completes a header-policy change that ADR-025
  began, because the product position below changes what this service is for.
- **Relationship to existing ADRs:** **supersedes in full**
  `adr-022-inbound-verification-at-the-ingest-boundary.md`. **Supersedes in part**
  `adr-025-outbound-header-policy-signature-pass-through-and-signing-header-names.md` (Decision 1,
  replaced by a larger reduction of the same constant), `adr-008-inbound-header-forwarding-policy.md`
  (two further strip entries) and `adr-023-outbound-request-contract.md` (the AC27 strip step, the
  Decision 5 rationale, and one premise of Decision 2). Every superseded document keeps its file, its
  status and its full text, and gains inline pointers at the affected passages. See
  § *Positions superseded*.
- **Companions:** ADR-021 (secret storage and rotation — the `verification` purpose leaves it, the
  `signing` purpose and the whole mechanism remain) · ADR-006 (the ingest URL token, which is now the
  sole authenticator on the ingest path) · ADR-013 and ADR-015 (the dispatched-output store and
  retry, which decide when a forwarded provider signature is still consistent with the body a
  destination receives).
- **Evidence base:** `docs/architecture/prd-16-template-model-feasibility.md`, retained. Its
  twenty-one-provider survey of verification constructions is the record of which providers' own
  signature headers a destination can verify once forwarded, and that record survives this decision
  intact. Its original subject — whether a bounded template vocabulary could express those
  constructions — is moot under Decision B; see § *Impact*.

## The product position this ADR renders

The Project Owner ruled on 2026-08-28:

> "we are going to forward all of the headers including auth, strip only what is technically
> required. We are going to remove everything related to validating incoming webhooks that we
> already added. Columns, code, etc. We are no longer validating when ingesting, just fanning."

This follows and completes the Owner's earlier ruling of the same day, which ADR-025 records:

> "our proxy is not a security layer, it's to support fan out."

Three consequences follow, and they are the whole of why this ADR exists.

1. **This service relays a request; it does not adjudicate one.** The ingest URL's token decides
   whether a request is accepted (ADR-006). Nothing else does, and nothing else will. There is no
   second factor at the ingest boundary, and the absence is deliberate rather than unfinished.
2. **Fidelity is the product, and the header set is the clearest expression of it.** A destination
   receives what the sender sent, minus only what would make the request malformed. Judging which of
   a sender's headers a destination ought to be trusted with is precisely the adjudication the first
   consequence removes.
3. **Inbound verification is not deprecated, disabled, or kept behind a flag. It is removed.** A
   capability that no longer expresses the product's position is not made optional; it is taken out,
   along with its columns, its stored secrets and its surfaces, so that the codebase states one
   position rather than two.

ADR-025 rendered the first half of this position while inbound verification still stood, and it
therefore reasoned carefully about the line between a header carrying a **digest** and a header
carrying **key material**. That line is now settled differently and more simply: this service does
not classify a sender's headers at all. What remains stripped is stripped because HTTP requires it,
not because of what it might contain.

## Question

Two linked questions, neither of which any approved requirement answers, and both of which the
ruling above decides:

1. **What is the smallest set of inbound headers that must not be forwarded**, once the product
   stops making any judgement about the sensitivity of a sender's headers — and what is the honest
   consequence of forwarding the rest, including credentials?
2. **What exactly constitutes "everything related to validating incoming webhooks"**, given that the
   secret-storage machinery inbound verification uses is the same machinery outbound signing depends
   on, and that outbound signing must survive completely intact?

## Positions superseded

Each row names a position, quotes it, and states what replaces it. Every source document keeps its
file, its status and its full text.

| Document | Position | Verbatim | Now |
|---|---|---|---|
| **ADR-022**, in full | Inbound verification runs at the ingest boundary under a closed two-scheme registry | "PRD-10 requires that a proxy may demand an incoming request prove itself under one of exactly two named schemes" | **Superseded in full by Decision B.** No proxy may demand anything of an incoming request beyond the ingest token. The seam ADR-022 chose was correct for the capability it served; the capability is withdrawn. **One passage is carried forward rather than retired** — Decision 4's statement of the Standard Webhooks construction, which outbound signing still depends on and which ADR-023 cites as stated only there. It is restated in Decision 5 below, and that restatement, not ADR-022, is now the normative record. |
| **ADR-025**, Decision 1 | Five provider signature names leave the strip list; `cookie` and `authorization` stay | "`cookie` — inbound session state addressed to this service's origin. It is meaningless at a destination and is credential material." · "`authorization` — the sender's credential to *us*, not the destination's. Forwarding it hands a third party a secret that opens our own ingest." | **Superseded by Decision A.** The five provider signature names still leave, so Decision 1's outcome is contained within Decision A rather than reversed. What is superseded is its **boundary**: `cookie` and `authorization` leave the strip list too. The reasoning quoted here is not withdrawn as a description of the risk — it is accurate, and Decision A records it as the cost the Owner has accepted rather than as an argument that lost. |
| **ADR-025**, Decision 1, the constant's character | The strip list is a transport-scoped **and credential-to-us** deny-list | "After this decision `DeliveryUnit::STRIPPED_HEADERS` is a **transport-scoped and credential-to-us deny-list**, not a verification-artefact list." | **Superseded by Decision A.** The constant is now **transport-scoped only**. It contains no entry that is there because of what a header's value might be, and its docblock must say so. This is what makes the list checkable by a Reviewer who has never seen a given header: if the header is not `host`, not `content-length` and not one of the eight RFC 7230 §6.1 fields, it forwards. |
| **ADR-025**, Decision 1, the safety argument | The per-proxy AC27 strip is what makes provider-signature pass-through safe | "**The per-proxy strip is unchanged and is what makes Decision 1 safe.**" | **Superseded by Decision B**, and this is the premise correction that matters most in this ADR. The AC27 per-proxy strip is deleted. Pass-through is safe now for a different reason, and a reader must not carry the old one forward: **no header carries a member's own verification secret, because no member can configure a verification secret.** The hazard AC27 existed to prevent is removed at its source rather than mitigated at the boundary. |
| **ADR-008**, § Decision, the `Cookie` and `Authorization` strip bullets | Inbound session state and the sender's credential to us are stripped | "`Cookie` — inbound session/cookie state must not cross to destinations." · "Inbound `Authorization` — the sender's credential to *us* is not the destination's credential; forwarding it leaks a secret to third parties." | **Superseded by Decision A.** Both names leave the constant and both are forwarded. ADR-008's **safe-allowlist policy itself is untouched and is what Decision A operates within** — forward everything except a stripped set; the stripped set shrinks to ten entries. |
| **ADR-023**, Decision 1, step 2 | The outbound composition subtracts the proxy's verification headers | "2. `forwarded −=` the proxy's verification headers (AC27, per PROXY)" | **Superseded by Decision B.** Step 2 is deleted from the composition. Steps 1, 3, 4 and 5 are unchanged and remain operative, and the composition becomes: forward inbound minus the constant, add the credential and the signing headers, remove any forwarded header colliding with an added one, merge. |
| **ADR-023**, Decision 2, the default-case parenthetical | A credential-versus-forwarded collision cannot arise by default | "which cannot arise in the default case, since ADR-008 already strips `authorization`, but can the moment a member names their credential header something else" | **Superseded by Decision A**, in the parenthetical only. The collision is now the **ordinary** case: a destination credential defaults to the `Authorization` header name (PRD-10 AC30) and inbound `Authorization` is now forwarded. Decision 2's rule resolves it correctly and unchanged — the added header wins, the forwarded one is dropped, matched case-insensitively — so the mechanism needs no change. Only the claim about how often it fires does. |
| **ADR-023**, Decision 5 | The three `webhook-*` names must not go into the constant, because that would strip them from an unsigned proxy's destinations | "Adding them to the constant would strip `webhook-id`/`webhook-timestamp`/`webhook-signature` from the outbound set of **every** destination … which changes what an untouched destination receives and breaks AC63's byte-identical guarantee." | **Operative, with one supporting clause superseded by Decision B.** The decision stands: this service's own outbound header names never go into the constant. Its closing sentence — "The constant's five inbound-signature entries … are unchanged; AC43 confirms inbound forwarding is otherwise untouched" — is superseded, because those five entries and two more are gone. |

**Not superseded, and named because each looks as though it should be:**

- **ADR-021 in full.** Secret storage, the `proxy_secrets` table, the rotation overlap, the live-set
  predicate, the two-row cap, the fail-loud decrypt rule and the encryption-at-rest column
  inventory all stand exactly as ratified. One of the two purposes ADR-021 anticipated ceases to
  have any rows; the mechanism is unchanged and is load-bearing for outbound signing. See
  Decision 3.
- **ADR-023 Decisions 3, 4, 6, 7 and 8** — the derived message id, one signature entry per live
  signing secret, the dispatched bytes being unaffected, secrets never reaching a queued job, and
  the `withTrashed()` proxy and destination loads. All stand verbatim. In particular the
  `withTrashed()` **proxy** load in `DeliveryUnitResolver` stays: it arrived carrying two reasons,
  one of which is deleted here, and the other — the proxy's live signing set — is sufficient on its
  own.
- **ADR-025 Decisions 2 and 3.** The outbound signing header rename to `WebhookProxy-Id`,
  `WebhookProxy-Timestamp` and `WebhookProxy-Signature`, and the deferral of all provenance
  headers, are unaffected. Decision 2's argument is **strengthened** by this ADR, not weakened —
  see Decision 6.
- **ADR-006 in full.** The ingest token remains the whole of ingest authentication, and it is now
  the only thing that ever was. The constraint that the ingest path never appears in an outbound
  header stands absolutely, which is why `host` remains stripped.
- **ADR-010's raw-capture seam and ADR-004's response decoupling.** The ingest hot path loses one
  step and is otherwise byte-for-byte the path it was before item #10.
- **PRD-10's field obfuscation, destination credential, sensitive-field list and outbound signing
  criteria.** None depends on inbound verification. See § *Impact* for the criteria that do.

## Decision

### (1) Decision A — the outbound strip list reduces to the technically required minimum: `host`, `content-length` and the RFC 7230 §6.1 hop-by-hop set. Everything else is forwarded.

`DeliveryUnit::STRIPPED_HEADERS` contains exactly ten entries after this decision, and every one of
them is there because forwarding it would produce a malformed or misrouted request, not because of
what its value might contain:

- **`host`** — the destination's own host must be used. A forwarded `Host` is a routing and
  request-smuggling hazard, and it is the ADR-006 guard in the outbound direction: it is the header
  that would otherwise carry this service's own ingest hostname, which is the first half of a URL
  whose second half is a bearer credential. It never leaves, under any decision, ever.
- **`content-length`** — recomputed by the outbound HTTP client for the body actually sent. A
  forwarded value is a framing bug waiting for the first proxy that transforms a payload.
- **The RFC 7230 §6.1 hop-by-hop set** — `connection`, `keep-alive`, `proxy-authenticate`,
  `proxy-authorization`, `te`, `trailer`, `transfer-encoding`, `upgrade`. These are scoped by the
  standard to a single transport connection. Forwarding them is a protocol error, not a policy
  choice.

**`proxy-authorization` stays, and it stays on hop-by-hop grounds alone.** It is a credential, and
it is retained while `authorization` is released, which reads as an inconsistency until the ground
is named. RFC 7230 §6.1 lists it as a connection-scoped field; forwarding it across a hop is a
protocol violation independent of its value. Stated here so a later reader does not "correct" the
list into consistency by removing it.

**Everything else forwards, including `authorization`, `cookie`, and every provider signature
header.** `stripe-signature`, `x-hub-signature`, `x-hub-signature-256`, `x-signature` and
`x-webhook-signature` leave the constant, as ADR-025 Decision 1 already ruled; `cookie` and
`authorization` leave it now.

#### The forwarded credential is an accepted trade, not an oversight

**A forwarded `Authorization` header reaches every destination of the proxy.** A proxy fans out; the
sender presents one credential to this service; every destination that proxy dispatches to receives
a copy of it. Where that credential is the ingest token itself, or any secret that opens this
service, the destination operator holds it. Where it is a credential to some third party, every
destination operator holds that too. The same is true of `cookie`.

The Project Owner's grounds, recorded because they are the answer to the objection rather than a
restatement of the decision: **they have never seen a webhook carry an `Authorization` header; this
service does not validate it; and a recipient can still validate with it.** The third ground is the
substantive one. A destination that has been configured to expect a bearer token from the original
sender can only check it if it arrives, and stripping it is the single thing preventing that — the
same argument ADR-025 Decision 1 made for provider signature headers, extended to a header the
earlier decision drew a line in front of.

**This is ruled as instructed and is not reopened here.** The exposure is real, it is named above in
the terms an objector would use, and it is the Owner's to hold. What this ADR does with it is make
it legible: a member choosing a destination is choosing who receives their sender's headers, in
full. That is the product.

**Two things the ruling does not do**, stated so the boundary is not read wider than it is:

- **It changes nothing about this service's *own* secrets.** The ingest token, the signing secrets
  and the destination credentials remain encrypted at rest, are never serialized into a response, a
  prop, a queued job, an attempt row or a log line, and none of them travels in a forwarded header.
  The strip list has never been what protected them.
- **It adds no header and removes no added header.** The destination credential (PRD-10 AC30) and
  the signing headers (AC54–AC64) are unaffected in every respect.

#### The credential collision is now the ordinary case, and precedence already handles it

PRD-10 AC30 defaults a destination credential's header name to `Authorization`. Until now a
forwarded inbound `Authorization` could never meet it, because the constant removed the forwarded
one first. It can now, on any proxy whose sender authenticates to us and whose destination carries a
credential.

**ADR-023 Decision 2 resolves it correctly and needs no change**: every forwarded header whose
lowercased name matches a lowercased added name is dropped before the added set is merged, so the
destination receives the credential this member configured for it and never two `Authorization`
headers. What changes is only how often that rule fires, and the correction to ADR-023's
parenthetical is recorded in § *Positions superseded*.

The consequence worth naming, because it is the case a support conversation will be about: **a
destination that carries its own credential never sees the sender's**, and one that does not carry
its own credential sees the sender's. That is not a hazard — it is precedence working — but it means
two destinations of one proxy can legitimately receive different `Authorization` values, and nothing
in the interface says so.

### (2) Decision B — inbound verification is removed from the product. The complete removal set.

There is no verification scheme, no verification secret, no verification header name, no rejection
path and no verification surface. The ingest hot path returns to its pre-item-#10 shape: resolve the
proxy by token hash, read the request facts once, capture, resolve the response, dispatch, return.

The set below was established from the code rather than from any prior list, and it is intended to
be exhaustive. Where a name appears that a reader might expect to survive, or survives where a
reader might expect it to go, that is stated in Decision 3.

**Deleted outright — production classes:**

- `app/Services/InboundVerifier.php`
- `app/Verification/VerificationSchemeHandler.php`
- `app/Verification/SharedSecretScheme.php`
- `app/Verification/StandardWebhooksScheme.php`
- the `app/Verification/` directory itself, which holds nothing else
- `app/Enums/VerificationScheme.php`
- `app/Enums/VerificationResult.php`
- `app/Http/Controllers/ProxyVerificationOverlapController.php`

**Deleted outright — test files whose entire subject is inbound verification:**

- `tests/Unit/Verification/SharedSecretSchemeTest.php`
- `tests/Unit/Verification/StandardWebhooksSchemeTest.php`
- `tests/Unit/Services/InboundVerifierTest.php`
- `tests/Unit/Enums/VerificationSchemeTest.php`
- `tests/Feature/Ingest/InboundVerificationIntegrationTest.php`
- `tests/Feature/Proxies/VerificationValidationTest.php`
- `tests/Feature/Proxies/ProxyVerificationOverlapControllerTest.php`

**Edited — the ingest path:**

- `app/Http/Controllers/IngestController.php`. The verification gate goes: the
  `InboundVerifier` constructor dependency, the `try`/`catch (SecretUnavailableException)` with its
  `report()` and `abort(500)`, the `VerificationResult::Failed` branch with its 401 response, the
  `ingest.verification_failed` log line, the `VERIFICATION_FAILED_BODY` constant, and the
  `VerificationResult`, `SecretUnavailableException`, `InboundVerifier` and `Log` imports.
  **The single `$request->getContent()` read stays exactly where it is**, immediately after the
  proxy resolution and before the capture transaction. It predates verification, it is passed to
  `WebhookEventCapture` unchanged, and moving it would be an unrelated change to the hot path.
  The capture-failure `report($e)` and its surrounding `try`/`catch (Throwable)` are untouched.
- `app/Pipeline/PipelineFactory.php`. The reserved comment on the commented-out step list names
  `VerifyStep` as "#10 — verification token (front)". That step was never built and now never will
  be; the comment is removed rather than left as an invitation.

**Edited — the outbound path:**

- `app/Support/OutboundHeaders.php`. The `$verificationHeaderNames` parameter and the first
  `withoutNames()` call go, so `build()` takes the unit, the credential header name, the credential
  value and the signing secrets. The class docblock's step (2) goes with it. **Nothing else in this
  class changes**, and the surviving composition is ADR-023 Decision 1 steps 1, 3, 4 and 5.
- `app/Services/DeliveryUnitResolver.php`. `verificationHeaderNamesFor()` and the
  `verificationHeaderNames:` constructor argument go, along with the `VerificationScheme` import and
  the docblock clause. **The `withTrashed()` proxy load stays** — see Decision 3.
- `app/Pipeline/DeliveryUnit.php`. The `$verificationHeaderNames` constructor property and its
  docblock go. Decision A's change to `STRIPPED_HEADERS` lands in the same file: seven entries
  removed (`cookie`, `authorization`, and the five provider signature names), the comment blocks
  above them removed, and the constant's docblock rewritten to describe a **transport-scoped**
  deny-list. The `forwardHeaders()` implementation is unchanged.
- `app/Actions/DeliverToDestination.php`. The `$unit->verificationHeaderNames` argument passed to
  `OutboundHeaders::build()`, and the docblock sentence naming the verification strip.

**Edited — configuration, persistence and the API surface:**

- `app/Models/Proxy.php`. The `verification_scheme` cast, both `@property` docblock lines, both
  `#[Fillable]` entries, and the `VerificationScheme` import. The `secrets()` relation stays.
- `app/Http/Requests/StoreProxyRequest.php` and `app/Http/Requests/UpdateProxyRequest.php`. The
  `verification_scheme`, `verification_header_name` and `verification_secret` rules, the
  `VerificationScheme` import, and — on `UpdateProxyRequest` — the
  `proxyHasLiveVerificationSecret()` helper with its `SecretStore` and `SecretPurpose` imports.
- `app/Http/Controllers/ProxyController.php`. Both `SecretStore::replace(…, SecretPurpose::
  Verification, …)` calls in `store()` and `update()`; the `verification_scheme` and
  `verification_header_name` keys in `update()`'s attribute array; both `standardWebhooksTolerance`
  page props on `create()` and `edit()`; and, once those are gone, the `SecretStore` constructor
  dependency and the `SecretStore`, `SecretPurpose` and `StandardWebhooks` imports, none of which
  this controller has any remaining use for. `DeliveryStatistics` stays.
- `app/Http/Resources/ProxySecurityResource.php`. The entire `verification` sub-object and the
  `$verificationStatus` lookup. The `signing` sub-object and the `destinations` credential map are
  untouched, as is `#[PreserveKeys]`, which is load-bearing for the latter.
- `routes/web.php`. The `proxies.verification.overlap.destroy` route and the
  `ProxyVerificationOverlapController` import. The three signing routes stay.

**Edited — the member-facing surfaces:**

- `resources/js/pages/proxies/ProxyForm.vue` (design-10 Screen 1). The whole Verification
  `fieldset`, the five `initialVerification*` mount seeds, the `verification_scheme`,
  `verification_header_name` and `verification_secret` form keys, the `VERIFICATION_NOT_REQUIRED`
  sentinel and `verificationSchemeSelect` computed, `verificationReplaceClicked`,
  `verificationSecretIsSet`, `replaceVerificationSecret()`, the
  `watch(() => form.verification_scheme, …)` clearing arm, and the `standardWebhooksTolerance` prop.
  **The Sensitive fields section and the Destinations fieldset are untouched.** The `security` prop
  itself stays on this component — Screen 3's credential subsection reads it.
- `resources/js/pages/proxies/Show.vue` (design-10 Screen 4). The whole Verification `Card`, the
  `verificationSchemeLabel`, `verificationSecretStatus` and `verificationOverlapStatus` computeds,
  `verificationOverlapBusy`, `verificationOverlapError`, `endVerificationOverlap()` and its
  `proxyRoutes.verification.overlap.destroy` call. **The Signing card immediately below it, its own
  end-overlap handler and the signing dialog are untouched.**
- `resources/js/pages/proxies/Create.vue` and `Edit.vue`. The `standardWebhooksTolerance` prop
  declaration and its forwarding to `ProxyForm.vue`. `defaultSensitiveFieldNames` stays.
- `resources/js/types/proxies.ts`. The exported `VerificationScheme` union and the
  `ProxySecurity.verification` member. `ProxySecurity.signing` and `ProxySecurity.destinations`
  stay.
- `resources/js/components/DestinationRows.vue`. One comment referring to "Screen 1's verification
  secret" as the shape precedent for the credential field. The behaviour it describes is unchanged;
  only the dangling reference goes.
- `resources/js/routes/` is generated by `@laravel/vite-plugin-wayfinder` from the route
  definitions and requires no manual edit — removing the route removes the helper.

**Edited — tests that survive with their verification cases pruned.** Each of these covers something
that outlives verification, so the file stays and its verification-specific methods or fixtures go:

- `tests/Feature/Ingest/IngestControllerTest.php` — the cases added for the gate: the four AC25
  negatives, the AC11 500 case, the log-payload assertion, and the FIFO rejection case. **The
  body-read-once test stays** and is re-pointed at the surviving single read, because reading the
  raw body exactly once is a property of the capture path, not of verification.
- `tests/Unit/Services/DeliveryUnitResolverTest.php` — the `verificationHeaderNames` assertions,
  including the `['webhook-id', 'webhook-timestamp', 'webhook-signature']` expectation.
- `tests/Unit/Support/OutboundHeadersTest.php` — the verification-strip cases. **The AC37
  byte-identical baseline stays** and becomes a stronger claim, since fewer headers are removed.
- `tests/Unit/Pipeline/DeliveryUnitTest.php` — the seven newly-forwarded names move from the
  stripped assertion to the forwarded one, and the forwarded count in that test's closing
  `assertCount` rises accordingly. The Senior Developer should count against the fixture rather than
  against any number quoted in a document.
- `tests/Feature/Proxies/ProxySecurityResourceTest.php` — the `verification` sub-object assertions.
- `tests/Unit/Services/SecretStoreTest.php`, `tests/Unit/Models/ProxySecretTest.php`,
  `tests/Unit/Actions/ExpireProxySecretsTest.php`,
  `tests/Feature/Console/PurgeExpiredProxySecretsTest.php`,
  `tests/Feature/ProxyEvents/ProxyEventPayloadControllerTest.php` — every fixture using
  `SecretPurpose::Verification` retargets to `SecretPurpose::Signing`. **None of these tests loses
  coverage**: each is testing rotation, expiry, hiding or sweeping, none of which is
  purpose-specific, and each currently uses `Verification` only because it was the first purpose
  built.
- `tests/Feature/Delivery/OutboundSigningIntegrationTest.php` — any fixture configuring a
  verification scheme in order to assert the AC27 strip alongside signing.
- `tests/Unit/Migrations/SensitiveDataHandlingSchemaTest.php` — the `proxies` column assertions,
  which name three added columns and must name one. Its rollback round trip and its
  pre-existing-index-survival list are otherwise unchanged.

### (3) What stays, and why — the `SecretStore` boundary is the line a developer must not cross

`SecretStore` serves both the `verification` and the `signing` purposes through one set of methods,
which makes every `verification` reference inside it look like removal material. **It is not.** The
class is purpose-parameterised throughout: every method takes a `SecretPurpose` and filters on it,
and no method contains a `verification` branch. Removing the `verification` purpose removes callers,
never code.

**`app/Services/SecretStore.php` stays whole and unmodified**, with one exception noted below. Every
method is load-bearing for outbound signing:

| Member | Signing depends on it for |
|---|---|
| `liveFor()` | The proxy's live signing set, read once per delivery by `DeliveryUnitResolver`, producing one `v1,<base64>` entry per member (ADR-023 Decision 4). Its fail-loud `SecretUnavailableException` on an undecryptable row is PRD-10 AC11's all-or-none rule. |
| `replace()` | Every regeneration. Carries the two-row cap and the 24-hour demotion in one transaction. |
| `generate()` | The whole of AC56 — the product generates the signing secret, `whsec_`-prefixed base64, 32 random bytes. |
| `endOverlap()` | The **End overlap now** action on the Signing card and in the signing dialog. |
| `disable()` | Disabling signing, which deletes every row for the purpose (ADR-021 Decision 5). |
| `statusFor()` | The `signing` sub-object of the `security` prop — enabled, generated-at, overlap-expires-at. |

The one exception: `disable()`'s docblock contrasts signing's semantics with verification's dormant-
secret retention. That sentence loses its subject and should be rewritten to state signing's own
semantics directly. **This is a comment change and nothing else. Do not delete the method.**

**Also load-bearing for signing, and therefore untouched:**

- **`proxy_secrets`** — the table, its `UNIQUE(proxy_id, purpose, is_current)` partial-unique index,
  and every column. `purpose` stays `string(32)` rather than narrowing to a single-value column,
  because ADR-021 Decision 2 chose that type precisely so a later purpose costs no migration, and
  that reasoning is unaffected.
- **`app/Models/ProxySecret.php`** — the `encrypted` cast, `$hidden = ['value']`, the `live()`
  scope, the `purpose` enum cast and the `proxy()` relation. Unchanged.
- **`app/Enums/SecretPurpose.php`** — the enum survives with the `Signing` case only. A single-case
  backed enum is the correct shape here for the reason above.
- **`app/Support/RotationOverlap.php`** — `HOURS = 24`, the fixed window signing rotations use.
- **`app/Exceptions/SecretUnavailableException.php`** — raised by `liveFor()` and carried on the
  `DeliveryUnit` for signing's deferred failure (AC11). Its constructor takes a `SecretPurpose`,
  which still has a case.
- **`app/Actions/ExpireProxySecrets.php`** and **`app/Actions/PurgeExpiredProxySecrets.php`** with
  its `secrets:purge-expired` daily schedule. Both are purpose-agnostic and both are the liveness
  net for a signing rotation's demoted row.
- **`app/Support/StandardWebhooks.php`** — **whole and unmodified, including `verify()`,
  `parseEntries()` and `TOLERANCE_SECONDS`.** This is the one place a developer following a
  `verification` thread is most likely to over-delete. `sign()` is what emits the outbound signature.
  `verify()` is what the outbound signing suites call as the receiver-side oracle — it is how
  `OutboundHeadersSigningTest` and `OutboundSigningIntegrationTest` prove that what this service
  emits is verifiable by a conforming recipient — and `TOLERANCE_SECONDS` is `verify()`'s own replay
  window. Deleting either would silently reduce the outbound signing suite to asserting that a
  string is present.
- **`app/Http/Controllers/ProxySigningController.php`**, **`ProxySigningOverlapController.php`** and
  the three `proxies.signing.*` routes.
- **`DeliveryUnitResolver`'s `withTrashed()` proxy load.** It was introduced carrying two reasons —
  the AC27 verification header names and the proxy's live signing set. The first is deleted; the
  second is sufficient on its own, and the load must stay, because `Delivery::proxy()` is a plain
  `belongsTo` on a `SoftDeletes` model and a soft-deleted proxy would otherwise resolve `null` at
  runtime where PHPStan cannot see it.
- **`OutboundHeaders` as the single build point**, `DeliverToDestination::send()` as the single call
  site, and ADR-023 Decision 2's precedence rule. All unchanged.
- **Item #10's sensitive-data handling in every other respect** — field obfuscation, the 23-name
  default list, the per-proxy additions, the revealed-payload envelope, the destination credential
  and its removal control, the encryption-at-rest column set, the old-input scrub for the credential,
  and the whole of outbound signing.
- **The encrypted-column inventory is unchanged.** `webhook_events.body`, `webhook_events.headers`,
  `dispatched_payloads.body`, `proxies.ingest_token`, `proxy_secrets.value` and
  `destinations.credential_secret` are still exactly six, so ADR-021's `APP_PREVIOUS_KEYS` rule and
  the reflection test that pins it need no change. This is worth stating because it looks as though
  removing a secret should shorten the list, and it does not: the column that held verification
  secrets is the same column that holds signing secrets.

**The ingest middleware stack is untouched** — `EnsureIngestIsSecure`, `EnforceIngestBodyLimit` and
`throttle:ingest` all remain, and none of them ever knew about verification.

### (4) The migration: one migration, irreversible in substance, and nothing is preserved

The removal of `proxies.verification_scheme`, `proxies.verification_header_name` and every
`proxy_secrets` row of purpose `verification` lands in **one new migration**,
`database/migrations/2026_08_28_000001_remove_inbound_verification.php`, doing two things:

1. **`DELETE FROM proxy_secrets WHERE purpose = 'verification'`** — every row, current and
   superseded alike.
2. **`ALTER TABLE proxies DROP COLUMN verification_scheme, DROP COLUMN verification_header_name`**,
   expressed as a single `Schema::table()` `dropColumn` call with both names.

The order between the two is not load-bearing — by the time this migration runs, no code reads
either the column or the rows — and it is stated only so a reader does not look for a constraint
that is not there.

**`2026_08_27_000001_add_sensitive_data_handling_schema.php` is not edited.** Editing an applied
migration in place converges nothing: a database that has already run it will not run it again, so
the two columns would survive silently on every developer's working database. Some have already run
it. And the row deletion cannot be expressed as an edit to a create-table migration in any case, so
an in-place edit would split one removal across two mechanisms while fixing neither.

**One migration, not a two-step expand-and-contract.** Expand-and-contract exists to keep an old and
a new version of the application running against one schema across a rolling deploy. Here the
columns are read only by code that is deleted in the same change; there is no version of the
application that wants them and is still running; and item #10 has never merged to `main`, so no
deployed instance reads them at all. A second step would buy nothing and would leave a half-removed
schema that somebody has to remember to finish.

**`sensitive_fields` on `proxies`, and `credential_header_name`, `credential_secret` and
`credential_set_at` on `destinations`, are not touched by this migration.** They were added by the
same migration as the two columns being dropped and they belong to capabilities that survive.

#### Reversibility, stated honestly

`down()` restores **the two columns and nothing else**: `verification_scheme` as `string(32)`
nullable and `verification_header_name` as `string(128)` nullable, in that order, matching their
original definitions exactly.

**`down()` cannot restore, and must say so in its own docblock:**

- **The column values.** Every proxy's chosen scheme and, under `shared-secret`, its chosen header
  name. A rolled-back database has two columns that are `NULL` on every row.
- **The deleted secrets.** Every `proxy_secrets` row of purpose `verification` is gone permanently.
  These are secrets **issued by upstream providers** (PRD-10 AC26) — the product never generated
  them, never displayed them after saving, and cannot reconstruct them.
- **The code.** A rolled-back schema has no reader, no writer and no surface for either column.

This mirrors the precedent already set by `2026_08_27_000001`'s own `down()` docblock, which states
plainly that it is destructive to every stored secret. **The migration is reversible in schema and
irreversible in substance, and the docblock says exactly that rather than leaving `down()` to imply
otherwise.**

**Nothing is preserved, and nothing should be.** A retained verification secret would be a
credential with no consumer, no surface, no rotation, no expiry and no position in any retention
pass — precisely the shape ADR-021 and PRD-10 AC10 and AC11 exist to prevent. It cannot be handed
back to the member, because the product has never displayed a stored secret and adding a disclosure
surface in order to retire a feature would be absurd. A member who wants their verification secret
already has it: their provider issued it.

#### One ordering constraint that is load-bearing

`ProxySecret::$casts` maps `purpose` to `SecretPurpose`. **Removing the `SecretPurpose::Verification`
case while rows carrying `'verification'` still exist makes every hydration of those rows throw.**

Therefore: **the `SecretPurpose::Verification` case is removed in the same task as this migration,
not in the earlier code-removal task.** No shipping code path hydrates such a row — `SecretStore`
filters by purpose on every query, and both sweepers delete through the query builder without
hydrating — so the exposure is confined to a developer's working database and to a test that
deliberately eager-loads the `secrets` relation. Landing the enum case and the row deletion together
closes it entirely rather than relying on that analysis holding.

### (5) The Standard Webhooks construction, restated — this is now its normative record

ADR-023 states that the outbound signature's construction "is stated once, in ADR-022 Decision 4,
and is not restated here." ADR-022 is superseded in full by this ADR, so that cross-reference would
otherwise dangle and outbound signing's signed-content definition would live only in a retired
document. It is restated here, and this restatement is what ADR-023 now points at.

The normative source remains the **Standard Webhooks specification** at `standardwebhooks.com`, and
the properties below are the specification's, not the product's:

| Element | Value |
|---|---|
| Signed content | `<id>.<timestamp>.<body>` — literal dots, no separator escaping, the body exactly as dispatched |
| Algorithm | `HMAC-SHA256` |
| Encoding | **base64**, not hex |
| Signature value | a **space-delimited list** of entries, each `v1,<base64 signature>` — one per live signing secret, current first |
| Key material | the stored secret with an optional leading `whsec_` stripped, then **base64-decoded**. A secret that does not decode is a `SecretUnavailable` failure, not a signature failure |
| Comparison | constant-time (`hash_equals`) wherever a comparison is made |
| Replay tolerance | `App\Support\StandardWebhooks::TOLERANCE_SECONDS = 300`, a class constant and not configuration, applied inside `verify()` |

Everything above is implemented by `App\Support\StandardWebhooks`, which is header-name-agnostic —
`sign($id, $timestamp, $body, $secret)` takes no header names at all. **The header names those
values travel under are ADR-025 Decision 2's**: `WebhookProxy-Id`, `WebhookProxy-Timestamp` and
`WebhookProxy-Signature`.

### (6) Sequencing and build order

This decision lands entirely on `feat/item-10-sensitive-data`, before that branch merges to `main`.
Item #10 has never merged, so removing a capability it introduced costs nothing beyond the work
already spent on it; after a merge it would be a member-visible withdrawal with a migration against
live configuration.

The order below is the order the work must be done in, and each step's placement has a reason.

**Step 1 — the member-facing surfaces (Decision B).** `ProxyForm.vue`, `Show.vue`, `Create.vue`,
`Edit.vue`, `proxies.ts`, `DestinationRows.vue`'s comment. **This comes first, before the backend,
and the order is not interchangeable**: removing `ProxySecurityResource`'s `verification` sub-object
while `Show.vue` still reads `props.security.verification.scheme` is a runtime break that
`pnpm types:check` will not catch, because the TypeScript interface still declares the member. A
component that reads a prop key which still exists is harmless; the reverse is not.

**Step 2 — the backend (Decision B).** Everything in Decision 2's removal set except the
`SecretPurpose::Verification` case and the migration: the ingest gate, the verification classes and
enums, the resolver and header-builder parameters, the model, the form requests, the controller, the
resource, the route and the pipeline comment, together with the deleted and pruned test files.

**Step 3 — the migration (Decision 4), carrying the `SecretPurpose::Verification` removal.** Last of
the removal, so that no code reads a column or a row that has been dropped, and so that the enum
case and the rows it maps disappear together. **Steps 1 through 3 should be completed without the
branch being parked between them**, because a working database that has run the earlier migration
holds `'verification'` rows until this step deletes them.

**Step 4 — T50, the outbound signing header rename (ADR-025 Decision 2), unchanged.** It becomes
strictly simpler after the removal, and its argument becomes stronger. Its central caution — that a
global find-and-replace of `webhook-` would break the inbound reader and the AC27 strip map — is
moot, because both are gone; the task's "explicitly not touched" list names files that no longer
exist. And the collision the rename exists to close is now **universal rather than conditional**:
every Svix-family sender's inbound `webhook-id`, `webhook-timestamp` and `webhook-signature` trio is
forwarded on every proxy, since nothing strips it any more, so an identically-named outbound trio
would silently destroy the sender's on every signing proxy rather than only on one whose
verification happened to be unconfigured.

**Step 5 — Decision A, the strip list.** After the removal, because Decision A's safety argument
depends on there being no member-configurable verification header. This **replaces T51**, which
removed five names on a premise that no longer holds; see § *Impact*.

**Step 6 — the surviving M9 hardening: T45 (narrowed), T46, T47, T48.**

**Step 7 — T44, then T49 (narrowed).** Both are manual verification passes and both must run against
the finished tree.

#### T44, specifically, because the question is a fair one

**T44's scope does not include any surface being deleted.** It walks `design-10` Flows G, H and I,
which are enable-and-reveal, regenerate-and-end-overlap, and disable — all outbound signing.
Verification is Flows A, B and C.

**But T44 must not run before the removal.** Removing the Verification card from `Show.vue` and the
Verification fieldset from `ProxyForm.vue` edits the same two files Flows G, H and I are walked
through, and Decision A changes what the dispatches those flows produce actually send. A pass
against a build that is about to be rebuilt certifies nothing. **T44 moves to after Step 5**, keeping
its Acceptance Criteria exactly as written.

Once T44 sits that late it is adjacent to T49, whose narrowed flow walk covers the same ground.
Whether to fold one into the other is the Task Planner's call and is flagged here rather than made.

## Alternatives

Only options that would otherwise be re-proposed are recorded.

- **Keep inbound verification behind a feature flag, or mark it deprecated.** Rejected by the ruling,
  and correctly. A flag would preserve the columns, the secrets, the schemes, the surfaces and the
  test surface in order to default them off, so it would remove no maintenance and no exposure while
  leaving the codebase asserting two incompatible product positions. "Removed" was stated
  explicitly, in those terms, and it is the cheaper outcome as well as the ruled one.
- **Keep the schemes as dead code for a later reinstatement.** Rejected. Unreferenced authentication
  code that no test exercises decays silently, and the specification it implements will have moved
  by the time anyone wants it. The record of how it was built survives in ADR-022, in PRD-10 and in
  this branch's history, which is the right place for it.
- **Retain stored verification secrets, unreachable, in case the decision is reversed.** Rejected in
  Decision 4: a credential with no consumer, no expiry and no retention position is the exact shape
  the feature's own criteria forbid.
- **Drop the two `proxies` columns by editing the unmerged migration in place.** Rejected in
  Decision 4: it converges no database that has already applied that migration, and it cannot
  express the row deletion at all.
- **A two-step expand-and-contract migration.** Rejected in Decision 4: nothing reads the columns
  concurrently, so the intermediate step protects nothing and can be forgotten.
- **Keep `authorization` stripped and forward everything else.** Rejected by the ruling, which names
  `auth` specifically ("forward all of the headers including auth"). The argument against forwarding
  it is recorded in Decision A in the terms an objector would use, and in ADR-025 Decision 1's own
  words in § *Positions superseded*, so the trade is legible rather than lost.
- **A per-proxy toggle for forwarding credentials.** Rejected on the same grounds ADR-025 Decision 1
  rejected a toggle for signature pass-through: the benefit accrues to the destination operator and
  the control would be shown to the proxy owner, neither default is defensible, and it reintroduces
  the per-proxy header configuration this decision removes. Recorded so it is not re-proposed as a
  compromise.
- **Keep `App\Support\StandardWebhooks::verify()` out of the surviving code because verification is
  gone.** Rejected, and named because the method's name makes it the likeliest casualty of a careless
  removal: `verify()` is the receiver-side oracle the outbound signing suites use to prove the
  emitted signature is verifiable at all. Deleting it would leave those suites asserting only that a
  header is present.
- **Retire `docs/architecture/prd-16-template-model-feasibility.md` along with PRD-16.** Rejected: it
  is the evidence base ADR-025 Decision 1 and Decision A both rest on, and its provider findings are
  about constructions rather than about the template model that is being withdrawn.

## Reasoning

- **The strip list becomes checkable rather than curated.** ADR-025 improved it from "verification
  artefacts" to "transport state and credentials to us"; Decision A improves it again to "transport
  state", which is the only category whose membership a Reviewer can settle from a specification
  rather than from judgement. The previous list required somebody to decide, for each new header
  anyone might invent, whether it was a credential. Nobody has to decide anything now.
- **Removing the capability is what makes pass-through simple, and the causality runs that way
  round.** ADR-025 needed a careful digest-versus-key-material distinction and a per-proxy strip
  because a member could configure a header whose value was their own secret. With no such
  configuration, there is no header whose value this service put there, and the whole class of
  hazard the AC27 strip guarded goes with it. Decision A is not Decision B's consequence, but it is
  much cheaper to hold because of it.
- **The `SecretStore` boundary is the single place this removal could go wrong, so it is ruled at
  the level of the method rather than the class.** Every method of that class contains the word
  `verification` somewhere in a docblock and not one of them contains a verification branch. A
  removal driven by grep would delete the rotation engine outbound signing runs on. That is why
  Decision 3 lists members and what depends on each, rather than saying "keep `SecretStore`".
- **The migration ruling turns on the fact that a migration is a public artifact only once merged,
  and on the fact that rows are not schema.** The first would permit an in-place edit; the second
  forbids one, because no edit to a create-table migration deletes data. The second wins, so there
  is one new migration and the old one is left alone.
- **`down()` is written to be honest rather than symmetric.** A `down()` that restores two nullable
  columns and calls itself a reversal invites somebody to believe a rollback recovers configuration.
  It recovers a shape. Saying so in the docblock costs two sentences and prevents a bad assumption
  at exactly the moment somebody is under pressure.
- **The work being discarded was correct when it was built.** T16 through T25 implemented an approved
  criterion set against a ratified ADR, and they are being removed because the product's purpose
  changed, not because they were wrong. Naming them as built-and-removed rather than as gaps is what
  lets the Reviewer read the resulting diff as a deletion rather than as an omission.

## Impact

### Code — the complete change set

The complete change set is Decision 2's removal set, plus Decision A's seven-entry reduction of
`DeliveryUnit::STRIPPED_HEADERS`, plus Decision 4's migration. It is not restated here.

**Data model:** two columns dropped from `proxies`, and every `proxy_secrets` row of purpose
`verification` deleted. One new migration. **New dependency:** none. **Stack change:** none.
**New config key:** none. **Removed config key:** none.

**Tests.** One test worth adding rather than editing, because nothing currently covers it and it is
the property Decision A most needs pinned: **a request carrying `Authorization`, `Cookie` and a
provider signature header, dispatched to a destination that carries its own credential under the
default `Authorization` header name, must deliver the destination's credential and not the sender's,
must deliver the `Cookie` and the provider signature unchanged, and must emit exactly one
`Authorization` header.** That is Decision A and ADR-023 Decision 2's precedence rule in one
assertion, and it is the case that changed from impossible to ordinary.

### Documents

**Retired or superseded by this ADR:**

- **ADR-022** is **superseded in full**. It keeps its file, its Accepted status and its full text,
  and gains a sub-bullet under its Status line plus an inline pointer at Decision 4 recording that
  the construction it states is carried forward into ADR-026 Decision 5. It is not deleted, and no
  reference to it is removed from any document that cites it historically.
- **ADR-008**, **ADR-023** and **ADR-025** gain inline pointers at the passages named in
  § *Positions superseded*, plus a sub-bullet under each Status line. Their Decision, Alternatives,
  Reasoning and Impact sections are otherwise untouched and their statuses are unchanged. Those
  edits must be **pure insertions** — verifiable with `git diff --numstat` showing zero deletions —
  because an additive pointer is what they are.

**Amended by this ADR:**

- **`plan-10`** is mine and takes a **`## Revision A`**. Its § *Pointer to ADR-025* section is
  overtaken; § *Architecture A* (the whole inbound gate) is withdrawn; § *Architecture B*'s secret
  table loses its verification row; § *Architecture C*'s five-step composition loses step 2;
  § *Architecture E* loses Screens 1 and 4; § *Architecture H*'s inbound branch goes; § *Data Model*
  loses two of the three `proxies` columns; § *Validation*, § *API*, § *Services & Actions*,
  § *Risks* and § *Test strategy* each lose their verification entries; and § *Milestones* M6 is
  reduced to `SecretStore`, `RotationOverlap`, `ExpireProxySecrets` and the daily sweeper. The
  revision is written after this ADR is committed and does not reopen any Owner flag: flag 1's
  data-model approval is narrowed by an Owner ruling, not by me, and flag 3 approved ADR-022, which
  this ADR supersedes on the same authority.

**Routed to the Product Manager — an amendment to an approved PRD, which is theirs and not mine:**

**PRD-10 criteria that fall in full.** Each describes a capability that no longer exists:

- **AC23** — the closed two-scheme registry and the selection of a scheme.
- **AC24** — verification is optional and off by default. There is nothing to be optional about.
- **AC25** — rejection with HTTP 401 before capture, and its four negatives.
- **AC26** — verification secrets are stored, never generated by us, and write-only once saved.
- **AC27** — verification headers are never forwarded to destinations.
- **AC28** — configuring verification is gated by the existing proxy update permission.
- **AC46** — no analytics, counter or notification for rejected requests. Its subject is AC25's
  rejection; with no rejection there is nothing for the criterion to exclude. Recommended for
  withdrawal, flagged rather than asserted because its scope is the Product Manager's to read.
- **AC50** — the scheme list stays closed until an Owner decision opens it.
- **AC51** — `shared-secret`.
- **AC52** — `standard-webhooks` as an inbound scheme, and its five binding properties.
- **AC53** — the specification's timestamp and replay window, and that the tolerance is not
  member-configurable.

**PRD-10 criteria that are narrowed rather than withdrawn.** These are the ones an amendment is
most likely to over-remove, so each is named with what survives:

- **AC29** — the rotation overlap. It governs **both directions** today. Its inbound half falls: the
  opening clause "the inbound verification secret under either scheme", and the bullet "**Inbound,
  either secret verifies**". **Everything else stands and is load-bearing for signing**: the cap of
  two, the immediate discard of the oldest on a second rotation inside an overlap, the ruling-2a
  disclosure before the save, the fixed non-configurable 24 hours, ending an overlap early, the
  bullet "**Outbound, both are presented**", and the explicit exclusion of the destination
  credential. **AC29 must not be withdrawn.**
- **AC43** — inbound header forwarding is unchanged except for three added rules. AC27's strip is one
  of the three and it goes; AC38's credential precedence and AC64's signing-header precedence
  remain. The criterion also needs to record that ADR-008's fixed strip list is no longer "otherwise
  untouched": Decision A reduces it to ten entries.
- **AC55** — outbound signing uses "the same one AC52 defines for inbound". With AC52 withdrawn the
  cross-reference has no referent, so **AC55 must state the scheme itself** rather than pointing at
  a criterion that no longer exists. ADR-025 already routed an amendment to AC55's "same three
  headers" clause; this is a second, independent change to the same criterion and the two should be
  ruled together.
- **AC11** — its inbound clause (an undecryptable verification secret produces a 500) falls; its
  signing clause (proxy-wide all-or-none) stands untouched.
- **AC1, AC10 and AC44** — the at-rest floor, the key rule and the deferred key-rotation tooling each
  enumerate the secrets they cover, and the verification secret leaves those enumerations. The
  criteria themselves stand.

Also for the Product Manager: **PRD-10 § V2** records the Owner's ruling that established inbound
verification, and § *Consequences for approved documents* records several ratifications that rest on
it. Neither is edited here.

**Routed to the Designer — a `design-10` amendment, through the Product Manager:**

- **Screen 1** (the proxy form's Verification section) and **Screen 4** (the Show page's
  Verification card) are withdrawn in full.
- **Flow A** (configure a proxy's inbound verification), **Flow B** (replace a verification secret)
  and **Flow C** (view verification status and end a rotation overlap early) are withdrawn in full.
- **Screens 2, 3, 4b, 5, 6 and 7 and Flows D, E, F, G, H and I are unaffected.** Screen 3's
  credential subsection cites Screen 1 as its shape precedent and needs a new reference; the
  behaviour it describes does not change.
- The design gate's correction **B2**, which required the AC29 ruling-2a disclosure on **both**
  surfaces, now has one surface. Its signing half (Screen 6 state 4, Flow H step 2) stands. Its
  inbound half goes with Screen 1.

**Withdrawn entirely, and not amended, because it was never approved:**

*(Amended 2026-08-29 — Amendment A. This list originally held two withdrawn documents. `design-17`
is no longer one of them; it was re-based rather than withdrawn, and it now has its own entry below.
PRD-16's withdrawal is unchanged and its bullet stands exactly as written.)*

- **`docs/product/prd-16-configurable-inbound-verification.md`** — Draft, 54 acceptance criteria, a
  member-configurable template model for inbound verification. **It is withdrawn, not amended.**
  There is nothing left for it to configure: it describes how a member would express a verification
  construction for a capability the product no longer has. Withdrawing a Draft PRD is the Product
  Manager's act, and the substance of the withdrawal is not in question — the ADR records that the
  document's entire subject is removed.
- **`docs/architecture/prd-16-template-model-feasibility.md` is retained**, and is mine to rule on.
  It keeps its filename and its twenty-one provider findings, which are ADR-025 Decision 1's and
  Decision A's evidence base and are about verification **constructions**, not about the template
  model. Its Part on whether a bounded vocabulary can express those constructions is moot and should
  be marked so, and the numbered questions it routed to the Product Manager lapse with PRD-16. A
  short status note on the study is the only edit it needs.

**Re-based against this ADR rather than withdrawn — the Designer's act, recorded here.**

*(Added 2026-08-29 — Amendment A, replacing this document's original entry in the withdrawn list
above, which said `design-17` was withdrawn. It was not.)*

- **`design-17`**, the proxy-form restructure on branch `design/proxy-form-restructure`. This ADR
  originally recorded it as withdrawn alongside PRD-16, on the reasoning that it was a
  mid-revision, never-approved design written against PRD-16 and its feasibility study. The Project
  Owner directed on 2026-08-29 that it be re-based instead, and the Designer carried that out in
  commit `6c6bdb9` on that branch, which also carries `main` merged in at `a9d6ca2` and therefore
  contains the shipped item #10. **The document is alive, and its status stays Draft** — an
  unapproved proposal awaiting a Project Owner ruling, with nothing in it authorized. The branch is
  still not on this tree; this ADR reads it at `6c6bdb9` and edits nothing on it.
  - **What was deleted** is the PRD-16-dependent material, and only that: the "Webhook secret" copy
    table, `## The Inbound control — from two schemes to a template model`, and
    `## Custom-template entry UX`. The re-basing removed 578 lines against 294 added, taking the
    document from 823 lines to 539.
  - **What was kept, untouched,** is everything that never depended on PRD-16 and that answers the
    Owner's original "too jumbled and overwhelming" brief: the five-container grouping in pipeline
    order, the copy-rewrite pass for Details, Delivery, Sensitive fields and Destinations, and the
    form-wide `## Rule: form copy vs. tooltip vs. cut`. None of it turns on whether inbound
    verification exists.
  - **One rename follows Decision B.** The grouping proposal's container 2 is renamed from "Inbound"
    to "Response", because with Verification removed the container holds only the synchronous
    response. Five containers and the pipeline-order placement stand.
  - **Three of its four Open Questions are closed.** Questions 1 and 2 are ruled **moot rather than
    reopened**: each presupposed a live scheme choice on a Verification control that Decision B
    removes outright, which is a stronger outcome than either question's own "if PRD-16 is declined"
    contingency anticipated. Question 4 is closed because `design-16` will never be written.
  - **Question 3 stays open, routed to the Product Manager**, because it crosses into the approved
    `design-10`: whether renaming the form's "Verification" legend also renames the proxy Show
    page's card. The Designer's finding — that merged `main` has no "Verification" card on the Show
    page at all, `design-10`'s own amendment having withdrawn Screen 4 in full, leaving only
    "Signing" — is recorded there as a finding and not as a ruling on another role's document. That
    is the right boundary, and this ADR does not rule it either.
  - **The Owner-directed Principal Engineer technical sign-off on the design is ruled in
    § *Amendment A*, ruling A2.** The original entry said the gate lapsed with the document; with
    the document alive that no longer follows on its own, so it is ruled on its own merits there
    rather than inherited.

**`docs/status.md`** needs the item #10 row updated, a row for ADR-026, a row for the withdrawal of
PRD-16, and a row for `design-17` recording it as **re-based and still Draft — not withdrawn**.
*(Amended 2026-08-29 — Amendment A. The original sentence asked for rows "for the withdrawal of
PRD-16 and design-17"; half of that is no longer what happened.)* That is the Orchestrator's upkeep
and is not done here.

### The existing task plan — what stands, what narrows, what is wasted

`docs/tasks/sensitive-data-handling-tasks.md` is the Task Planner's document and is not edited here.
The rulings it needs are these.

**Complete, committed, and now removed.** These tasks built inbound verification and their output is
deleted by Decision B. The diff will show their work disappearing, and that is correct rather than a
regression: **T16** (`VerificationScheme`), **T17** (the two scheme handlers), **T18**
(`InboundVerifier`), **T19** (the `IngestController` gate), **T20** (validation and its persistence
wiring), **T21** (the verification overlap endpoint), **T22** (the `security` prop's `verification`
sub-object), **T23** (Screen 1), **T24** (Screen 4), **T25** (the inbound integration suite). Parts
of **T26** (the AC27 strip step inside `OutboundHeaders`) and **T27** (the resolver carrying
verification header names) go the same way; the rest of both stands.

**Complete and unaffected**: T13, T14 and T15 — `RotationOverlap`, `SecretStore` and the expiry
job with its daily sweeper — all of which outbound signing depends on.

**Pending, and now wasted in part:**

- **T45** — its `verification_secret` addition to `bootstrap/app.php`'s `dontFlash` list is wasted;
  the field will not exist. **Its other half stands and is the harder half**: the
  `failedValidation()` scrub of `destinations.*.credential_secret`, which exists because
  `Arr::forget()` has no wildcard support and the session driver is `database`.
- **T49** — its Flow A, B and C coverage is wasted, and its Acceptance Criterion requiring the AC29
  ruling-2a disclosure "present together on one finished screen pass" reduces to T43's signing
  surface alone. Its Implementation-Note sweep, its out-of-scope confirmation, its byte-identical
  re-runs and its Flow D through I walk all stand.
- **T51** — **superseded.** It removes five names from `STRIPPED_HEADERS`; Decision A removes seven.
  More importantly its stated safety premise — "the per-proxy verification-header strip inside
  `OutboundHeaders::build()` … is what keeps a member's own `shared-secret` value from leaving via
  this path" — **is void**, because that strip is deleted. A replacement task should carry Decision
  A's own reasoning: the pass-through is safe because no member-configurable verification header
  exists to leak.
- **T50** — **stands, unchanged in scope.** Its "explicitly not touched" list names inbound files
  that will no longer exist, which makes the caution moot rather than wrong; the rename itself is
  exactly as specified and its justification strengthens. See Decision 6, step 4.

**Unaffected and still owed:** T44 (moved later, scope unchanged), T46, T47 and T48. T48's sweep
narrows by one secret and its eager-loaded-relation case still applies to signing rows.

### Constrained, carried forward

- **The ingest token is the only authenticator on the ingest path.** No second factor is added
  without a new Owner ruling. A pipeline step, a middleware or a controller branch that inspects a
  request in order to decide whether to accept it is a review finding.
- **`DeliveryUnit::STRIPPED_HEADERS` is a transport-scoped constant.** An entry goes in only if
  forwarding it would make the request malformed or misrouted under a specification that can be
  cited. No entry is ever added because of what a header's value might be.
- **`OutboundHeaders` remains the only place an outbound header set is built**, and ADR-023
  Decision 2's precedence rule remains the only mechanism resolving a name collision.
- **`SecretStore` remains the single reader and writer of `proxy_secrets`.**
- **`App\Support\StandardWebhooks` stays whole**, including `verify()` and `TOLERANCE_SECONDS`,
  which the outbound signing suites depend on as their receiver-side oracle.
- **`proxy_secrets.purpose` stays `string(32)`** so a later purpose costs no migration, even though
  exactly one purpose remains.
- **No behaviour depends on header-name casing.** Matching is case-insensitive everywhere, as HTTP
  requires.
- **The ingest path never appears in an outbound header**, in any form, under any decision. `host`
  remaining stripped is what enforces it.

## Owner-approval flags (✋)

**None outstanding.** Both decisions in this ADR are the Project Owner's own ruling of 2026-08-28,
quoted verbatim in § *The product position this ADR renders*, and the Owner asked that it not be
taken back and forth. This document records the ruling and works out what it means; it does not put
it to them.

**The one item that would ordinarily be a flag is the data-model change, and it is approved by the
words of the ruling.** `CLAUDE.md` gates a data-model change affecting existing data on the Project
Owner, and this ADR drops two columns and deletes every stored verification secret. The Owner's
sentence — "*We are going to remove everything related to validating incoming webhooks that we
already added. **Columns, code, etc.***" — names columns explicitly. What the ruling directed is
enumerated precisely in Decision 4 so that the Owner can see what "columns" resolved to:

1. `proxies.verification_scheme` — dropped.
2. `proxies.verification_header_name` — dropped.
3. Every `proxy_secrets` row of purpose `verification` — deleted, unrecoverable, and not preserved
   anywhere.

**Explicitly *not* in the change set, verified item by item:** the `proxy_secrets` table, its
unique index and all nine of its columns; `proxies.sensitive_fields`; `destinations.
credential_header_name`, `credential_secret` and `credential_set_at`; `proxies.ingest_token` and
`ingest_token_hash`; every index on `proxies` and `destinations`; and every other table in the
schema. No backfill runs, and no default is written to any existing row.

**The one consequence the Owner should read as ruled rather than discovered** is recorded in
Decision A and not carried here as a question: **a forwarded `Authorization` or `Cookie` header
reaches every destination of the proxy.** It is stated in full at § *Decision A*, in the terms an
objection would use, and ruled as instructed.

---

## Amendment A (2026-08-29): `design-17` was re-based, not withdrawn

The Project Owner directed on 2026-08-29 that `design-17`, the proxy-form restructure, be re-based
against this ADR instead of withdrawn with PRD-16, and the Designer carried that out in commit
`6c6bdb9` on branch `design/proxy-form-restructure`. This ADR had recorded the document as
withdrawn. That entry is now false, and it is corrected in § *Impact → Documents*, where the
document's own entry now sits.

This amendment exists because a document this ADR declared dead is alive, and because one obligation
was recorded as lapsing with it. Three rulings follow from that, and nothing else in this ADR is
touched.

### A1. Ruling — the entry stands on its own, in neither list it might have joined

Neither existing list describes it honestly, so it gets its own entry.

The **"Amended by this ADR"** list holds `plan-10`, a document of mine to which this ADR directs a
specific revision. Nothing here directs anything about `design-17`: the re-basing was the Designer's
act on the Owner's direction, not a consequence of this ADR being worked out, and this ADR has no
authority over a design specification in any case. The **"Withdrawn entirely"** list is now about
PRD-16 alone. PRD-16's bullet is untouched; its withdrawal is not in question and nothing in this
amendment bears on it.

The separate entry also lets the record say the awkward thing plainly, which neither list would
allow. `design-17` is a never-approved proposal that lost roughly a third of its content and kept
the rest. It is not a clean amendment, because a third of it was deleted rather than revised. It is
not a withdrawal, because what remains is the substance the Owner commissioned in the first place.
Filing it as either would mislead a reader who has to decide later what standing the document has.

### A2. Ruling — the Principal Engineer technical sign-off stays lapsed, and only the Project Owner can reinstate it

The original entry said the gate "lapses with the document and no sign-off is owed." That reasoning
died with the withdrawal it rested on. The gate is therefore ruled again here, on its merits rather
than by inheritance.

**It stays lapsed. No Principal Engineer technical sign-off is owed on `design-17` as it now
stands, and I am not asking for one.** Three reasons:

1. **The material that would have needed an engineer's read is exactly the material that was
   deleted.** The gate attached to a document proposing a member-facing template model for
   expressing verification constructions, a custom-template entry UX, and an editable header-name
   field — a design with real technical shape, which is why an extra technical gate on it made
   sense. Decision B removed the capability all of that described, and the re-basing removed the
   design for it. What remains is container grouping, ordering and copy.
2. **What remains proposes no technical decision.** Every control it proposes reuses an
   already-shipped primitive; it proposes no data-model change, no API change and no dispatch-time
   behaviour change; and its own Handoff records its dependencies as "none, technical or otherwise."
   I have read the document at `6c6bdb9` and I agree with that characterisation. A sign-off on it
   would certify nothing that is not already true of the shipped form.
3. **The re-based document does not carry the gate.** Its Handoff routes to the Product Manager and
   names no Principal Engineer sign-off anywhere. Nothing is outstanding and no role is waiting on
   me.

**If the Project Owner wants the sign-off back, it is theirs to reinstate, and I will carry it.**
The gate was Owner-directed. I can rule that none is owed on the current text and decline to invent
one, but I cannot re-create an Owner's gate on their behalf any more than I could discharge one on
their behalf. This paragraph is here so that a later reader does not read A2 as the gate being
quietly dropped: it is ruled lapsed, on stated grounds, and one sentence from the Owner reverses it.

Two things this ruling does **not** say. It does not say `design-17` needs no approval — it needs
the Project Owner's ruling to be anything at all, and the design gate that `CLAUDE.md` delegates to
the Product Manager is unaffected by this amendment. And it does not offer the ordinary pipeline as
a substitute for the Owner's gate: if the proposal is accepted, the implementation is planned by me
in the normal way, which is engineering involvement by structure, not a gate discharged early.
Should the amendments to the approved specs turn out to have a technical shape the current text does
not show, that routes to me as a question document rather than reviving a gate.

### A3. Ruling — no decision in this ADR is affected

`design-17` is downstream of this ADR and was never an input to it. It appears here only in
§ *Impact → Documents*, which records what this ADR's decisions do to other documents; a consequence
is not a premise. Walked one by one, so that "considered, not overlooked" is checkable:

- **Decision A** — the outbound strip list reducing to `host`, `content-length` and the RFC 7230
  §6.1 hop-by-hop set. Unaffected. `design-17` says nothing about outbound headers.
- **Decision B** — the complete removal set for inbound verification. Unaffected. The re-based
  `design-17` is *consistent* with Decision B, deleting its Verification material and renaming the
  container that held it, but consistency is not influence: had the Designer withdrawn the document
  instead, not one word of Decision B would read differently.
- **Decisions 3, 4, 5 and 6** — the `SecretStore` boundary, the migration, the Standard Webhooks
  construction, and the sequencing and build order. Unaffected. None of them has a surface
  `design-17` touches, and the removal they specify is already built and merged.
- **§ *Positions superseded*** — unaffected. Every superseded position belongs to ADR-008, ADR-022,
  ADR-023 or ADR-025, and none of those documents cites `design-17`.
- **§ *Constrained, carried forward*** — unaffected. All eight constraints are code-level and hold
  exactly as written.
- **§ *Owner-approval flags (✋)*** — **still none outstanding.** The data-model change this ADR
  makes is approved by the words of the Owner's ruling, as recorded there. This amendment adds no
  flag: it corrects a record and rules a lapsed gate, and neither is a decision to put to the Owner.
- **§ *Evidence base*, and the retention of `prd-16-template-model-feasibility.md`** — unaffected.
  The study is still retained, for the reason already given.

The only thing that changes beyond `design-17`'s own entry is the `docs/status.md` sentence in
§ *Impact → Documents*, which asked the Orchestrator for a row recording a withdrawal that did not
happen. It is corrected in place. `docs/status.md` itself is the Orchestrator's upkeep and is not
edited here.
