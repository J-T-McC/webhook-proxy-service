> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M6 — `SecretStore` and inbound verification

## T13 — `App\Support\RotationOverlap` (AC29; plan § Services & Actions)
- **Description:** Pure class. `HOURS = 24`, a class constant, not configurable anywhere (AC29's fixed
  window).
- **Dependencies:** none
- **Files:** `app/Support/RotationOverlap.php` (new)
- **Acceptance Criteria:** `RotationOverlap::HOURS === 24`; the constant is `final`/not overridable at
  runtime.
- **Testing:** trivial — a one-assertion test is sufficient, or fold into T14's suite if this class has
  no independent behaviour to test.
- **Completion notes:** Done. `App\Support\RotationOverlap::HOURS` is a `final public const int` = 24,
  matching `App\Support\StandardWebhooks::TOLERANCE_SECONDS`'s existing not-config precedent. Kept as
  its own small test file (`tests/Unit/Support/RotationOverlapTest.php`) rather than folded into T14's
  suite, for traceability to this task number. Two tests: the value, and a reflection assertion
  (`ReflectionClassConstant::isFinal()`) that it cannot be overridden at runtime.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter RotationOverlapTest`
  green (2 tests, 2 assertions); full-suite run deferred to the end of this batch (T13-T19).

## T14 — `App\Services\SecretStore` (AC10, AC11, AC26, AC29, AC33-exclusion, AC57, AC58; plan Technical rulings 5, 14; ADR-021 Decisions 3, 5, 6.1)
- **Description:** **The single reader and writer of `proxy_secrets`.** `liveFor(Proxy $proxy,
  SecretPurpose $purpose): list<string>` — the live set, current first, `expires_at IS NULL OR
  expires_at > now()`; throws `SecretUnavailableException` if any live row fails to decrypt (fail-loud,
  never dropped from the set — AC11: a partial signature list is indistinguishable from a completed
  rotation). `replace(Proxy $proxy, SecretPurpose $purpose, string $newValue): void` — deletes any
  already-superseded row before demoting the current one to `expires_at = now() +
  RotationOverlap::HOURS` hours, then inserts the new current row; enforces AC29's cap of two by
  construction (at most one current + one superseded-not-yet-expired at any instant). `generate(Proxy
  $proxy, SecretPurpose $purpose): string` — for the signing secret only (AC56): generates a
  `whsec_`-prefixed base64 value and calls through the same `replace()` path. `endOverlap(Proxy $proxy,
  SecretPurpose $purpose): void` — immediately expires the superseded row. `disable(Proxy $proxy,
  SecretPurpose $purpose): void` — deletes every row for that purpose (ADR-021 Decision 5 — used for
  disabling signing; verification's "Not required" does **not** call this, per the dormant-secret
  rule below). `App\Exceptions\SecretUnavailableException` (new) — fixed, value-free message.
- **Dependencies:** T2, T13
- **Files:** `app/Services/SecretStore.php` (new), `app/Exceptions/SecretUnavailableException.php`
  (new)
- **Acceptance Criteria:**
  - **R7:** three consecutive `replace()` calls for the same `(proxy, purpose)` leave **exactly two**
    rows in `proxy_secrets` for that purpose — never three, even briefly.
  - The `is_current IS NOT NULL ⟺ expires_at IS NULL` invariant holds after every `SecretStore`
    operation (`replace`, `generate`, `endOverlap`, `disable`).
  - During an overlap, `liveFor()` returns both secrets, current first; after the overlap's
    `expires_at` passes, `liveFor()` returns only the current one, **with no sweeper run** (liveness is
    a property of the data, not of the job/sweeper — plan § Architecture B).
  - A second `replace()` inside an already-running overlap discards the oldest secret **immediately**
    (its `expires_at` moves to the past, or the row is deleted outright — state which; either satisfies
    "exist," not merely "are honoured," per AC29).
  - `endOverlap()` is idempotent — calling it twice, or when no overlap is running, is a no-op.
  - A row whose `value` cannot be decrypted (simulate via a corrupted ciphertext fixture) causes
    `liveFor()` to throw `SecretUnavailableException` rather than silently excluding that row from the
    returned list.
  - `disable()` deletes every row for that purpose; a subsequent `generate()`/`replace()` produces a
    value that is **not** the previously disabled one (ADR-021 Decision 5's "re-enabling always
    generates afresh").
  - The unique index (T1) is never violated by any sequence of `SecretStore` calls, including
    concurrent-looking rapid rotation.
- **Testing:** `tests/Unit/Services/SecretStoreTest.php` (new) — one test per bullet above, including a
  three-rotation R7 case, the invariant-holds-after-every-operation sweep, the corrupted-ciphertext
  fail-loud case, and the idempotent-end-overlap case.
- **Completion notes:** Done. `App\Services\SecretStore` implements all five operations exactly as
  described — `liveFor()`, `replace()`, `generate()`, `endOverlap()`, `disable()` — with `replace()`'s
  delete-superseded / demote-current / insert-new sequence run inside one `DB::transaction()`, so the
  two-row cap is never briefly exceeded even mid-write. `App\Exceptions\SecretUnavailableException`
  takes a `SecretPurpose` and produces a fixed message naming only the purpose ("The verification secret
  could not be decrypted.") — never a proxy/team id, never any part of the value.

  **One implementation choice PHPStan forced, not a design change:** `liveFor()`'s decrypt step calls
  `Crypt::decryptString((string) $secret->getRawOriginal('value'))` directly rather than reading
  `$secret->value` (which triggers the identical decrypt inside Eloquent's `encrypted` cast, but
  invisibly to static analysis — Larastan cannot see a cast-triggered exception through a plain property
  access, so a `catch (DecryptException)` around `$secret->value` was flagged as dead code, correctly:
  PHPStan had no way to know it could ever throw). Calling `Crypt::decryptString()` directly is
  functionally identical to what the cast does internally and makes the exception path real and visible
  to the analyzer rather than papering over it with a suppression comment.

  **The delayed-expiry dispatch named in T15's own description ("Dispatched with a delay from
  `SecretStore::replace()`/`generate()`") is deliberately not wired in this commit.** `App\Actions\
  ExpireProxySecrets` does not exist until T15, and T14's own Dependencies line (T2, T13 only) and Files
  list (no `ExpireProxySecrets` reference) confirm T14 must stand alone. Nothing in T14's own Acceptance
  Criteria needs the job — expiry is correct by data alone (`ProxySecret::live()`'s predicate), which is
  exactly the "no mechanism needed for correctness" property ADR-021 Decision 3 states and which this
  task's own second bullet through fourth bullet test directly via `expires_at`, without invoking any
  job. The dispatch call is added to this same `replace()` method at T15, once the Action it calls
  exists — noted here so a later reader does not read its absence as an oversight.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter SecretStoreTest` green (8
  tests, 20 assertions); full-suite run deferred to the end of this batch.

## T15 — `App\Actions\ExpireProxySecrets` and the `secrets:purge-expired` daily sweeper (R10; plan § Services & Actions, ADR-015 Decision 5's shape)
- **Description:** `ExpireProxySecrets` (`AsJob`), scalar arguments only (`proxyId: int, purpose:
  string`), guarded on `expires_at <= now()` — deletes the superseded row if its window has passed,
  no-op otherwise (e.g. if a further rotation already restarted the window). Dispatched with a delay
  from `SecretStore::replace()`/`generate()` at the moment a row is superseded. A daily scheduled
  command, `secrets:purge-expired`, is the liveness net for a lost delayed job — scans for any
  `expires_at <= now()` row across all proxies/purposes and deletes it. Neither the job nor the
  sweeper can extend a window; both only delete.
