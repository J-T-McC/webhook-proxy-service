> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M3 — Standard Webhooks primitive, no surface

## T7 — `App\Support\StandardWebhooks` (AC52, AC53, AC55; plan § Services & Actions, Technical ruling 6)
- **Description:** Pure class, no DB. Implements the Standard Webhooks specification in-house (no new
  Composer dependency — plan Technical ruling 6, Dependencies assessed and declined): `sign(string $id,
  int $timestamp, string $body, string $secret): string` (HMAC-SHA256 over `"<id>.<timestamp>.<body>"`,
  base64-encoded, not hex); `verify(string $id, int $timestamp, string $body, string
  $signatureHeaderValue, list<string> $secrets): bool` (parses a space-delimited list of `v1,<sig>`
  entries, skips any non-`v1` entry rather than failing, succeeds if **any** entry verifies against
  **any** secret in the live set, `hash_equals` for constant-time comparison); `TOLERANCE_SECONDS =
  300` (the specification's reference value, a single-sourced constant so member-facing copy at T23
  interpolates it rather than hand-typing "5 minutes"); a `whsec_`-prefixed-or-bare-base64 secret
  decoder, since the specification allows both.
- **Dependencies:** none
- **Files:** `app/Support/StandardWebhooks.php` (new)
- **Acceptance Criteria:**
  - A specification-computed signature verifies via `verify()`.
  - A multi-entry space-delimited `webhook-signature` value verifies when only the **second** entry
    matches.
  - A non-`v1` entry is skipped rather than causing a failure when a later entry matches.
  - A timestamp `TOLERANCE_SECONDS + 1` seconds either side of now is rejected by the tolerance check;
    one second inside is accepted (the tolerance check itself may live here or at the T17 scheme
    wrapper — state which in completion notes; either satisfies AC53 as long as it is
    single-sourced from this constant).
  - A `whsec_`-prefixed secret and a bare base64 secret both produce/verify the same signature.
  - Hex-encoded input where base64 is expected fails to verify (never silently accepted as a different
    encoding).
- **Testing:** `tests/Unit/Support/StandardWebhooksTest.php` (new) — specification-derived fixtures
  (hand-computed HMAC-SHA256/base64 vectors), the multi-entry-list case, the non-`v1`-skip case, the
  tolerance boundary cases, the `whsec_`/bare-secret equivalence, and the hex-rejection case.
- **Completion notes:** Done. `App\Support\StandardWebhooks`: `sign()` (`hash_hmac('sha256', "<id>.
  <timestamp>.<body>", $secret, true)`, base64-encoded), `verify()` (space-delimited `v1,<sig>` entry
  parsing via `preg_split('/\s+/', ...)`, skips any non-`v1` entry, `hash_equals` against every
  secret in the live set), `TOLERANCE_SECONDS = 300`, and a `whsec_`-or-bare-base64 secret decoder
  (strict-mode `base64_decode`, empty string on a decode failure so a malformed secret simply never
  matches rather than throwing — no exception type is named anywhere in this task's own Acceptance
  Criteria).

  **Tolerance check placed inside `verify()` itself**, not deferred to the T17 scheme wrapper: this
  task's own Testing section requires `tests/Unit/Support/StandardWebhooksTest.php` to cover the
  tolerance boundary directly, which only makes sense against code this class owns.

  One consequence, noted rather than worked around: the Standard Webhooks specification's own
  published fixed-timestamp reference vector (2021, from the specification's `svix-webhooks`
  verification test suite) cannot be run through `verify()` today, since `verify()` now rejects
  anything outside `TOLERANCE_SECONDS` of the real wall clock — that vector is over four years stale
  relative to this sail run. Used it instead to pin `sign()`'s HMAC/base64 construction directly
  (independently re-derived via a standalone `php -r` one-liner before writing the test, confirming
  the published vector rather than trusting memory of it), which is the part of the specification
  that fixture exists to prove; the `verify()` round-trip tests use a current timestamp with a
  signature computed by the already-pinned `sign()`. AC53 is unaffected — the tolerance is still
  single-sourced from `TOLERANCE_SECONDS` and enforced unconditionally.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter StandardWebhooksTest`
  green (10 tests, 10 assertions); full-suite run deferred to the end of this batch per the task
  list's own working rules.

---
