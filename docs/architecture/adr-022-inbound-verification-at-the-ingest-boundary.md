# ADR-022: Inbound verification at the ingest boundary — a closed scheme registry evaluated before capture

- **Status:** **Accepted — Project Owner, 2026-08-27.** This ADR was **Owner-approval flag 3**
  of `docs/plans/plan-10-sensitive-data-handling.md`. The *behaviour* was already ratified — the Project
  Owner settled roadmap open question **V2** directly on 2026-08-27 and ratified it by approving
  PRD-10 (AC23–AC29, AC50–AC53). What is not ratified is the **seam**: where verification runs, and
  the shape a third scheme would be added to. AC50 makes adding a scheme a Project Owner decision
  each time, so the Owner should ratify the structure that decision will be taken against.
  - **SUPERSEDED IN FULL by `adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`
    (Accepted, Project Owner, 2026-08-28).** Inbound verification is removed from the product — not
    disabled, not deprecated — on the Owner's ruling that this service is a fan-out relay and does
    not validate incoming webhooks. The scheme registry, both handlers, the `InboundVerifier` gate,
    the 401 rejection path, the `proxies.verification_scheme` and `proxies.verification_header_name`
    columns, every stored `verification`-purpose secret, and the inbound verification surfaces are
    all removed. **This document keeps its file, its Accepted status and its full text as the record
    of what was built and why.** One passage is carried forward rather than retired — see the
    pointer at Decision 4 below.
- **Author:** Principal Engineer
- **Date:** 2026-08-27
- **Feature:** prd-10-sensitive-data-handling (AC23, AC24, AC25, AC26, AC27, AC28, AC29, AC46,
  AC50, AC51, AC52, AC53)
- **Companions:** ADR-021 (how the verification secret and its rotation slots are stored) ·
  ADR-023 (the outbound side, which strips the verification headers under AC27 and reuses this
  ADR's signature primitive for signing) · ADR-006 (the ingest URL — the first factor this is the
  second to) · ADR-010 (raw capture — the bytes verification runs over, and the write this gate
  precedes) · ADR-004 (the upstream response this gate must not serve)
- **Normative source:** the **Standard Webhooks specification**, `standardwebhooks.com`. AC52 makes
  it normative and #10 defines no variant of it. Where this ADR states a property of the scheme, it
  states it so a Reviewer can check it against the specification rather than against the code.