- **Dependencies:** T14
- **Files:** `app/Actions/ExpireProxySecrets.php` (new), `routes/console.php` (scheduled command
  registration), `app/Console/Commands/PurgeExpiredProxySecrets.php` (new, or an inline closure command
  per this project's existing `routes/console.php` convention — match whichever pattern
  `queue:prune-failed`/existing scheduled commands use)
- **Acceptance Criteria:**
  - `ExpireProxySecrets` deletes only a row whose `expires_at` has passed; is a no-op against a row
    whose window has not passed, and a no-op if the row no longer exists (already deleted by the
    sweeper or a further rotation).
  - **R10:** the daily sweeper deletes an expired row when the delayed job is simulated as never having
    run (dispatch it, then run the sweeper without executing the queued job — the row is still
    removed).
  - The job's arguments are plain scalars (`int`, `string`) — no `Proxy`/`ProxySecret` model argument
    (ADR-021 Decision 8 — a model argument would silently re-enable `SerializesModels` via
    `JobDecorator`).
- **Testing:** `tests/Unit/Actions/ExpireProxySecretsTest.php` (new) — the delete/no-op cases;
  `tests/Feature/Console/PurgeExpiredProxySecretsTest.php` (new) — the R10 lost-job case.
- **Completion notes:** Done. `App\Actions\ExpireProxySecrets` (`AsJob`, scalar `proxyId: int,
  purpose: string` arguments) deletes the superseded row for that `(proxy, purpose)` only when its
  `expires_at` has already passed; guarded by the same `WHERE` clause rather than a separate check, so
  a row whose window hasn't passed or that no longer exists is a plain no-op with zero rows affected.
  `App\Actions\PurgeExpiredProxySecrets` (`AsAction` with `$commandSignature = 'secrets:purge-expired'`,
  matching `PurgeExpiredPayloads`'s existing convention exactly rather than the `Schedule::call()`
  closure style the two per-minute sweepers use — this task's own Testing line names
  `tests/Feature/Console/PurgeExpiredProxySecretsTest.php` and drives it via `$this->artisan(...)`,
  which needs a real registered command name) does one unscoped `DELETE` across every proxy/purpose for
  a superseded row past its `expires_at`. Registered in `routes/console.php` beside
  `payloads:purge-expired`, `Schedule::command('secrets:purge-expired')->daily()`; `Actions::
  registerCommands()` already wires the Artisan entry, no new call needed.

  **`SecretStore::replace()` (T14) now dispatches the delayed job**, exactly as T14's own completion
  notes flagged as deferred to this task: `ExpireProxySecrets::dispatch($proxy->id, $purpose->value)
  ->delay(now()->addHours(RotationOverlap::HOURS))->afterCommit()`, fired only when a row was actually
  demoted (`$hadCurrent`). `app/Services/SecretStore.php` is therefore touched by this task too, even
  though it isn't in T15's own Files list — necessary wiring the task's own description names
  explicitly ("Dispatched with a delay from `SecretStore::replace()`/`generate()`"), not scope creep.

  **File choice, not a deviation:** used `app/Actions/PurgeExpiredProxySecrets.php` rather than
  `app/Console/Commands/PurgeExpiredProxySecrets.php` — the task's own either/or explicitly allows
  matching "whichever pattern `queue:prune-failed`/existing scheduled commands use", and
  `payloads:purge-expired` (the closer precedent: a daily sweep with a genuine Artisan command name,
  versus `queue:prune-failed`'s framework-owned command) is itself an `App\Actions\*` class using
  `AsAction` + `$commandSignature`, not a `Console\Commands` class.

  `tests/Feature/Console/PurgeExpiredProxySecretsTest.php`'s R10 case uses `Queue::fake()` +
  `ExpireProxySecrets::assertPushed(1)` (the lorisleiva-actions pattern already established by
  `SweepDueRetriesTest`/`AdvanceProxyFifoQueueTest` — a plain `Queue::assertPushed(ExpireProxySecrets::
  class)` does not match, since the object actually pushed is `JobDecorator`, never `instanceof` the
  wrapped action) to simulate the delayed job being lost, then moves the superseded row's `expires_at`
  into the past directly (standing in for 24 real hours elapsing) before invoking
  `$this->artisan('secrets:purge-expired')` and asserting the row is gone and the current secret
  untouched.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "ExpireProxySecretsTest|PurgeExpiredProxySecretsTest|SecretStoreTest"` green (13 tests, 30
  assertions); full-suite run deferred to the end of this batch.

## T16 — `App\Enums\VerificationScheme` (AC23, AC50; plan § Services & Actions, ADR-022)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** This task's output,
> `App\Enums\VerificationScheme`, is deleted in full at **T53**. The work was correct against
> ADR-022/PRD-10 AC23/AC50 as approved at the time; it is removed because the Project Owner withdrew
> inbound verification from the product, not because anything here was built wrong. This task also
> filled in `Proxy::casts()`'s deferred `verification_scheme` cast (T2's own deferral) — that cast,
> its `@property` line and its `#[Fillable]` entry are undone at T53 alongside the enum. Original task
> content below is preserved for history; do not build against it.
- **Description:** Backed enum, exactly two cases: `StandardWebhooks = 'standard-webhooks'`,
  `SharedSecret = 'shared-secret'`. The closed list AC50 requires — adding a case is a Project Owner
  decision, never absorbed quietly.
- **Dependencies:** none
- **Files:** `app/Enums/VerificationScheme.php` (new); revisit T2's `Proxy` cast declaration if it was
  deferred there.
- **Acceptance Criteria:** exactly the two documented cases; `VerificationScheme::tryFrom('github') ===
  null`.
- **Testing:** `tests/Unit/Enums/VerificationSchemeTest.php` (new).
- **Completion notes:** Done. `App\Enums\VerificationScheme` — exactly `StandardWebhooks =
  'standard-webhooks'`, `SharedSecret = 'shared-secret'`. T2's deferred cast is now filled in:
  `Proxy::casts()` gains `'verification_scheme' => VerificationScheme::class`, the `@property`
  docblock line changes from `string|null` to `VerificationScheme|null`, and — matching T2's own
  completion note, which named both the cast *and* the `#[Fillable]` entry as deferred together —
  `'verification_scheme'` is added to `Proxy`'s `#[Fillable]` list alongside
  `verification_header_name`/`sensitive_fields`. No other file changed; nothing else in the codebase
  yet references `verification_scheme` (confirmed by search) so this cast has no downstream surface to
  regress before T20/T23 build the validation and form.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "VerificationSchemeTest|ProxySecretTest|SensitiveDataHandlingSchemaTest|EncryptedColumnSurfaceTest"`
  green (32 tests, 113 assertions, confirming the cast change is a no-regression on T1-T3's existing
  Proxy/schema coverage); full-suite run deferred to the end of this batch.

## T17 — `App\Verification\SharedSecretScheme`, `StandardWebhooksScheme` (AC51, AC52, AC53; plan § Services & Actions)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** This task's output — the
> `VerificationSchemeHandler` interface and both scheme classes, plus the whole `app/Verification/`
> directory they live in — is deleted in full at **T53**. **Note the boundary:** `App\Support\StandardWebhooks`
> (T7), which `StandardWebhooksScheme` here delegated to, is a *different* class in a different
> namespace and is **not** touched by this removal — it stays whole, `verify()` included, because
> outbound signing's own suites use it as their receiver-side oracle (ADR-026 Decision 3). The work
> here was correct against ADR-022/PRD-10 AC51–AC53 as approved at the time; it is removed because the
> Project Owner withdrew inbound verification, not because anything here was built wrong. Original
> task content below is preserved for history; do not build against it.
- **Description:** One class per scheme, implementing a shared interface (e.g.
  `App\Verification\VerificationSchemeHandler`, new): `verify(Proxy $proxy, Request $request, string
  $rawBody, list<string> $liveSecrets): bool`. `SharedSecretScheme` — the named header's value must
  exactly (constant-time) match a member of `$liveSecrets`; nothing computed over the body.
  `StandardWebhooksScheme` — delegates to `StandardWebhooks::verify()` (T7) over the three specified
  headers (`webhook-id`, `webhook-timestamp`, `webhook-signature`) and `$liveSecrets`; a missing or
  malformed header fails; the tolerance check (AC53) is enforced here if not already inside T7.
- **Dependencies:** T7, T16
- **Files:** `app/Verification/VerificationSchemeHandler.php` (new interface),
  `app/Verification/SharedSecretScheme.php` (new), `app/Verification/StandardWebhooksScheme.php` (new)
- **Acceptance Criteria:**
  - `SharedSecretScheme`: correct value in the named header verifies; wrong value, missing header, and
    wrong header name all fail; comparison is constant-time (`hash_equals`).
  - `StandardWebhooksScheme`: a specification-computed request verifies; a missing or malformed one of
    the three headers fails; a timestamp outside tolerance fails (AC53); a multi-entry signature list
    verifies when only one entry matches (delegated to T7, exercised here at the scheme layer).
- **Testing:** `tests/Unit/Verification/SharedSecretSchemeTest.php`,
  `tests/Unit/Verification/StandardWebhooksSchemeTest.php` (new) — one test per bullet.
- **Completion notes:** Done. `App\Verification\VerificationSchemeHandler` (interface, exactly
  `verify(Proxy $proxy, Request $request, string $rawBody, array $liveSecrets): bool` as specified) is
  implemented by `SharedSecretScheme` (named-header exact/constant-time match, nothing computed over
  the body) and `StandardWebhooksScheme` (delegates to `StandardWebhooks::verify()` (T7) over the three
  `webhook-*` headers; the tolerance check stays inside T7 per that task's own completion note, not
  duplicated here as a gate — see below for why it's still read once more).

  **One deliberate addition beyond the interface's own `verify(): bool`, needed by T19 and decided as a
  local implementation detail (within this Senior Developer's decision authority):** both classes also
  expose a `reasonFor(...): ?string` method — pure, stateless, recomputed from the same arguments rather
  than cached from a `verify()` call — returning one of ADR-022 Decision 5's five value-free reason codes
  (`missing_header`, `malformed_header`, `timestamp_out_of_tolerance`, `signature_mismatch`,
  `secret_mismatch`) or `null` if the request would verify. `verify()` itself is implemented as
  `reasonFor(...) === null`, so there is exactly one place per scheme that decides pass/fail. T18/T19
  need this because `InboundVerifier::verify()`'s own return type (`VerificationResult`, filed under
  `app/Enums/` per T18's own file list — a plain three-case enum, not a DTO) has nowhere to carry a
  reason, and T19's log line needs the *specific* one of the five codes, not just "failed" — computing
  it anywhere other than inside the scheme that owns the wire format would mean duplicating header/
  format knowledge in `IngestController` or `InboundVerifier`. Kept off the shared interface (interface
  stays exactly as specified) since only `InboundVerifier` needs to call it, and it already has to
  branch on the concrete scheme to dispatch `verify()` in the first place. `StandardWebhooksScheme::
  reasonFor()` re-checks the tolerance window itself (one cheap `abs(time() - $timestamp)` comparison,
  reusing `StandardWebhooks::TOLERANCE_SECONDS` — no second magic number) purely so a
  `timestamp_out_of_tolerance` result can be distinguished from a `signature_mismatch` one; the
  constant itself is still single-sourced from T7.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "SharedSecretSchemeTest|StandardWebhooksSchemeTest"` green (12 tests, 18 assertions); full-suite run
  deferred to the end of this batch.

## T18 — `App\Services\InboundVerifier` (AC24, AC25; plan § Architecture A, ADR-022 Decisions 1–3)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** This task's output —
> `App\Services\InboundVerifier` and `App\Enums\VerificationResult` — is deleted in full at **T53**.
> The work was correct against ADR-022/PRD-10 AC24/AC25 as approved at the time; it is removed because
> the Project Owner withdrew inbound verification, not because anything here was built wrong. Original
> task content below is preserved for history; do not build against it.
- **Description:** The **resolution-time gate**: establishes `$proxy->verification_scheme !== null`
  **before** asking `SecretStore` for anything, so a proxy with verification off never queries
  `proxy_secrets` (AC24's "behaves exactly as today," at the query-count level). `verify(Proxy $proxy,
  Request $request, string $rawBody): VerificationResult` (new small DTO/enum: `NotRequired`,
  `Verified`, `Failed`) — dispatches to the matching `VerificationSchemeHandler` (T17) with
  `SecretStore::liveFor($proxy, SecretPurpose::Verification)`'s result. Every member of the live set is
  tried; which one matched leaves no trace (ADR-022 Decision 3 — no log field, no return value names
  it).
- **Dependencies:** T14, T17
- **Files:** `app/Services/InboundVerifier.php` (new), `app/Enums/VerificationResult.php` (or similar,
  new)
- **Acceptance Criteria:**
  - A proxy with `verification_scheme === null` returns `NotRequired` and issues **zero** queries
    against `proxy_secrets` (assert via `DB::listen` or `assertQueryCount`).
  - A correctly verifying request (either scheme) returns `Verified`; an incorrect one returns
    `Failed`.
  - `SecretUnavailableException` from `SecretStore::liveFor()` propagates rather than being caught and
    treated as `Failed` (the 500-vs-401 distinction is `IngestController`'s to make at T19, not this
    class's to hide).
- **Testing:** `tests/Unit/Services/InboundVerifierTest.php` (new) — the zero-query not-required case,
  the verified/failed case per scheme, the propagated-exception case.
- **Completion notes:** Done. `App\Enums\VerificationResult` is a plain (unbacked) three-case enum —
  `NotRequired`, `Verified`, `Failed` — filed under `app/Enums/` per this task's own Files line, never
  carrying a reason. `InboundVerifier::verify()` checks `$proxy->verification_scheme !== null` before
  any `SecretStore` call, matching AC24's query-count requirement exactly; otherwise fetches the live
  verification set once and dispatches to the matching T17 scheme handler.

  **One method beyond this task's own literal Acceptance Criteria, needed by T19 and flagged in T17's
  completion notes as coming here:** `InboundVerifier::reasonFor(Proxy, Request, string): string`,
  called only by `IngestController` after `verify()` has already returned `Failed`. It re-establishes
  the scheme (throwing `LogicException` if called for a `NotRequired` proxy or if the request would
  actually verify — both programmer-error guards, never reachable from `IngestController`'s own
  correct call order) and delegates to the matching scheme's `reasonFor()` (T17) for the specific
  ADR-022 Decision 5 code. This is a second, independent `SecretStore::liveFor()` read rather than a
  cached one — deliberately stateless, so `InboundVerifier` carries nothing between the two calls — and
  only ever executes on the rejection path, never the common case.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter InboundVerifierTest`
  green (6 tests, 7 assertions); full-suite run deferred to the end of this batch.

## T19 — `IngestController` integration: the verification gate, 401/500 shapes, reason-code log (AC8, AC11, AC25; plan § Architecture A, H; Technical ruling 12; ADR-022 Decisions 4, 5)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** The verification gate this task
> added to `IngestController` — the `InboundVerifier` dependency, the `SecretUnavailableException`
> catch with its `report()`/`abort(500)`, the `VerificationResult::Failed` 401 branch, the
> `ingest.verification_failed` log line, the `VERIFICATION_FAILED_BODY` constant, and the
> `VerificationResult`/`SecretUnavailableException`/`InboundVerifier`/`Log` imports — is removed in
> full at **T53**. **Not everything in this task's own diff goes**: the single `$request->getContent()`
> read this task's own completion notes describe stays exactly where it is (it predates verification
> and is unrelated to it), the capture-failure `report($e)`/`try`/`catch (Throwable)` block is
> untouched, and the body-read-once test stays, re-pointed at the surviving single read rather than
> deleted. The gate itself was correct against ADR-022/PRD-10 AC8/AC11/AC25 as approved at the time; it
> is removed because the Project Owner withdrew inbound verification, not because anything here was
> built wrong. Original task content below is preserved for history; do not build against it.
- **Description:** `IngestController` gains exactly one step between proxy resolution and the capture
  transaction: read `$rawBody` **once**, call `InboundVerifier::verify()`. `NotRequired`/`Verified` →
  continue to capture unchanged. `Failed` → **401**, a fixed non-configurable body, log
  `ingest.verification_failed` with `team_id`, `proxy_id`, `scheme`, and one of five value-free reason
  codes (never a header value, body, secret, or computed signature — AC8), return **before** any
  `webhook_events` row is created. `SecretUnavailableException` → **500**, a report with identifiers
  only, return before capture. `WebhookEventCapture` receives the **same** `$rawBody` string the
  verifier just checked (ADR-022 Decision 4) — no second body read.
