# ADR-006: Ingest-URL generation and security (resolves R5)

- **Status:** Proposed
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** walking-skeleton (Roadmap #1 / PRD-01 AC12); resolves open question R5

## Question
How are per-proxy ingest URLs generated, kept unique, and protected (secrecy /
verification) for item #1? See `docs/questions/prd-01-walking-skeleton-r5-ingest-url.md`.

## Decision
1. **Form.** Each proxy's ingest URL is
   `https://<ingest-host>/ingest/{ingest_token}`, where `ingest_token` is an
   opaque, high-entropy secret. No team id, proxy id, or other identifier is
   embedded in the path.
2. **Generation & uniqueness.** `ingest_token` is **32 bytes (256 bits) from a
   CSPRNG** (`random_bytes`), URL-safe-base64/base62 encoded. A **UNIQUE index**
   on its deterministic lookup hash enforces uniqueness at the database;
   regenerate-on-collision (astronomically unlikely). Uniqueness is guaranteed by
   the token, not by proxy id.
3. **Secrecy / unguessability.** 256 bits of CSPRNG entropy makes the token
   non-enumerable and non-forgeable. **Storage:** persist a deterministic
   `ingest_token_hash` (SHA-256, indexed) for O(1) request-time lookup, and store
   the token itself in an `encrypted` column for later display to team members
   (AC4 requires the URL be viewable). Inbound resolution hashes the presented
   token and looks up by hash. Unknown/invalid token ⇒ **`404`** (no existence
   disclosure). Token **rotation** (regenerate ⇒ old URL invalid) is supported by
   the model though no UI is required at #1.
4. **Transport / additional auth at #1.** **TLS-only** (HTTPS); non-TLS ingest is
   rejected. For the walking skeleton, **secrecy of the high-entropy URL is the
   sole authenticator — no signature/verification-token check is in scope at #1**
   (that is #10 / V2). Baseline non-blocking hardening: request body-size cap,
   basic per-token rate limiting, and the ingest route is public (no session auth)
   and CSRF-exempt because callers are external systems.

## Alternatives
- **Sequential id / UUIDv1 / plaintext-only unique token** — enumerable or leaks ordering/host state, or (plaintext) stores a bearer secret in the clear; rejected. (Plaintext-unique-index is a *simpler acceptable MVP fallback* if the Owner prefers, but hash-lookup + encrypted-at-rest is recommended for defence-in-depth.)
- **Require a signature/verification token at #1** — that is item #10 (gated by V2 token standards); out of scope for the walking skeleton per the R5 question itself; rejected for #1.
- **Store hash only, show token once** — breaks AC4 (URL must be viewable any time); rejected.

## Reasoning
- R5 asks for uniqueness, unguessability, and whether any additional auth is
  needed at #1. 256-bit CSPRNG tokens over TLS are the standard unguessable-URL
  pattern; hashing for lookup + encrypting for display keeps the bearer secret out
  of the clear while satisfying AC4's "view the ingest URL."
- Deferring verification tokens to #10/V2 matches the roadmap; item #1's threat
  model (an unauthenticated external POST fanned out to destinations) is
  adequately covered by an unguessable secret URL plus TLS and body-size limits.

## Impact
- **Resolves** PRD-01 AC12: "unique" = DB-unique 256-bit token; "not guessable" =
  256-bit CSPRNG entropy, no identifiers in the path, `404` on miss.
- **Easier:** #10 verification tokens attach as a `VerifyStep` at the front of the
  pipeline (ADR-001) without changing URL generation.
- **Constrained / needs Owner confirmation:** whether an item #1 ingest **must** be
  rejected without TLS at the app layer vs. terminated at the load balancer, and
  whether the simpler plaintext-token fallback is acceptable, are the only points
  left for Owner preference; both are non-blocking for the recommended design.