- **Supersedes:** nothing.
- **Storage:** the verification secrets live as `proxy_secrets` rows of purpose `verification`,
  under **ADR-021 Decision 2** (the Project Owner's mid-flight ruling A of 2026-08-27). This ADR
  reads them through `SecretStore`'s live set and never touches the table directly.

## Question

PRD-10 requires that a proxy may demand an incoming request prove itself under one of exactly two
named schemes, and that a failure is rejected **with HTTP 401, before capture, with no
`webhook_events` row, no delivery, no dispatch, and without serving the proxy's own configured
response** (AC25). Four things follow that no upstream artifact decides:

1. **Where does the check run?** The candidate seams are route middleware, the ingest controller,
   and a pipeline step. Two of the three are wrong for reasons the project has already learned once.
2. **What is the shape of the closed scheme list**, given AC50 says the list stays closed until an
   Owner decision opens it and names the vendor schemes that are *not* in it?
3. **How does the two-secret overlap (AC29) compose with verification**, and does which secret
   matched leave any trace?
4. **What exactly is `standard-webhooks` here** — headers, signed string, encoding, key handling,
   list semantics, tolerance — stated precisely enough that an implementer does not reach for a
   library's behaviour as the definition.

## Decision

### (1) Verification runs inside `IngestController`, after the proxy is resolved and before `WebhookEventCapture` — not middleware, not a pipeline step.

The ingest hot path becomes:

```
resolve proxy by SHA-256 token hash  (ADR-006, unchanged)
  → read method / headers / raw body (unchanged)
  → VERIFY                            (new — this ADR)
  → capture + fifo_dispatches row     (ADR-010/011, unchanged, inside one transaction)
  → ResponseResolver::resolve()       (ADR-004, unchanged)
  → dispatch by reference             (ADR-005/011/020, unchanged)
  → return the configured response
```

**Not a pipeline step.** `ProcessIngestedWebhook` is dispatched *after* the response has been
returned (ADR-005, and asynchronously since #4), so a pipeline step runs too late to withhold
anything — the 2xx has already gone back to the sender and the event has already been captured.
This is the same reasoning ADR-010 used to move raw capture out of `PipelineFactory`'s
enhanced-only `CaptureRawStep` and into a synchronous pre-dispatch step: **work that must precede
the upstream response cannot live in the pipeline.**

**Not route middleware.** Middleware runs before route-model binding and does not have the proxy;
resolving it there would duplicate the `ingest_token_hash` lookup — the one query the ingest path
is built around — or force the resolution into middleware and the controller into reading it back
out of the request. `EnsureIngestIsSecure` and `EnforceIngestBodyLimit` are legitimately middleware
because neither needs the proxy. This one does.

**In the controller, before capture,** AC25's four negatives are structural rather than enforced:
no `webhook_events` row exists because the row is written after this point; no delivery and no
dispatch exist because both are downstream of capture; and the proxy's configured response is not
served because `ResponseResolver::resolve()` is not reached. The 401 is returned directly from the
controller with a fixed, non-configurable body.

### (2) `App\Enums\VerificationScheme` is the closed list; `App\Services\InboundVerifier` is the single resolver; one class implements each scheme.

```
VerificationScheme (string-backed): StandardWebhooks = 'standard-webhooks'
                                    SharedSecret     = 'shared-secret'
```

`proxies.verification_scheme` is NULL for "not required" — there is no `none` case, because a case
would be a scheme that verifies nothing and every consumer would have to remember to special-case
it. NULL is the absence, and `InboundVerifier` is the **resolution-time gate** in ADR-018 Decision
1's sense: it establishes the scheme before asking `SecretStore` for anything, and returns "not
required" otherwise. Nothing else in the codebase reads `verification_scheme` behaviourally — the
same single-reader discipline `RetryPolicy` and `StoredPayloadLookup` hold. **The scheme staying a
column on `proxies` is what makes this cheap:** a proxy with verification off never queries
`proxy_secrets` at all, so AC24's "behaves exactly as it does today" holds at the query-count level
(ADR-021 § Impact).

Each case maps to one `VerificationSchemeHandler` implementation
(`SharedSecretScheme`, `StandardWebhooksScheme`) exposing one method:
`verify(Request $request, string $rawBody, Proxy $proxy, string $secret): bool`, plus a
value-free `FailureReason` on rejection. Adding a scheme is: one enum case, one handler, one entry
in the map, one option in the form's closed select — **and an Owner decision (AC50)**. That
cheapness in structure is deliberate and is exactly why the *decision* is not: the header name is
the easy part, and what differs per vendor is how the signed string is constructed (PRD-10 § V2,
the Owner's ground 2).

The list is closed in the type system and in validation. There is no free-form configuration: no
member-composed signed string, no member-chosen digest or encoding, no member-defined header set
beyond `shared-secret`'s one header name (AC23).

### (3) Every live secret verifies; which one matched leaves no trace.

`InboundVerifier` asks `SecretStore` for the proxy's **live set** of `verification` secrets —
`expires_at IS NULL OR expires_at > NOW()`, current first (ADR-021 Decision 2) — and loops it. A
request that verifies against **any** member is accepted, and **which one matched has no other
effect** (AC29). Nothing records it: no column, no log field, no counter. Recording it would be a
per-request fact about a secret, and it would tempt a future "your sender is still using the old
secret" surface, which is AC46's excluded territory and is designed nowhere.

Looping the set rather than checking two named slots is what makes AC29's cap a property of the
write path alone (ADR-021 Decision 4) — **this ADR's read path assumes no number**, so if the
Product Manager ever raises the cap, nothing here changes.

**A dormant secret cannot verify anything.** The scheme gate is evaluated first, so a proxy whose
scheme is NULL never reads a secret at all (ADR-021 Decision 5).

### (4) `standard-webhooks`, stated precisely.

> **[Decision 4 — CARRIED FORWARD into `adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`
> Decision 5 (Accepted, Project Owner, 2026-08-28), which is now its normative record.]** The
> inbound scheme this decision defines is removed with the rest of this ADR. **The construction it
> states is not**: outbound signing computes the same signed content, under the same algorithm and
> encoding, through the same `App\Support\StandardWebhooks` primitive, and ADR-023 cites this
> decision as the one place the construction is written down. ADR-026 Decision 5 restates it in
> full so that citation resolves to a live document. The inbound-only elements — the three request
> header names this decision reads, and the tolerance as a *rejection rule applied to an incoming
> request* — go with the capability; `TOLERANCE_SECONDS` itself survives as `verify()`'s own replay
> window, which the outbound signing suites use as their receiver-side oracle.

Everything below is the specification's, not the product's. AC52 and AC53 bind it; this ADR records
it so an implementer does not have to infer it from a library.

| Element | Value |
|---|---|
| Headers required on the request | `webhook-id`, `webhook-timestamp`, `webhook-signature`. Any missing or malformed one fails verification (AC25). Matched case-insensitively, which is HTTP's rule and Laravel's `Request::header()` behaviour. |
| Signed content | `<webhook-id>.<webhook-timestamp>.<raw body>` — literal dots, no separator escaping, the body exactly as received. |
| Algorithm | `HMAC-SHA256`. |
| Encoding | **base64**, not hex. |
| `webhook-signature` value | a **space-delimited list** of entries, each `v1,<base64 signature>`. Verification **loops the list** and succeeds if **any** entry verifies; it never reads only the first. Entries whose version prefix is not `v1` are skipped, not treated as failures. |
| Key material | the stored secret with an optional leading `whsec_` stripped, then **base64-decoded**. A secret that does not decode is a `SecretUnavailable` failure (ADR-021 Decision 6), not a verification failure. |
| Comparison | constant-time (`hash_equals`), always, on every entry. |
| Timestamp tolerance | `App\Support\StandardWebhooks::TOLERANCE_SECONDS = 300` — a class constant, **not config**. AC53: the tolerance is the specification's and is not member-configurable, and #10 invents no value of its own; an env key would be the product owning a number the specification owns. The constant carries a comment citing the specification and the date it was read, so a later change is a deliberate edit against the source. Applied as an absolute difference either side of `now()`. |
| Body | the **raw request body exactly as received** — `$request->getContent()`, read once in the controller and passed to the verifier and then to capture. Never a re-encoded, parsed or normalised form. |

**This is why #8 and #9 have no bearing on inbound verification** (PRD-10 § V2's correction of the
superseded ruling's ground 4): verification runs on bytes that exist before any transform seam,
and both of those items operate strictly downstream of capture.

### (5) Rejection: HTTP 401, a fixed body, a reason code in the log, and nothing else.

The response is `401` with a fixed, non-configurable, non-disclosing body (a short plain-text
string; it names no scheme, no header, no reason). The proxy's user-defined response (PRD-03,
ADR-004) is **not** used — AC25's load-bearing clause: returning the configured success response
to an unverified sender would make verification decorative.

One log line is written, at `info`, with **identifiers and a reason code only**:
`ingest.verification_failed` carrying `team_id`, `proxy_id`, `scheme` and one of
`missing_header`, `malformed_header`, `timestamp_out_of_tolerance`, `signature_mismatch`,
`secret_mismatch`. **Never** the header value, the body, any part of the secret, or the computed
signature (AC8: a log line may name identifiers and rule names, never a value). This is an operator
diagnostic, not a member-facing surface, and it does not touch AC46 — no event record, no counter,
no analytics figure and no notification is created.

### (6) Nothing re-verifies. Replay and retry are unaffected.

Verification is a property of an inbound HTTP request. A **retry** re-sends recorded bytes to a
destination (ADR-015) and a **replay** re-runs the pipeline from the raw capture (ADR-017); neither
is an inbound request and neither has a `webhook-signature` to check. A captured event is by
construction one that already passed verification, so re-checking would be checking our own storage
against itself. Stated because "verify on replay too" is a plausible-sounding change that would
break every replay after a rotation.

### (7) AC27's strip is per proxy, and it lives on the outbound side.

Under either scheme the verification headers must never reach a destination — a member's own secret
under `shared-secret`, and an inbound signature a destination would try and fail to verify against
**our** signing secret under `standard-webhooks`. The strip is a function of the proxy's
verification configuration, so it cannot be a constant; ADR-023 Decision 1 defines where it is
applied and how it composes with ADR-008's fixed list.

## Alternatives

- **Route middleware.** Rejected: it does not have the proxy, and getting it there means either a
  second `ingest_token_hash` lookup on the hottest path in the system or moving proxy resolution out
  of the controller for one caller's benefit. See Decision 1.
- **A pipeline step.** Rejected outright: the pipeline runs after the response and after capture, so
  every one of AC25's four negatives would already be false by the time the step ran. This is the
  ADR-010 lesson repeated.
- **Verify after capture, then delete the row.** Rejected: AC25 says no row is created, not that one
  is created and removed; it would also make an unverified sender able to drive writes, which is the
  denial-of-service shape the check exists to reduce.
- **Free-form verification configuration** (member-chosen digest, encoding, signed-string template).
  Rejected by AC23 and AC50, and by the Owner's own ground 2 in PRD-10 § V2: it converts a bounded
  piece of work into a per-vendor programme wearing a configuration form.
- **A `none` enum case instead of NULL.** Rejected: a case that verifies nothing is a state every
  consumer must special-case; NULL is the absence and the resolver is the only reader.
- **Adopt the official `standard-webhooks` PHP package.** Rejected, and worth recording because it
  is the obvious move. The verification surface is `hash_hmac` + `base64_decode` + `hash_equals` +
  a timestamp comparison + a space-split — small enough that the wrapper this feature would need
  around it (two simultaneously honoured secrets under AC29, our own failure-reason codes, our own
  fail-loud semantics under AC11) is comparable in size to the thing wrapped. Against that:
  `docs/stack/stack.md` records that **this repository has no Composer dependency scanning at all**
  — Dependabot covers `github-actions` only — so a package on the authentication path receives no
  automated vulnerability signal. AC52 also makes the *specification* normative, not any
  implementation of it, which is precisely the framing under which a hand implementation is
  checkable against a written source. **Not rejected on merit**; if the Owner prefers the package,
  it is a new-dependency gate and this bullet is where the assessment already is.
- **Record which secret matched** (current vs previous). Rejected: see Decision 3.
- **Rate-limit or lock out repeated verification failures.** Rejected as out of scope — the existing
  per-token `throttle:ingest` applies unchanged, and no criterion asks for more. Named so its
  absence is deliberate.
- **Return 403, or 400, on a verification failure.** Rejected: AC25 fixes 401.

## Reasoning

- **Placement is the whole decision.** Everything else in this ADR is the specification or the PRD;
  where the check runs is the part that is ours, is hard to move later, and determines whether
  AC25's four negatives are guaranteed or merely intended. The project has already paid once for
  putting must-precede-the-response work in the pipeline (ADR-010), which is why the reasoning is
  restated rather than assumed.
- **The closed list is a type, not a policy document.** AC50 will be tested the first time a real
  integration wants `github` or `stripe`, and the useful thing to have at that moment is a seam so
  small that the conversation is entirely about whether to open the list, never about how much work
  it is.
- **Tolerance as a constant rather than config is the difference between "the specification's value"
  and "our value that happens to match".** AC53 draws exactly that line, and an env key would put
  it on the wrong side.
- **The reason-code log is the only debugging affordance this feature has**, and PRD-10 says so
  bluntly: AC46 ships no analytics, counter or notification for rejections, and UX Direction point 7
  calls the resulting silence a real cost. An operator-side reason code costs nothing, breaches no
  criterion, and is the difference between "my sender is rejected" being diagnosable in one grep and
  not at all.

## Impact

- **Ingest hot path gains one branch and, under `standard-webhooks`, one HMAC over the raw body.**
  For a proxy with no verification configured the added cost is a single NULL check on a column of a
  row already in memory — AC24's "behaves exactly as it does today" is true to that precision. For a
  verified proxy the HMAC is O(body size) and runs **before** the upstream response, so it is inside
  the sender's latency budget. `INGEST_MAX_BODY_BYTES` defaults to 50 MiB, which makes the worst case
  a 50 MiB HMAC on the request thread. **AC47 asserts no numeric target and this ADR asserts none**;
  the cost is named here rather than discovered, and `plan-10` § *Risks* carries it.
- **Security-sensitive (Owner-gated ✋):** this is the product's first authentication mechanism for
  inbound traffic. The gate the Owner is asked for is the seam and the closed registry, not the
  schemes themselves, which V2 already settled.
- **Data-model:** none of its own. `proxies.verification_scheme`,
  `proxies.verification_header_name` and the `proxy_secrets` rows of purpose `verification` are
  ADR-021's and are gated in `plan-10` § *Data Model*.
- **Easier:** a third scheme is one enum case, one handler and one select option. `standard-webhooks`
  signature construction is shared with ADR-023's outbound signing through one primitive
  (`App\Support\StandardWebhooks`), so AC55's "one implementation serves both directions" is
  structural.
- **Constrained:**
  - **`InboundVerifier` is the only reader of `proxies.verification_scheme`**, and the only
    consumer of `SecretStore`'s `verification` live set. A second reader of either is a review
    finding.
  - **The raw body must be read exactly once** in `IngestController` and passed to both the verifier
    and `WebhookEventCapture`. Re-reading or re-encoding it between the two would make the bytes
    verified and the bytes stored different things.
  - **Adding a scheme is a Project Owner decision (AC50)** — not a design choice, not a Product
    Manager call, and not something a later item may absorb quietly.
  - **Never re-verify on retry or replay** (Decision 6).
- **Within stack:** PHP's `hash_hmac`/`hash_equals`/`base64_decode`, Laravel routing and Eloquent.
  **No new dependency, no stack change.**