- **Dependencies:** T18
- **Files:** `app/Http/Controllers/IngestController.php`
- **Acceptance Criteria:**
  - **AC25, four negatives, one assertion each:** a failed verification returns 401; creates **no**
    `webhook_events` row; creates **no** delivery and **no** `fifo_dispatches` row; does **not** return
    the proxy's configured response (asserted against a proxy configured with a custom 200 + body).
  - **AC11:** a proxy whose verification secret cannot be decrypted returns **500**, never 401 and
    never the configured 2xx, and captures nothing.
  - `ingest.verification_failed` is logged on a failure with exactly `team_id`, `proxy_id`, `scheme`,
    and a reason code — never a header value, body, secret, or signature (assert the log payload
    directly, not just that a line was written).
  - The same `$rawBody` string instance/value reaches both the verifier and `WebhookEventCapture` — no
    second `$request->getContent()` call.
- **Testing:** extends `tests/Feature/Ingest/IngestControllerTest.php` — the four AC25 cases, the AC11
  500 case, the log-payload assertion, a body-read-once assertion (e.g. via a request stream that
  errors on a second read, or a spy).
- **Completion notes:** Done. `IngestController` gains the one step the description names, in order:
  read `$rawBody` once (unchanged read point), `InboundVerifier::verify($proxy, $request, $rawBody)`
  wrapped in a `try`/`catch (SecretUnavailableException)` → `report()` + `abort(500)`; `Failed` → 401
  with the fixed body `'Webhook verification failed.'` (a private class constant,
  `VERIFICATION_FAILED_BODY`, `text/plain`) plus the `ingest.verification_failed` info log
  (`team_id`, `proxy_id`, `scheme` from `$proxy->verification_scheme?->value`, and
  `InboundVerifier::reasonFor()`'s reason code), returned before the capture transaction;
  `NotRequired`/`Verified` fall through to the existing capture path completely unchanged. The same
  `$rawBody` local variable already flowed to both the verifier and `WebhookEventCapture::capture()`
  from the existing single read point — no second `$request->getContent()` call was needed to satisfy
  ADR-022 Decision 4, since the controller already read the body once before this task and this task
  adds no second read.

  **Body-read-once was proven at the controller level, not the full HTTP pipeline**, and the completion
  notes record why: `EnforceIngestBodyLimit` (pre-existing, `routes/ingest.php`) legitimately calls
  `$request->getContent()` itself when `Content-Length` is absent, which would confound a whole-stack
  read count unrelated to this task's own guarantee. `test_the_raw_body_is_read_exactly_once_by_the_controller`
  therefore resolves `IngestController` from the container and invokes `__invoke()` directly against a
  `CountingContentRequest` (a small `Illuminate\Http\Request` subclass local to the test file,
  incrementing a counter on every `getContent()` call), bypassing route middleware entirely — isolating
  the assertion to the one guarantee this task actually owns.

  All four AC25 negatives, the AC11 500 case (via the same corrupted-ciphertext technique used in T14/
  T18's suites), the exact log-payload assertion (`Log::spy()` +
  `Log::shouldHaveReceived('info')->once()->withArgs(...)`, the pattern already established by
  `DeliverToDestinationTest`), and a FIFO-specific case (rejection creates no `fifo_dispatches` row
  either) are all covered as new methods on the existing `tests/Feature/Ingest/IngestControllerTest.php`
  — extended rather than duplicated, per this task's own Testing line.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter IngestControllerTest`
  green (22 tests, 74 assertions, up from the pre-existing 15). **Full suite run at the close of this
  batch (T13-T19): `./vendor/bin/sail test --parallel` green at 973/973** (up from 931 at the T7-T12
  batch boundary — 42 new tests across T13-T19).

## T20 — Verification validation rules (AC23, AC24, AC26; plan § Validation)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** Every validation rule this task
> added (`verification_scheme`, `verification_header_name`, `verification_secret` on both
> `StoreProxyRequest`/`UpdateProxyRequest`) and every piece of persistence plumbing it added to
> `ProxyController::store()`/`update()` (the `SecretStore::replace()` calls, the `verification_scheme`/
> `verification_header_name` write-attribute keys) are removed in full at **T53**. The work was correct
> against ADR-022/PRD-10 AC23/AC24/AC26 as approved at the time; it is removed because the Project
> Owner withdrew inbound verification, not because anything here was built wrong. Original task content
> below is preserved for history; do not build against it.
- **Description:** On `StoreProxyRequest`/`UpdateProxyRequest`: `verification_scheme` —
  `nullable`, `Rule::enum(VerificationScheme::class)`. `verification_header_name` —
  `required_if:verification_scheme,shared-secret`, `prohibited_unless:verification_scheme,shared-secret`,
  `string`, `max:128`, valid HTTP field-name pattern. `verification_secret` — `nullable`, `string`,
  `min:8`, `max:1024`; **required only when a scheme is selected and the proxy has no live
  `verification` secret** — an absent field on an already-configured proxy means "leave unchanged"
  (the write-only contract; a present-but-empty field must never clear the secret).
- **Dependencies:** T14 (to check "has a live secret already"), T16
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:**
  - Selecting `shared-secret` without a header name fails validation; selecting `standard-webhooks`
    with a header name present fails validation (`prohibited_unless`).
  - An empty `verification_secret` on a proxy that already has a live verification secret leaves the
    stored secret unchanged (an **absent** field, not an empty string, is what "leave unchanged" reads
    as — confirm `form.transform()`'s frontend contract at T23 sends absence, not `""`, when untouched).
  - A first-time scheme selection with no secret provided fails validation (secret required when none
    exists yet).
- **Testing:** `tests/Feature/Proxies/VerificationValidationTest.php` (new) — one case per bullet.
- **Completion notes:** Done. `verification_scheme` (`nullable`, `Rule::enum(VerificationScheme::class)`),
  `verification_header_name` (`required_if`/`prohibited_unless:verification_scheme,shared-secret`,
  `string`, `max:128`, the HTTP field-name regex) and `verification_secret` (`nullable`, `string`,
  `min:8`, `max:1024`) added to both `StoreProxyRequest` and `UpdateProxyRequest`. `verification_secret`
  is additionally required via `Rule::requiredIf()`: on Store, whenever a scheme is selected (a create
  has no proxy yet, so no live secret can already exist); on Update, only when a scheme is selected
  **and** the proxy has no live `verification` secret yet — read through
  `SecretStore::liveFor($proxy, SecretPurpose::Verification)` via a new private
  `UpdateProxyRequest::proxyHasLiveVerificationSecret()` helper, keeping `SecretStore` the single
  reader of `proxy_secrets` (Technical ruling 14) rather than a direct query.

  **Necessary supporting plumbing, not scope creep:** this task's own second Acceptance Criterion
  ("an empty `verification_secret` ... leaves the stored secret unchanged") is a persistence claim,
  not a validation-shape one, and no other task in T20–T25 (or, by inspection, anywhere in T1–T49)
  wires `ProxyController::store()`/`update()` to actually write `verification_scheme`/
  `verification_header_name` or call `SecretStore::replace()` for a submitted `verification_secret`
  — unlike the destination credential, whose validation and persistence are explicitly one task
  (T29). Without this wiring, T20's own AC would be untestable (vacuously true, since nothing ever
  persists) and Screen 1 (T23) would submit a fully-built form with no effect. Following the
  precedent already set in this same document (T12's `ProxyResource` addition, T15's `SecretStore`
  dispatch wiring — both flagged as necessary plumbing despite an incomplete Files list), added:
  `ProxyController::store()` calls `SecretStore::replace()` after the new proxy is saved, when
  `verification_secret` is present (`verification_scheme`/`verification_header_name` already ride
  through unchanged via the existing `Proxy::make(array_merge($data, ...))` mass-assignment call,
  since both are already `#[Fillable]` from T2/T16 — no code change needed there); `update()`'s
  explicit column array gains `verification_scheme`/`verification_header_name` (mirroring the
  existing `response_status`/`response_body` omission-vs-explicit-null idiom), and a
  `SecretStore::replace()` call runs when `verification_secret` is present. Per plan-10
  §Architecture B, switching `verification_scheme` back to "not required" writes the column to
  NULL but never calls `SecretStore::disable()` for the verification purpose — the dormant secret
  is deliberately retained (`disable()` stays reserved for signing's different on/off semantics, per
  its own docblock) — pinned by a dedicated test.

  **Confirmed by testing, not assumed:** the parenthetical on this task's second AC ("an absent
  field, not an empty string, is what 'leave unchanged' reads as") anticipates a distinction the app
  turns out not to need — this app's global `ConvertEmptyStringsToNull` middleware (Laravel's
  framework default, active here) normalises a submitted `""` to `null` before validation ever runs,
  so an empty `verification_secret` already takes the identical "absent → leave unchanged" path,
  with no 422 and no special-case code. Verified directly (not inferred) by first writing the test
  against the AC's literal expectation of a validation rejection, watching it fail because the
  request actually succeeded, and correcting the test to the observed, safe behaviour rather than
  forcing a rejection the framework doesn't produce. T23's frontend `transform()` still omits the
  key on an untouched Replace field regardless (design-10's own stated rule), so both layers agree
  independently.

  Nine tests: the three literal AC bullets (`shared-secret` without a header, `standard-webhooks`
  with one present, first-time selection with no secret), a valid first-time-selection persistence
  round trip, the absent-field-leaves-unchanged case, the empty-string case (see above), a
  replace-rotates-with-an-overlap case, the switch-to-not-required-keeps-the-dormant-secret case,
  and a `shared-secret` round trip asserting the header name persists. `composer lint`,
  `composer types:check` and `./vendor/bin/sail test --filter
  "VerificationValidationTest|ProxyStoreTest|ProxyUpdateTest|ProxyRequestValidationTest|SensitiveFieldsPersistenceTest"`
  all green (92 tests, 285 assertions); full-suite run deferred to the end of this batch (T20-T25).

## T21 — `ProxyVerificationOverlapController@destroy` (AC29; plan § API)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** This task's output —
> `ProxyVerificationOverlapController` and the `proxies.verification.overlap.destroy` route — is
> deleted in full at **T53**. The work was correct against AC29's inbound half as approved at the time;
> it is removed because the Project Owner withdrew inbound verification, not because anything here was
> built wrong. AC29's outbound (signing) half is untouched — `SecretStore::endOverlap()` (T14) and the
> three `proxies.signing.*` routes (T37) stand. Original task content below is preserved for history;
> do not build against it.
- **Description:** `DELETE proxies/{proxy}/verification/overlap`, gated `update` via `ProxyPolicy`,
  calls `SecretStore::endOverlap($proxy, SecretPurpose::Verification)`, Inertia redirect (`back()`).
- **Dependencies:** T14
- **Files:** `app/Http/Controllers/ProxyVerificationOverlapController.php` (new), `routes/web.php`
- **Acceptance Criteria:**
  - Ending an overlap stops the previous secret verifying **immediately** (a request that verified
    against it a moment ago now fails).
  - Idempotent — calling it again, or when no overlap is running, is a no-op, no error.
  - A Member without `update` rights on the proxy is **403**.
- **Testing:** `tests/Feature/Proxies/ProxyVerificationOverlapControllerTest.php` (new).
- **Completion notes:** Done. `ProxyVerificationOverlapController@destroy` (`DELETE
  proxies/{proxy}/verification/overlap`) authorizes `update` on the proxy through `ProxyPolicy`
  (unchanged, no new permission), calls `SecretStore::endOverlap($proxy, SecretPurpose::Verification)`
  — already idempotent by construction from T14 — and redirects with `back()`, matching the plan's API
  table exactly and mirroring `ProxyEventReplayController::store()`'s own `back()` PRG precedent.
  Route registered in `routes/web.php` alongside the other proxy-scoped mutating routes; a single
  `{proxy}` binding needs no `->scopeBindings()` (that's reserved for a doubly-nested child like
  `{destination}`).

  Four tests: ending a running overlap removes the previous secret from the live set immediately (the
  "stops verifying" claim, proven at the `SecretStore::liveFor()` level rather than a second HTTP
  round trip through the ingest endpoint, since `InboundVerifier` reads exactly that live set — T25's
  integration suite additionally proves this over real HTTP); calling it twice is a no-op the second
  time; calling it when no overlap is running is a no-op; and a Member without update rights on a
  teammate's proxy (attached via `TeamRole::Member`, not the proxy's creator) is 403 with nothing
  changed.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  ProxyVerificationOverlapControllerTest` all green (4 tests, 10 assertions); full-suite run deferred
  to the end of this batch (T20-T25).

## T22 — `ProxySecurityResource`: the `security` prop's `verification` sub-object (AC20, AC26, AC28; plan Technical rulings 3, 5; § API)
> **Status: PARTIALLY SUPERSEDED by ADR-026 Decision B, 2026-08-28 — narrower than T16–T21/T23–T25,
> read carefully.** Only the `verification` sub-object and its status lookup are removed, at **T53**.
> **`ProxySecurityResource` itself, the sibling `security` prop wiring on `ProxyController::show()`/
> `::edit()`, and `SecretStore::statusFor()` (this task's own addition) all stand** — they now carry
> only the `signing` sub-object (T38) and the `destinations` credential map (T32), both untouched by
> the removal, as is `#[PreserveKeys]`. Do not delete this file, the `security` prop, or `statusFor()`
> when acting on T53 — only the `verification` key and its lookup go. Original task content below is
> preserved for history exactly as built; its `verification`-specific portions are superseded, its
> resource/prop-wiring portions are not.
- **Description:** New resource, status-only — never a value, never a length. `verification: {
  scheme, header_name, secret_set, secret_changed_at, overlap_expires_at } | null`. Wired as a sibling
  **`security`** prop on `ProxyController::show()` and `::edit()` (never `index()`, never a key on
  `ProxyResource` — Technical ruling 3). `create()` renders no proxy resource at all.
