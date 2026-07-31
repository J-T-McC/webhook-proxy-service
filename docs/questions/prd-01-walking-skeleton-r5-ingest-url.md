# Question R5: Ingest-URL generation & security

- **Status:** Resolved (2026-07-30, Principal Engineer) — see Answer; formalised in ADR-006, pending Project Owner approval
- **Raised by:** Product Manager
- **Owner (must answer):** Principal Engineer *(technical)*
- **Raised:** 2026-07-30
- **Gates:** PRD-01 — Walking skeleton: ingest -> fan-out delivery
- **Source:** Roadmap Open Question R5 (`docs/product/roadmap.md`)

## Context
Item #1 gives each proxy a single ingest URL that a team member hands to an
upstream sender. Anyone who can reach that URL can post payloads that will be
fanned out to all of the proxy's destinations. How the URL is generated and
protected is therefore a security-relevant technical decision, not a product one.

## Question
How are per-proxy ingest URLs created and protected? Specifically:

1. How is each ingest URL made **unique** per proxy?
2. How is an ingest URL made **hard to guess / secret** (e.g. sufficient entropy
   in an opaque token), so an attacker cannot enumerate or forge another team's
   ingest endpoint?
3. Is any additional authentication/verification of the incoming request in
   scope for item #1, or does secrecy of the URL alone suffice for the walking
   skeleton? *(Note: verification-token support is item #11 / Vision Open
   Question V2 — this question only asks whether item #1 needs any minimum.)*

## Impact if unresolved
The acceptance criteria in PRD-01 covering ingest-URL **uniqueness** and
**secrecy/unguessability** cannot be made objectively testable until this is
answered. This question gates item #1 implementation.

## Answer
Resolved by the Principal Engineer on 2026-07-30. Full rationale and alternatives
in `docs/architecture/adr-006-ingest-url-generation-security.md`; summary below.

**Ingest URL form.** `https://<ingest-host>/ingest/{ingest_token}` — opaque token
only; no team id, proxy id, or other identifier embedded.

1. **Uniqueness.** Each proxy gets an `ingest_token` = **32 bytes (256 bits) from a
   CSPRNG** (`random_bytes`), URL-safe encoded. Uniqueness is enforced by a
   **UNIQUE index** on the token's deterministic lookup hash, with
   regenerate-on-collision. Uniqueness derives from the token, not the proxy id.

2. **Unguessable / secret.** 256 bits of CSPRNG entropy ⇒ not enumerable or
   forgeable. The token is stored as a deterministic **`ingest_token_hash`
   (SHA-256, indexed)** for O(1) request-time lookup, plus the token itself in an
   **`encrypted`** column so team members can view the URL (AC4). Inbound requests
   are resolved by hashing the presented token. Unknown/invalid token ⇒ **`404`**
   (no existence disclosure). Token rotation is supported by the model (no #1 UI
   required). A plaintext-unique-index is an acceptable simpler fallback if the
   Owner prefers, but hash-lookup + encrypted-at-rest is recommended.

3. **Additional auth at item #1.** **No.** For the walking skeleton, secrecy of the
   high-entropy URL over **TLS-only** transport is the sole authenticator; no
   signature/verification-token check is in scope at #1 (that is item #10 / V2,
   which attaches as a front-of-pipeline `VerifyStep` without changing URL
   generation). Baseline non-blocking hardening: request body-size cap, basic
   per-token rate limiting; the ingest route is public (no session auth) and
   CSRF-exempt.

**Effect on PRD-01 AC12.** "Unique" = DB-unique 256-bit token; "not guessable" =
256-bit CSPRNG entropy, no identifiers in path, `404` on miss — now objectively
testable.

**Residual points for the Project Owner (non-blocking):** whether TLS is enforced
at the app layer vs. the load balancer, and whether the simpler plaintext-token
fallback is acceptable. Both are Owner preferences on the recommended design; see
ADR-006.
