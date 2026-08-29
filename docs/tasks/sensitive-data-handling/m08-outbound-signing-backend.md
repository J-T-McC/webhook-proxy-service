> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M8a — Outbound signing, backend

## T34 — `OutboundHeaders` extended: the signing headers (AC54, AC55, AC58, AC59, AC60, AC64; plan § Architecture C, ADR-023 Decisions 2–3) — **delivery path**
- **Description:** Extends T26's `OutboundHeaders` with the fifth composition step: if the proxy has a
  live `signing` secret set, add the Standard Webhooks headers — `webhook-id` (derived,
  `msg_{dispatch_uuid}_{destination_id}` — stable across retries of one delivery, new on a replay, no
  new column), `webhook-timestamp` (this attempt's time, not the original), `webhook-signature`
  (`StandardWebhooks::sign()`, T7, over the **exact bytes about to go out**, one `v1,<sig>` entry per
  live signing secret — at most two, AC29's cap) — computed **in the send path**, over the request that
  is actually about to be dispatched, so a signature can never be computed over stale bytes. These
  three headers take precedence over any forwarded inbound header of the same name (AC64), resolved by
  the same lowercased-name collision rule T26 already established. Signing changes **nothing but the
  headers** — the body is byte-identical to the unsigned case (AC59).
- **Dependencies:** T7, T14, T26
- **Files:** `app/Support/OutboundHeaders.php`
- **Acceptance Criteria:**
  - `webhook-id` is identical across attempt 1 and its retry of the same delivery; **different** on a
    replay of the same event; **different per destination** of one dispatch even though the signing
    key is shared (AC60 — the secret is the proxy's, the message identity stays per delivery).
  - `webhook-timestamp` reflects each attempt's own time, not the original attempt's.
  - During a signing overlap, the header carries **exactly one entry per live secret** (at most two)
    and each verifies independently against `StandardWebhooksScheme`/`StandardWebhooks::verify()`;
    after expiry, exactly one.
  - The signature verifies against the specification, computed over the exact dispatched bytes; the
    body is byte-identical to the same request unsigned.
  - An inbound request that happened to carry `webhook-id`/`webhook-timestamp`/`webhook-signature`
    (e.g. from a `standard-webhooks`-verified proxy) never lets those values reach a destination as the
    proxy's own signing headers — the signing values always win (AC64).
- **Testing:** `tests/Unit/Support/OutboundHeadersSigningTest.php` (new) — one test per bullet.
- **Completion notes:** Done. `OutboundHeaders::build()` gains the fifth composition step (ADR-023
  Decision 1): an explicit `list<string> $signingSecrets` parameter (default `[]`, matching this
  document's established defaulting-new-parameter precedent so no existing call site breaks), composed
  into the same `added`/collision-removal mechanism T26 already built for the credential header, rather
  than a second bespoke precedence path — AC38 and AC64 are now the same rule applied to a longer list.
  A new private `signingHeaders()` derives `webhook-id` as `msg_{dispatch_uuid}_{destination_id}`
  (ADR-023 Decision 3, no new column), takes `webhook-timestamp` at the exact moment of this call via
  `now()->getTimestamp()` (this app's established `Carbon::setTestNow()`-testable convention, rather
  than `StandardWebhooks::verify()`'s own bare `time()`, which only reads an already-received header
  value and has no equivalent testability need), and builds `webhook-signature` as one space-delimited
  `v1,<sig>` entry per live secret via `StandardWebhooks::sign()` (T7) over `$unit->payload` — the exact
  bytes about to be dispatched, never touched or read back by this class (AC59).

  **Necessary supporting plumbing, not scope creep (this document's own established precedent — T12,
  T15, T20, T22, T23, T27, T29, T30, T32):** `webhook-id`'s derivation needs the delivery's
  `dispatch_uuid`, which `DeliveryUnit` did not carry before this task. Added `dispatchUuid: string = ''`
  to `DeliveryUnit`'s constructor (defaulted for the same reason T27's `verificationHeaderNames` was —
  every pre-#10 construction site stays valid unchanged) and wired
  `DeliveryUnitResolver::resolve()` to pass `$delivery->dispatch_uuid` through. Also added
  `signingSecrets: array = []` to `DeliveryUnit` (populated for real at T36; `[]` until then, so this
  task's own production wiring is inert everywhere it lands ahead of T36) and updated
  `DeliverToDestination::send()`'s existing `OutboundHeaders::build()` call site to pass
  `$unit->signingSecrets` as the fifth argument — otherwise this task's own class-level capability would
  never actually reach a real dispatch, leaving T34–T38 unconnected until some later task noticed.

  Five tests, one per Acceptance Criteria bullet: `webhook-id` identical across an attempt and its retry
  (same `dispatchUuid`), different on a replay (different `dispatchUuid`) and different per destination
  of the same dispatch despite a shared signing key (AC60); `webhook-timestamp` reflects each call's own
  time via `Carbon::setTestNow()`, not a fixed/original one; an overlap of two live secrets produces
  exactly two space-delimited entries, each independently verifying via `StandardWebhooks::verify()`
  against only its own secret, collapsing to exactly one entry once only one secret is live; the
  signature verifies against the specification over the exact dispatched bytes, with `$unit->payload`
  provably unread/unmutated by `build()` and no `body` key ever present in its return; and an inbound
  request that happened to carry `webhook-id`/`webhook-timestamp`/`webhook-signature` never lets those
  values reach a destination as the proxy's own once signing is on (AC64).

  **Delivery-path caveat, stated per the task list's own instruction:** `QUEUE_CONNECTION=sync` runs the
  send path inline under this suite; this is strong evidence the *logic* — the composition order, the
  derivation, the collision rule — is correct, but exercises none of Horizon's real async/queued
  dispatch, and this task's own suite is a pure unit test of `OutboundHeaders` directly, not even routed
  through a queued job.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "OutboundHeadersTest|OutboundHeadersSigningTest|DeliverToDestinationTest|DeliveryUnitResolverTest|RetryDeliveryTest"`
  (43 tests, 151 assertions — confirming no regression on T26/T27/T28's existing coverage) all green;
  full-suite run deferred to the end of this batch (T34-T40).

## T35 — AC63 byte-identical regression, dedicated (AC63; plan § Test strategy, "the regression that matters most") — **delivery path**
- **Description:** The signing-surface counterpart to T26's AC37 test, named separately per `plan-10`'s
  own instruction that this class of regression "must be its own named task" — a partial landing here
  is a shipped defect that a broader test could quietly pass around. A destination **of a proxy without
  a signing secret** produces a request byte-identical, header set and body both, to the pre-signing
  (and pre-#10, composing with T26) baseline.
- **Dependencies:** T34
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - A destination of a proxy with no signing secret configured: no `webhook-*` header is added; the
    header set and body are byte-identical to the T26 AC37 baseline (i.e., this test composes with T26
    rather than duplicating its fixture from scratch).
  - A destination of a proxy that **had** signing enabled and then disabled produces the same
    byte-identical result (ADR-021 Decision 5's delete-on-disable, exercised at the header-building
    layer).
- **Testing:** `tests/Unit/Support/OutboundHeadersSigningRegressionTest.php` (new), or a dedicated
  method inside T34's test class clearly separated and named for this AC — either is acceptable as
  long as it is independently identifiable as *the* AC63 regression guard, not folded into a broader
  assertion.
- **Completion notes:** Done. New, independently-identifiable test file (not folded into T34's own
  suite) — `tests/Unit/Support/OutboundHeadersSigningRegressionTest.php`. Two tests, mirroring T26's own
  AC37 fixture exactly (same header set, same `assertSame($unit->forwardHeaders(), $result)` assertion)
  rather than a fresh fixture: a proxy with no signing secret configured produces no `webhook-*` header
  and a byte-identical result; a proxy that had signing enabled and then disabled produces the same
  result, since `SecretStore::disable()` (ADR-021 Decision 5) leaves `liveFor()` returning an empty set —
  mechanically identical, at the `OutboundHeaders` layer, to a proxy that never enabled signing at all
  (noted here so a later reader does not read the two tests' identical inputs as redundant — they pin
  two distinct product states that happen to collapse to the same header-building input).

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  OutboundHeadersSigningRegressionTest` (2 tests, 8 assertions) green; full-suite run deferred to the end
  of this batch (T34-T40).

## T36 — `DeliveryUnitResolver`/`DeliveryUnit`: the proxy's live signing set (AC54, AC60; plan § Services & Actions) — **delivery path**
- **Description:** `DeliveryUnitResolver` asks `SecretStore::liveFor($proxy, SecretPurpose::Signing)`
  (through the `withTrashed()` proxy load T27 already established) and populates `DeliveryUnit` with
  the result, so `OutboundHeaders` (T34) has what it needs at send time without querying
  `proxy_secrets` directly (Technical ruling 5 — `SecretStore` stays the single reader/writer).
- **Dependencies:** T14, T27
- **Files:** `app/Services/DeliveryUnitResolver.php`
- **Acceptance Criteria:**
  - `DeliveryUnit` carries the resolved proxy's live signing secret set (0, 1, or 2 entries) correctly
    for a proxy with signing off, on with no overlap, and on with an overlap running.
  - Enabling signing on a proxy signs dispatches to **every** destination of that proxy, including one
    added **after** signing was enabled — no per-row lookup, no per-row state (AC54).
- **Testing:** extends `tests/Unit/Services/DeliveryUnitResolverTest.php` — the three signing-state
  cases, the destination-added-afterward case.
- **Completion notes:** Done. `DeliveryUnitResolver` gains a `SecretStore` constructor dependency and
  calls `SecretStore::liveFor($proxy, SecretPurpose::Signing)` once per resolve, on the proxy already
  loaded `withTrashed()` (T27) — no second query, no per-row lookup, so every destination of a
  signing-enabled proxy (including one added afterward) resolves the identical live set (AC54).

  **Necessary supporting plumbing, not a deviation, decided within this Senior Developer's own
  discretion (naming/private-helper decisions the plan leaves open):** `SecretStore::liveFor()` can
  throw `SecretUnavailableException` (T14's fail-loud contract). `resolve()` runs inside `asJob()`
  and `RetryDelivery::handle()`, **before** `DeliverToDestination::handle()` creates the
  `DeliveryAttempt` row — an uncaught throw here would leave AC11's required per-destination Failed
  record, with its value-free `error_summary`, permanently unwritten (the job would simply vanish into
  `failed_jobs`, no delivery-path record of what happened). So this task's own new private
  `signingSecretsFor()` catches the exception and defers it onto the resolved `DeliveryUnit`
  (`signingSecretsUnavailable: ?SecretUnavailableException`, a new `DeliveryUnit` field alongside
  `signingSecrets`) rather than letting it propagate — `resolve()` itself never throws for a signing
  decrypt failure. This task's own Acceptance Criteria doesn't exercise the failure path at all (that's
  explicitly T39's), so nothing here contradicts it; T39 is the task that checks
  `$unit->signingSecretsUnavailable` inside `DeliverToDestination::send()`'s own failure handling and
  pins the resulting all-or-none behaviour — until T39 lands, this deferred field is carried but never
  read, which is itself the gap T39's own task text anticipates finding.

  Four new tests, alongside the existing suite (all still green): the three signing-state cases (off —
  `[]`, no `signingSecretsUnavailable`; on, no overlap — one secret; on, overlap running — two secrets,
  current first, matching `SecretStore::replace()`'s own ordering) and the destination-added-after-signing-was-enabled
  case (AC54), resolving the identical live set as a destination that predates the secret.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "DeliveryUnitResolverTest|OutboundHeadersTest|OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|DeliverToDestinationTest|RetryDeliveryTest|SecretStoreTest"`
  (57 tests, 190 assertions — confirming no regression) all green; full-suite run deferred to the end of
  this batch (T34-T40).

## T37 — Proxy-scoped signing endpoints (AC56, AC57, AC58; plan § API, Technical ruling 5)
- **Description:** `POST proxies/{proxy}/signing` (`ProxySigningController@store`) — always generates a
  new secret (Enable and Regenerate are one action, AC56); returns `application/json`, `{ secret,
  generated_at }`, `Cache-Control: no-store, private` — **the only endpoint in this feature returning
  JSON**, because it carries a secret that must not enter an Inertia prop or the session store
  (Technical ruling 5). `DELETE proxies/{proxy}/signing` (`@destroy`) — disables, deletes every
  `signing` row (`SecretStore::disable()`), Inertia redirect. `DELETE
  proxies/{proxy}/signing/overlap` (`ProxySigningOverlapController@destroy`) — ends the signing
  overlap now, Inertia redirect. All three proxy-scoped, gated `update` via `ProxyPolicy` — no
  destination-scoped route exists anywhere for signing.
- **Dependencies:** T14
- **Files:** `app/Http/Controllers/ProxySigningController.php` (new),
  `app/Http/Controllers/ProxySigningOverlapController.php` (new), `routes/web.php`
- **Acceptance Criteria:**
  - `store` always generates a **different** secret than whatever was previously current, returns it
    exactly once in the JSON body, and the response carries `Cache-Control: no-store, private`.
  - The generated secret never appears in any subsequent page prop or any subsequent response from any
    endpoint (assert by hitting `show`/`edit` immediately afterward).
  - `destroy` deletes every `signing`-purpose row for the proxy; a subsequent `store` produces a value
    different from the deleted one.
  - The overlap-end endpoint stops the previous signing secret verifying/being included in the
    signature list immediately.
  - A Member without `update` rights on the proxy is 403 on all three.
- **Testing:** `tests/Feature/Proxies/ProxySigningControllerTest.php` (new) — one test per bullet.
- **Completion notes:** Done. `ProxySigningController@store` (`POST proxies/{proxy}/signing`) always
  calls `SecretStore::generate()` (Enable and Regenerate are literally the same call, AC56) and returns
  `{ secret, generated_at }` as `application/json` with `Cache-Control: no-store, private` — matching
  `ProxyEventPayloadController`'s own established `response()->json($data, 200, [...])` header-array
  convention rather than the `HttpFoundation` fluent `setPrivate()`/`addCacheControlDirective()` API,
  which does not compose cleanly with `response()->json()`'s own return type. `@destroy` calls
  `SecretStore::disable()` and redirects (`back()`). `ProxySigningOverlapController@destroy`
  (`DELETE proxies/{proxy}/signing/overlap`) calls `SecretStore::endOverlap()` and redirects —
  the signing-surface mirror of `ProxyVerificationOverlapController`, same idempotent-by-construction
  reliance on `SecretStore`, no guard of its own. All three gated `update` via `ProxyPolicy` (no new
  permission); all three routes registered in `routes/web.php` alongside the existing
  `proxies.verification.overlap.destroy` route, no destination-scoped route added anywhere.

  Five tests, one per Acceptance Criteria bullet: two consecutive `store` calls produce different
  secrets, both returned exactly once with the exact `Cache-Control` header, and the live signing set
  afterward is `[second, first]` (current-first, matching `SecretStore::replace()`'s own ordering); the
  generated secret is absent from the content of an immediately-following `show`, `edit` and `index`
  response; `destroy` empties the live signing set and a subsequent `store` produces a value matching
  neither of the two previously-live secrets; the overlap-end endpoint removes the demoted secret from
  the live set immediately; and a Member without update rights on a teammate's proxy is 403 on all three
  endpoints with nothing changed.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "ProxySigningControllerTest|ProxyVerificationOverlapControllerTest"` (9 tests, 39 assertions) all
  green; full-suite run deferred to the end of this batch (T34-T40).

## T38 — `security` prop: the `signing` sub-object (AC54, AC57, AC58; plan § API, Technical ruling 4)
- **Description:** Extends `ProxySecurityResource` with `signing: { enabled, generated_at,
  overlap_expires_at } | null` — status only, never the value. Under ruling B there is no
  per-destination signing flag to carry; this is one object on the shared `security` prop, not a
  per-row field.
- **Dependencies:** T32, T37
- **Files:** `app/Http/Resources/ProxySecurityResource.php`
- **Acceptance Criteria:**
  - The `signing` sub-object correctly reflects not-enabled / enabled-no-overlap / enabled-overlap
    states after the corresponding T37 endpoint calls.
  - The secret's value is never present anywhere in this resource's output.
- **Testing:** extends `tests/Feature/Proxies/ProxySecurityResourceTest.php` — the three-state
  assertion, the no-value assertion.
- **Completion notes:** Done. `ProxySecurityResource` gains a `signing` key, sourced from
  `SecretStore::statusFor($proxy, SecretPurpose::Signing)` — the same T22-established non-value,
  non-length status metadata call, never a direct `proxy_secrets` query (Technical ruling 14). Shaped
  as an always-present object (`enabled`, `generated_at`, `overlap_expires_at`), mirroring
  `verification`'s own established convention of never being `null` itself, with `enabled` playing
  `verification.secret_set`'s presence-only role — this reads the task description's own `| null`
  notation the same way T22's identically-worded `verification: {...} | null` was actually built (a
  status object whose fields go null/false, not a nullable wrapper), for consistency with the sibling
  sub-object the same resource already carries. One object on the shared prop, never a per-destination
  field — there is no per-destination signing state to carry under Amendment B. A proxy that was
  enabled and then disabled reads identically to one that was never enabled, since
  `SecretStore::disable()` (ADR-021 Decision 5) deletes every row and `statusFor()` returns `null` for
  either case — noted inline so a later reader does not read this as a missing "was previously enabled"
  memory.

  **Incidental doc-comment correction, not a deviation:** T32's own class docblock said "`signing` is
  added in a later, out-of-scope-for-this-batch task (T41)" — T41 is actually the Show.vue Signing
  *card* (M8b, a later milestone); this resource's `signing` key is this task, T38, per the plan's own
  M8a/M8b split. Corrected the comment while adding the key it was describing.

  Two new tests, alongside the existing suite (all still green): the three states (not enabled — all
  three fields false/null; enabled, no overlap — `enabled: true`, a real `generated_at`, null overlap;
  enabled with an overlap running — all three populated) driven through real `SecretStore::replace()`
  calls exactly as T37's own endpoints would produce them; and a response-body substring check (both
  `show()` and `edit()`) proving a live signing secret's value never appears in either response.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter ProxySecurityResourceTest`
  (9 tests, 122 assertions) green; also re-ran the full `Proxies` feature suite (198 tests, 1079
  assertions) as a regression check, green. Full-suite run deferred to the end of this batch (T34-T40).

## T39 — AC11 signing all-or-none, dedicated (AC11; plan § Architecture H, PRD-10 `## Amendment B` ruling 1) — **delivery path**
- **Description:** **Pins the partial-fan-out prohibition by name, as its own task**, per this feature's
  explicit re-grained AC11: a proxy whose signing secret cannot be decrypted must dispatch to **none**
  of its destinations for that attempt cycle, never some signed-successfully-elsewhere and some
  silently unsigned. `SecretStore::liveFor()` (T14) already throws `SecretUnavailableException` rather
  than dropping an undecryptable row from the live set; this task proves that exception reaches
  `DeliverToDestination::send()` and fails **every** destination of that proxy's dispatch, before any
  request is made, with the recorded `error_summary` containing no part of the secret (AC61).
- **Dependencies:** T34, T36
- **Files:** none production expected — this task should find T14/T34/T36 already correct and pin the
  behaviour with a test; if it finds a gap (e.g. `send()` catching the exception per-destination rather
  than letting it fail the whole dispatch cycle for the proxy), fix it in
  `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:**
  - A proxy with a signing secret that fails to decrypt (corrupted-ciphertext fixture): **every**
    destination of that proxy fails its attempt for that cycle — none dispatches signed, and **none
    dispatches unsigned** (the forbidden partial-fan-out/fallback state).
  - No destination of that proxy's fan-out succeeds while another fails, for the same underlying cause,
    in the same dispatch cycle.
  - The recorded `error_summary` on every resulting failed attempt names no part of the secret.
  - A proxy with signing **off**, or with a healthy signing secret, is completely unaffected by this
    task (regression guard).
- **Testing:** `tests/Feature/Delivery/SigningAllOrNoneFailureTest.php` (new) — a fixture proxy with 3+
  destinations and a corrupted signing-secret row, asserting all three destinations fail together with
  no part of the secret in any `error_summary`, against a control fixture (healthy secret) where all
  three succeed signed.
- **Completion notes:** Done. **Found a real gap and fixed it, exactly as this task's own Files line
  anticipated.** T36 deliberately does not let `SecretStore::liveFor()`'s `SecretUnavailableException`
  propagate out of `DeliveryUnitResolver::resolve()` (see T36's own completion notes) — it defers the
  exception onto the resolved `DeliveryUnit` (`$signingSecretsUnavailable`) instead, precisely so the
  `DeliveryAttempt` row still gets created before the failure surfaces. Before this task, nothing ever
  read that deferred field: `DeliverToDestination::send()` would have built headers with an EMPTY
  signing-secret list (the safe default `DeliveryUnitResolver` falls back to on a decrypt failure) and
  dispatched every destination **unsigned** — a silent fallback, not a loud failure, and exactly the
  "some signed-successfully-elsewhere and some silently unsigned" state AC11 forbids (here it would
  have been "all silently unsigned," which is the same forbidden fallback-instead-of-failure shape, not
  a partial split, but still never allowed to happen quietly).

  **The fix, entirely inside `app/Actions/DeliverToDestination.php`, nothing else touched:** at the top
  of `send()`'s existing `try` block — before `OutboundHeaders::build()` is ever called, so no header is
  built and no HTTP request is ever made — a new check: `if ($unit->signingSecretsUnavailable !== null)
  { throw $unit->signingSecretsUnavailable; }`. This lands the exception inside the SAME
  `catch (Throwable $e)` block that already handles every other transport/build failure, so the
  attempt's `error_summary` becomes the exception's own fixed, value-free message ("The signing secret
  could not be decrypted.") through the exact same code path already proven for HTTP failures — no new
  failure-recording logic, no duplication. Because every destination of the same proxy reads the
  identical corrupted `proxy_secrets` row through its own independent `asJob()` → `resolve()` call, each
  one is caught here identically — "the whole dispatch cycle for the proxy" fails as an emergent
  property of the shared root cause, not because of any code that coordinates across destinations (none
  exists, and none was added — `DeliverStep.php`/`AdvanceProxyFifoQueue.php` remain untouched, per this
  document's own binding note).

  **How the all-or-none rule is pinned as "not merely one destination failed":** the test drives three
  real destinations of one proxy through the real `DeliverToDestination::asJob()` entry point (not a
  bare `run($unit)` call, so the corrupted-secret path is exercised exactly as production reaches it),
  fakes `Http` with no configured response, and asserts `Http::assertNothingSent()` — proving zero HTTP
  requests were made for ANY of the three destinations, not just that one `DeliveryAttempt` row reads
  Failed. `DeliveryAttempt::count() === 3`, every one `Failed`, and zero `Succeeded`, closes the
  "no destination succeeds while another fails" bullet directly rather than by inference from a single
  destination's result.

  **Queue faked, and why:** a failed attempt schedules a real, delayed `RetryDelivery` (T14/T15); under
  this project's `QUEUE_CONNECTION=sync` a delayed dispatch still runs inline unless the queue is faked,
  which — discovered while first running this test unfaked — cascaded each destination's own corrupted
  secret through its full retry limit (3 destinations × 5 attempts = 15 rows, not 3), a real but
  unrelated interaction with the pre-existing retry engine rather than a defect in this task's own fix.
  `Queue::fake()` isolates the test to attempt 1 for each destination — exactly "that attempt cycle,"
  the AC's own words.

  Two regression-guard tests, per the task's own fourth bullet: a proxy with signing off dispatches and
  succeeds exactly as before this task; a proxy with a healthy (uncorrupted) signing secret dispatches
  signed to all three destinations, `webhook-signature` present on every request.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "SigningAllOrNoneFailureTest|DeliverToDestinationTest|RetryDeliveryTest|DeliveryUnitResolverTest|OutboundHeadersTest|OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|SecretStoreTest"`
  (60 tests, 205 assertions — confirming no regression) all green; full-suite run deferred to the end of
  this batch (T34-T40).

  **Delivery-path caveat, stated per the task list's own instruction:** `QUEUE_CONNECTION=sync` runs
  `asJob()` inline under this suite, so this proves the *logic* — the deferred exception reaching
  `send()`, the shared-cause independent-failure mechanism — is correct when each destination's job is
  invoked, but exercises none of Horizon's real concurrent/async dispatch of those same three jobs.

## T40 — Outbound signing integration test suite (AC54–AC64) — **delivery path**
- **Description:** No production code — the end-to-end pinning pass across T34–T39, through the full
  send/retry/replay path.
- **Dependencies:** T36, T37, T39
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - **AC54:** enabling signing on a proxy signs dispatches to every destination, including one added
    afterward, under the same secret; a proxy without a signing secret signs none of its destinations.
  - **AC55/AC59:** the signature verifies against the specification, computed over the exact dispatched
    bytes; the body is byte-identical to the unsigned case.
  - **AC56/AC57:** the secret is generated, returned once as JSON with `no-store`, absent from every
    subsequent page prop and response; `whsec_`-prefixed base64.
  - **AC58:** during an overlap the header carries one entry per live secret and each verifies; after
    expiry, one.
  - **AC60:** `webhook-id` identical on attempt 1 and its retry, different on a replay, different per
    destination of one dispatch.
  - **ADR-021 Decision 5:** disabling deletes every row; re-enabling generates a different secret.
  - **AC61:** the signing secret appears nowhere but the one-time display and the signature computation
    — not in a queued job's arguments (asserted positionally on the serialized job, as
    `AdvanceProxyFifoQueueTest` does for its own scalars), not in a delivery-attempt record, not in
    analytics, not in a failure record, not in a log line, not in any payload view.
  - **AC64:** with signing on, the outbound `webhook-*` headers are the proxy's own even when the
    inbound request carried them.
  - **R3, composed with T27/T36:** a retry whose proxy has been soft-deleted still resolves, still
    applies AC27's strip, and still signs.
- **Testing:** `tests/Feature/Delivery/OutboundSigningIntegrationTest.php` (new) — one test method per
  bullet.
- **Completion notes:** Done. New test-only file, `tests/Feature/Delivery/OutboundSigningIntegrationTest.php`,
  nine test methods (one per named bullet, `AC55/AC59` and `AC56/AC57` each folded into one method as
  the bullets themselves already pair them) — real HTTP requests throughout (`$this->postJson()` against
  the ingest route, `$this->post()`/`postJson()` against the signing/replay management routes), through
  the real send/retry/replay path, no production code touched.

  **AC54:** signing off produces no `webhook-signature` on any destination; enabling signing and adding a
  destination afterward signs both the pre-existing and the newly-added destination identically, with no
  per-row lookup.

  **AC55/AC59:** a signing proxy and a non-signing twin dispatch byte-identical bodies for the same
  payload; the signing proxy's signature verifies via `StandardWebhooks::verify()` against the exact
  dispatched bytes.

  **AC56/AC57:** the real `POST proxies/{proxy}/signing` endpoint's generated secret is `whsec_`-prefixed
  with valid base64 material after the prefix, returned with `Cache-Control: no-store, private`, and
  absent from an immediately-following `show` response — composing with, not duplicating, T37's own
  fuller endpoint coverage.

  **AC58:** an overlap of two live secrets produces a two-entry `webhook-signature`, each verifying
  independently against its own secret; pushing the demoted secret's `expires_at` into the past (no
  sweeper run) collapses a subsequent dispatch to exactly one entry.

  **AC60:** attempt 1 (failed) and its real, sync-driver-drained retry carry the identical `webhook-id`;
  a second destination of the same dispatch carries a different one despite the shared signing key; a
  replay of the same event through the real `POST .../events/{event}/replay` endpoint mints a fresh
  `dispatch_uuid` and therefore a new `webhook-id`.

  **ADR-021 Decision 5:** the real `DELETE`/`POST proxies/{proxy}/signing` endpoints empty the live set
  and then generate a value distinct from the one just disabled; a dispatch afterward verifies against
  only the new secret, never the disabled one.

  **AC61:** the widest-reaching method in this suite — a positional assertion on
  `DeliverToDestination`'s pushed job parameters (`$params === [$delivery->id, 1]`, the same technique
  `AdvanceProxyFifoQueueTest` already established for its own scalars) proves only two integers ever
  travel with the queued job; the entire serialized job payload, the resulting `DeliveryAttempt` row's
  every string attribute, a forced failure's `error_summary`, the proxy's `show`/`edit` responses, the
  events index and event show pages, and the event's payload-view response are all swept for the
  secret's literal substring, plus a `Log::listen()` collector run across the whole method proving no log
  line at any level ever carried it.

  **AC64:** an ingest request carrying attacker-forged `webhook-id`/`webhook-timestamp`/`webhook-signature`
  headers never lets those values reach the destination — the proxy's own signing headers win by
  precedence, and the outbound signature verifies against the real signing secret, not the forged one.

  **R3:** a delivery whose proxy is soft-deleted before its retry runs still resolves via
  `RetryDelivery::handle()`, still strips the proxy's own verification header outbound, and still signs
  — composed directly against T27's own established soft-deleted-proxy regression shape rather than a
  fresh fixture.

  **Two genuine test-authoring bugs found and fixed while building this suite, both isolated by dumping
  actual state rather than guessing — neither is a defect in T34–T39's production code, confirmed by the
  full delivery-path suite staying green throughout:**
  1. `Illuminate\Http\Client\Factory::fake()`, given an array, MERGES each call's stub onto the
     existing `stubCallbacks` collection rather than replacing it, and request resolution takes the
     FIRST matching stub. A second `Http::fake(['*' => Http::response('boom', 500)])` call later in the
     same test therefore never overrides an earlier `Http::fake(['*' => Http::response('ok', 200)])`
     registered against the same `'*'` pattern — the earlier 200 stub keeps winning silently. AC61's own
     "failure record" section needed genuinely different behaviour mid-test, so it now uses one
     `Http::fake()` closure for the whole method, branched on a mutable `$shouldFail` flag, rather than a
     second array-form call.
  2. That flag was first captured with an arrow `fn () => $shouldFail ? ...`, which captures its
     enclosing variables **by value at definition time** — silently freezing `$shouldFail` at `false`
     forever regardless of the later `$shouldFail = true;` reassignment. Fixed by using a plain
     `function () use (&$shouldFail) { ... }` closure (by reference) instead.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "OutboundSigningIntegrationTest|SigningAllOrNoneFailureTest|DeliverToDestinationTest|RetryDeliveryTest|DeliveryUnitResolverTest|OutboundHeadersTest|OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|SecretStoreTest|ProxySigningControllerTest|ProxySecurityResourceTest|ReplayAcceptanceTest|AsyncDispatchAcceptanceTest"`
  (100 tests, 491 assertions) all green. **Full suite run at the close of this batch (T34-T40):
  `./vendor/bin/sail test --parallel` green at 1063/1063.**

  **Delivery-path caveat, stated per the task list's own instruction:** `QUEUE_CONNECTION=sync` runs
  every job inline under this suite — including the "real, sync-driver-drained retry" this suite itself
  relies on for AC60 — so this proves the *logic* across T34-T39 is correct when exercised end to end,
  but exercises none of Horizon's real concurrent/async dispatch of the same jobs. No claim of having
  exercised the async path is made.

---