- **Dependencies:** T14, T16
- **Files:** `app/Http/Resources/ProxySecurityResource.php` (new), `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - `show()`/`edit()` both emit a `security` prop with the `verification` sub-object shaped exactly as
    above; `index()` gains no new key; `ProxyResource` itself is unchanged.
  - The resource never serializes a secret's value or length — grep-level and response-level
    assertion.
  - `scheme === null` when verification is not required; `secret_set` is `true`/`false`, never the
    value.
- **Testing:** `tests/Feature/Proxies/ProxySecurityResourceTest.php` (new) — the shape assertion, the
  `index()`-untouched assertion, the no-value/no-length assertion.
- **Completion notes:** Done. `App\Http\Resources\ProxySecurityResource` — status-only, `$wrap = null`
  — builds the `verification` sub-object exactly as specified (`scheme`, `header_name`, `secret_set`,
  `secret_changed_at`, `overlap_expires_at`), wired as a sibling `security` prop on
  `ProxyController::show()` and `::edit()` only; `index()` and `ProxyResource` itself are both
  untouched.

  **Necessary supporting plumbing, not scope creep:** the resource needs non-secret status metadata
  (whether a live secret exists, when it last changed, whether an overlap is running) that
  `SecretStore`'s existing methods don't expose — `liveFor()` returns decrypted values, not metadata.
  Rather than query `proxy_secrets` directly from the resource (a Technical-ruling-14 violation — this
  document's own binding constraint 5 is explicit that only T14/T15 may do that), added
  `SecretStore::statusFor(Proxy, SecretPurpose): ?SecretStatus` (new `App\Data\SecretStatus` readonly
  DTO — `changedAt`, `overlapExpiresAt`, never a value or length) as the one new read method on the
  single reader/writer, following the same precedent already established in this document (T15's
  `SecretStore` touch, T20's controller-persistence wiring) for necessary plumbing a task's own Files
  list didn't enumerate. `statusFor()` returns `null` when no secret has ever been configured for that
  purpose; otherwise `changedAt` is the current row's `created_at` and `overlapExpiresAt` is the live
  superseded row's `expires_at` (or `null` when no overlap is running).

  **Typed against `Carbon\CarbonInterface`, not `Illuminate\Support\Carbon`:** this app's
  `AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so every Eloquent date-cast property
  resolves to `CarbonImmutable` at runtime, but Larastan's own inference of a model's date-cast
  property still widens to a `CarbonImmutable|Carbon` union — `CarbonInterface` (implemented by both)
  is what both runtime and PHPStan level 7 agree on without a suppression.

  Five tests: the not-required shape (all fields null/false), a `shared-secret` proxy with a live
  secret (full shape, header name present, `secret_set` true, a real `secret_changed_at`), a running
  overlap carrying a non-null `overlap_expires_at`, `index()` gaining no `security` key, and a
  response-body substring check (both `show()` and `edit()`) proving a live secret's actual value
  never appears anywhere in either response.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter Proxies` all green (179
  tests, 927 assertions — the full `tests/Feature/Proxies` directory, confirming no regression across
  T1–T22's existing coverage); full-suite run deferred to the end of this batch (T20-T25).

## T23 — Screen 1: `ProxyForm.vue` Verification section (AC23, AC24, AC26, AC29-ruling-2a; Flows A, B; plan Implementation Notes 13–15, 20–21)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** This task's own contribution to
> `ProxyForm.vue` — the whole Verification `fieldset`, the five `initialVerification*` mount seeds, the
> `verification_scheme`/`verification_header_name`/`verification_secret` form keys, the
> `VERIFICATION_NOT_REQUIRED` sentinel, `verificationSchemeSelect`, `verificationReplaceClicked`,
> `verificationSecretIsSet`, `replaceVerificationSecret()`, the scheme-switch clearing watcher, and the
> `standardWebhooksTolerance` prop — is removed in full at **T52**. `ProxyForm.vue` itself survives
> with everything other tasks built (Sensitive fields, Destinations) untouched. Screen 1 and Flows A/B
> are withdrawn in full by the `design-10` revision (Designer, per ADR-026 § Impact). The work here was
> correct against `design-10`'s original Screen 1/Flows A–B and AC23/AC24/AC26/AC29-ruling-2a as
> approved at the time; it is removed because the Project Owner withdrew inbound verification, not
> because anything here was built wrong. Original task content below is preserved for history; do not
> build against it.
- **Description:** New section, placed after **Processing**, before **Retry policy**. `Select` bound to
  `form.verification_scheme` with a `"none"` sentinel for "Not required" (the underlying `Select`
  primitive rejects an empty string — N2). Conditional fields per scheme, the shared write-only
  secret-field shape (unset: plain `Input type="password" autocomplete="off"`; set: collapsed "Secret
  set — changed {date}" + Replace). `{tolerance}` interpolates `StandardWebhooks::TOLERANCE_SECONDS`
  (T7) as a page prop, never hand-typed. **Flow B step 2's disclosure, branched (AC29 ruling 2a):**
  clicking Replace shows a help line stated **before save** — the ordinary-case copy when no overlap is
  running, or the immediate-discard copy when one already is (this proxy's verification secret has a
  live overlap per the T22 `security` prop's `overlap_expires_at`). Switching scheme clears the
  in-session, unsaved secret field only (`ProxyForm.vue`'s existing mount-seeded-vs-in-session-typed
  distinction — read `plan-07` § Technical ruling 4 before touching this, per `plan-10` Implementation
  Note 13; review-07's Major came from getting exactly this wrong).
- **Dependencies:** T20, T22
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - Scheme defaults to "Not required"; no secret field renders in that state.
  - Selecting a scheme reveals its fields and its "what your sender must send" static block, with the
    interpolated tolerance value matching `StandardWebhooks::TOLERANCE_SECONDS` exactly.
  - Switching back to "Not required" clears the in-session secret field without touching what is
    persisted (assert via a save afterward — the stored secret, if any, is unchanged).
  - Clicking Replace on a proxy with **no** overlap running shows the ordinary-case copy; on a proxy
    **with** a live overlap, shows the immediate-discard copy naming the timestamp — both **before**
    the save button is clickable-with-effect.
  - A present-but-empty secret field after clicking Replace, left untouched, does not clear the stored
    secret on save.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flows A and B, against a
  production build, both themes, 360px: scheme selection and field visibility; the tolerance copy
  matches the backend constant; both branches of the Replace disclosure (no overlap / overlap running,
  achieved by rotating twice in a test fixture proxy first); scheme-switch-clears-in-session behaviour.
- **Completion notes:** Done. New "Verification" `fieldset` added to `ProxyForm.vue`, placed after
  Processing and before Retry policy exactly as Screen 1 specifies. `Select v-model="verificationSchemeSelect"`
  wraps `form.verification_scheme` with the `"none"` sentinel (N2), mirroring `statusSelect`'s existing
  pattern exactly. `shared-secret` reveals a Header name field (pre-filled from the mount-seeded
  `security.verification.header_name` only when switching back to the originally-persisted scheme,
  cleared otherwise) plus the shared write-only secret shape; `standard-webhooks` reveals the same
  write-only secret shape plus the static "what your sender must send" block, its `{tolerance}`
  interpolating a new `standardWebhooksTolerance` page prop (`StandardWebhooks::TOLERANCE_SECONDS`,
  T7) divided into minutes — never hand-typed (AC53). The write-only field's set/unset/replace states
  (`verificationSecretIsSet`, `verificationReplaceClicked`) are governed entirely by the mount-seeded
  `security.verification.secret_set`/`overlap_expires_at` (T22) — never re-read after mount — matching
  `ProxyForm.vue`'s existing mount-seeded-vs-in-session-typed distinction (plan-07 §Technical ruling
  4). **Screen 1 / Flow B step 2's amended disclosure, landed verbatim, word for word**: the ordinary
  case ("Your current secret keeps working for 24 hours after you save this...") when
  `initialVerificationOverlapExpiresAt` is null, and the discard case ("You already have a previous
  secret from your last rotation, still honoured until {timestamp}. Saving a new secret now stops that
  previous secret being honoured immediately — its 24 hours do not finish out.") when it isn't — shown
  once Replace is clicked, before the save button has any effect, exactly as the amendment gate's
  correction requires. Switching scheme resets the Replace/typed-secret state and reseeds/clears the
  header name (same watcher-driven reset the Retry-policy fieldset already uses on a Mode change).

  **Necessary supporting plumbing, not scope creep** (same precedent as T12/T15/T20/T22): added the
  `standardWebhooksTolerance` page prop to `ProxyController::create()`/`edit()` (named explicitly by
  the plan's own API table, but not in this task's Files list) and forwarded it plus the existing
  `security` prop through `Create.vue`/`Edit.vue` down to `ProxyForm.vue`; `Create.vue` never passes
  `security` at all (no proxy resource exists yet, Technical ruling 3), so `ProxyForm.vue`'s `security`
  prop is optional and every mount-seeded verification value defaults to "nothing configured" there.
  Added `App\Data`-mirroring `ProxySecurity`/`VerificationScheme` TypeScript types to
  `resources/js/types/proxies.ts`.

  **Manual verification performed** (own local Sail dev environment, seeded via `sail tinker`, deleted
  again immediately after — same recipe as T9/T12), via a headless Playwright session against the real
  running app (not a static build check alone): logged in as a fresh team/user, then against a Create
  page and three seeded proxies (no verification; `standard-webhooks` with two live secrets — an
  overlap running; `shared-secret` with exactly one live secret — no overlap):

  - Create: Scheme defaults to "Not required" with no secret field; selecting `shared-secret` reveals
    Header name + Secret value; selecting `standard-webhooks` reveals Secret value plus the static
    block naming `webhook-id`/`webhook-timestamp`/`webhook-signature` and a tolerance sentence reading
    "5 minutes" (confirmed programmatically against the page's rendered text, not just visually);
    switching back to "Not required" hides the fields again; no horizontal overflow at a 360px
    viewport (checked via `scrollWidth`/`clientWidth`).
  - Edit, overlap-running proxy: scheme pre-selected correctly; collapsed "Secret set — changed
    {date}" status with a Replace button; clicking Replace reveals a blank input and the **immediate
    discard** disclosure text verbatim, with the **ordinary 24-hour copy absent** (asserted both ways,
    not just presence of one).
  - Edit, no-overlap proxy: header name pre-filled correctly (`X-Signature`); clicking Replace shows
    the **ordinary 24-hour copy** verbatim, with the **immediate-discard copy absent**.
  - Edit, no-verification proxy: scheme "Not required", no secret field — unaffected by this feature.
  - Screenshots taken in both light and dark mode (the standard-webhooks state) — legible and
    correctly styled in both; zero browser console/page errors observed across the whole session.

  `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check` and `pnpm run build` all
  green. `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter Proxies` green
  (179 tests, 927 assertions — no regression); full-suite run deferred to the end of this batch
  (T20-T25).

## T24 — Screen 4: `proxies/Show.vue` Verification card (AC29; Flow C; plan § Architecture F)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** This task's own contribution to
> `Show.vue` — the whole Verification `Card`, `verificationSchemeLabel`, `verificationSecretStatus`,
> `verificationOverlapStatus`, `verificationOverlapBusy`, `verificationOverlapError`,
> `endVerificationOverlap()` and its `proxyRoutes.verification.overlap.destroy` call — is removed in
> full at **T52**. `Show.vue` itself survives; the Signing card immediately below this one, its own
> end-overlap handler and the signing dialog are untouched by this removal, exactly as ADR-026 states.
> Screen 4 and Flow C are withdrawn in full by the `design-10` revision (Designer, per ADR-026 §
> Impact). The work here was correct against `design-10`'s original Screen 4/Flow C and AC29 as
> approved at the time; it is removed because the Project Owner withdrew inbound verification, not
> because anything here was built wrong. Original task content below is preserved for history; do not
> build against it.
- **Description:** New `Card`, alongside Retry policy, after Destinations. States: not required; scheme
  set, no overlap (`dl`/`dt`/`dd` of scheme, header if `shared-secret`, "Set — changed {date}");
  overlap running (adds the rotation-in-progress line and an **End overlap now** button, gated on the
  existing `canUpdate` computed — no new permission, AC28). Calls T21's endpoint on click, with
  `Spinner`/inline error per the existing `ReplayDialog.vue`-style convention.
- **Dependencies:** T21, T22
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:**
  - Each of the three states (not required / set / overlap) renders correctly against its matching
    `security.verification` prop shape.
  - **End overlap now** is visible only when `canUpdate` is true; the read-only status line always
    renders regardless of `canUpdate`.
  - Clicking End overlap now removes the rotation line on success without a full page reload
    (`router.reload({ only: [...] })` or equivalent), and shows an inline error on failure without
    losing the card's prior state.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flow C, against a
  production build: all three states render correctly; End overlap now works and updates the card;
  the button is absent for a Member without update rights on a teammate's proxy.
- **Completion notes:** Done. New "Verification" `Card` added to `proxies/Show.vue`, placed
  immediately after the Destinations table and before the Retry policy card, matching Screen 4's
  placement instruction. Three states, driven entirely by the existing `security` prop (T22, now a
  required prop on `Show.vue` since `show()` has always emitted it): **not required** (the plain
  status line); **scheme set, no overlap** (`dl` of Scheme/Header-if-shared-secret/Secret, via two new
  computeds — `verificationSchemeLabel` and `verificationSecretStatus` — kept as computeds rather than
  inline template expressions so an impossible combination the write-only validation contract never
  actually produces, e.g. a scheme with no live secret, degrades to a safe fallback instead of a
  runtime crash on a null timestamp); **overlap running** (the rotation line, always rendered for
  anyone who can view the proxy, plus a `canUpdate`-gated **End overlap now** button — the same reused
  `canUpdate` computed this page already has, no new permission, AC28). Clicking **End overlap now**
  calls T21's endpoint via `router.delete(..., { only: ['security'] })` — a partial Inertia reload of
  just the `security` prop, never a full page reload — with a `Spinner` while in flight and an
  `AlertError` (this app's existing `ReplayDialog.vue`-style convention) on failure, leaving the card's
  prior state intact rather than clearing it.

  **Manual verification performed** (own local Sail dev environment, seeded via `sail tinker`, deleted
  again immediately after — same recipe as T23), via a headless Playwright session against the real
  running app: an Owner and a Member (attached via `TeamRole::Member`, not the proxy's creator) against
  three seeded proxies (not required; `shared-secret` with one live secret, no overlap; `standard-webhooks`
  with two live secrets, an overlap running).

  - Not-required proxy: the plain "No verification required" line renders; no End overlap button.
  - Shared-secret, no overlap: Scheme "Shared secret", Header "X-Signature", "Set — changed {date}"
    all render; no rotation line, no End overlap button (there is nothing running to end).
  - Overlap-running proxy: the rotation line and End overlap now button both render for the Owner;
    clicking it left the URL unchanged (confirming no full-page navigation), removed the rotation line,
    and the card settled into the plain "Set — changed {date}" state — all via one partial reload.
  - Member session on the shared-secret proxy (no update rights, not the creator): the same read-only
    status line rendered identically; the End overlap now button was absent.
  - Zero browser console/page errors observed across the whole session.

  `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check` and `pnpm run build` all
  green. `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter Proxies` green
  (187 tests, 982 assertions — no regression); full-suite run deferred to the end of this batch
  (T20-T25).

## T25 — Inbound verification integration test suite (AC24, AC25, AC28, AC29, AC51–AC53; ADR-022 Decision 6)
> **Status: SUPERSEDED — removed by ADR-026 Decision B, 2026-08-28.** This task's output,
> `tests/Feature/Ingest/InboundVerificationIntegrationTest.php`, is deleted in full at **T53** — its
> whole subject is a capability the product no longer has. The work was correct against
> ADR-022/PRD-10 AC24/AC25/AC28/AC29/AC51–AC53 as approved at the time; it is removed because the
> Project Owner withdrew inbound verification, not because anything here was built wrong. Original task
> content below is preserved for history; do not build against it.
- **Description:** No production code — the end-to-end pinning pass across everything T16–T22 built,
  through real HTTP requests rather than unit calls.
- **Dependencies:** T19, T20, T21
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - **AC24:** a proxy with no verification behaves identically to today — same status, body, capture,
    dispatch — against a proxy with `verification_scheme` NULL and no secret rows, issuing **no**
    `proxy_secrets` query.
  - `shared-secret`: correct/wrong value, missing header, wrong header name.
  - `standard-webhooks`: specification-computed signature verifies; multi-entry list verifies on the
    second entry; non-`v1` entry skipped; missing/malformed header fails; hex instead of base64 fails;
    `whsec_`-prefixed and bare secrets both work; tolerance boundary (`TOLERANCE_SECONDS + 1` fails,
    one second inside passes).
  - **AC29**, end to end over HTTP: during an overlap both secrets verify inbound; after expiry only
    the current does, with the sweeper never run; a second rotation discards the oldest immediately;
    End overlap now discards it immediately.
  - **AC28:** a Member without update rights on a teammate's proxy is 403 on every new mutating
    endpoint this milestone added.
  - **ADR-022 Decision 6:** a replay of a verified proxy's event dispatches without re-verifying.
- **Testing:** `tests/Feature/Ingest/InboundVerificationIntegrationTest.php` (new) — one test method
  per bullet above.
- **Completion notes:** Done. New test-only file, 19 test methods, real HTTP requests throughout
  (`$this->post()`/`postJson()` against the ingest route, `$this->delete()`/`post()` against the
  proxy-management routes) — no production code touched, per this task's own description.

  **AC24:** one test, a proxy with `verification_scheme` NULL and no secret rows returns its
  configured 200/body, captures the event, dispatches `ProcessIngestedWebhook`, and issues **zero**
  queries touching `proxy_secrets` (the same `DB::listen` substring-count technique T18's own unit
  test already established, now proven at the HTTP layer).

  **shared-secret** (4 methods): correct value verifies and captures; wrong value, a missing header,
  and a wrong header name are each rejected with nothing captured.

  **standard-webhooks** (9 methods): a specification-computed signature verifies; a multi-entry
  `webhook-signature` list verifies when only the second (real) entry matches; a non-`v1` first entry
  is skipped and a later real entry still verifies; a missing header and a malformed one (non-numeric
  timestamp) both fail; hex instead of base64 fails; a `whsec_`-prefixed secret and the bare form of
  the same key material both verify (two proxies, one per encoding); the tolerance boundary
  (`TOLERANCE_SECONDS + 1` outside `now()` rejected, one second inside accepted). Requests are built
  with `postJson()` and an independently-reconstructed `json_encode()` of the same array as the raw
  body signed via `StandardWebhooks::sign()` — `postJson()`'s own implementation is `json_encode($data,
  0)`, confirmed by reading its source, so the two bodies are guaranteed byte-identical rather than
  merely likely to match.

  **AC29, end to end over HTTP** (4 methods): during an overlap, both the demoted and the current
  secret verify inbound; after the demoted row's `expires_at` is pushed into the past directly (no
  sweeper, no job, ever run), only the current one verifies; a third rotation discards the oldest
  secret immediately (asserted by attempting to verify with it and getting 401, while the middle and
  newest both still succeed); `DELETE .../verification/overlap` (T21) discards the previously-demoted
  secret immediately (verifies before the call, fails with 401 immediately after).

  **AC28** (1 method): a Member without update rights on a teammate's proxy (attached via
  `TeamRole::Member`, not the proxy's creator) is 403 on `DELETE .../verification/overlap` — the only
  genuinely new mutating endpoint this milestone (M6, T13–T25) added; `ProxyController::store()`/
  `update()` are pre-existing routes with extended validation, not new endpoints, so they are outside
  this bullet's "every new mutating endpoint" scope (T20's own `VerificationValidationTest` already
  covers their behaviour). Followed by an assertion that nothing changed — both secrets still verify.

  **ADR-022 Decision 6** (1 method): captures a genuinely verified event, then corrupts the stored
  secret's ciphertext so any live re-verification attempt would throw `SecretUnavailableException`
  (T14's fail-loud contract) — a 500 the test would catch immediately if replay were ever wired to
  re-verify — then calls the replay endpoint and asserts it redirects normally and creates exactly one
  `Delivery` row. This is a stronger proof than reading the replay controller's source (which already
  never references `InboundVerifier` at all): it fails loudly the moment someone adds a re-verification
  call to that path, rather than relying on the absence of a code reference staying true by inspection.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  InboundVerificationIntegrationTest` green (19 tests, 53 assertions). **Full suite run at the close of
  this batch (T20-T25): `./vendor/bin/sail test --parallel` green at 1010/1010** (up from 973 at the
  T13-T19 batch boundary — 37 new tests across T20-T25: 9 + 4 + 5 + 0 + 0 + 19, T23/T24 being
  frontend-only with no automated test harness).

---
