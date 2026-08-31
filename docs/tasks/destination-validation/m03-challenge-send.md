# M3 — The guarded challenge send

Implements plan-18 § Services and ADR-027 decision 3. This milestone builds the outbound request the
whole feature exists to make safe: a request to a URL nobody has vouched for yet.

## T8 — `OutboundAddressGuard`

- **Description:** Resolve a host to its full address set, refuse if **any** returned address is
  loopback, private, link-local, unique-local or cloud-metadata, and expose the validated address so
  the caller can pin to it. **Fails closed**: if the host cannot be resolved, or the transport cannot
  pin, the guard refuses rather than allowing an unpinned send. The only custom security code in the
  feature — nothing first-party does this.
- **Dependencies:** none.
- **Files:** `app/Services/OutboundAddressGuard.php`
- **AC-trace:** PRD-18 AC20, AC40; ADR-027 decision 3; Q-18-01 answer 3.
- **Verify step:** the guard refuses `http://127.0.0.1`, `http://169.254.169.254`, `http://10.0.0.1`
  and a hostname resolving to any of them, and permits a public address.
- **Testing:** `tests/Unit/Services/OutboundAddressGuardTest.php` — each refused range, IPv4 and
  IPv6 including IPv4-mapped forms, a multi-address host where only one address is private (must
  refuse), and unresolvable hosts (must refuse).

- **Completion notes:** Done, 2026-08-31. `OutboundAddressGuard` reuses `IngestHostGuard`'s parsing
  helpers and PHP's own `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE`, adding only the four CIDRs those
  flags miss — carrier-grade NAT (which is where Alibaba's metadata endpoint lives), IETF protocol
  assignments, benchmarking and multicast. IPv4-mapped IPv6 is unwrapped and judged by the IPv4
  rules, since the mapping is otherwise a trivial bypass. 23 tests including the rebinding proof:
  a resolver that answers public then loopback cannot reach loopback, because the caller connects to
  the returned address rather than resolving again.

## T9 — `SendDestinationValidationChallenge`

- **Description:** Dispatchable action (`AsJob`) that builds the fixed challenge body, applies T8's
  guard and pins the connection to the checked address via cURL's `CURLOPT_RESOLVE` through the HTTP
  client's Guzzle options, sends with redirects refused, and records the outcome on the destination —
  moving it to `pending`, minting a fresh `validation_nonce` and setting both challenge timestamps.
  A redirect response is a failed validation send, not something to follow.
  **It does not route through `DeliverToDestination` or the pipeline, creates no `deliveries` or
  `delivery_attempts` rows, and never attaches the destination's stored credential.**
- **Dependencies:** T2, T8.
- **Files:** `app/Actions/SendDestinationValidationChallenge.php`
- **AC-trace:** PRD-18 AC14, AC15, AC17, AC18, AC19, AC22; ADR-027 decisions 1 and 3.
- **Verify step:** run against a fake endpoint; the destination moves to pending with a nonce and a
  7-day expiry, and the outbound request carries no credential header.
- **Testing:** own test group — the credential is absent from the request (AC17, the
  exfiltration guard); a redirect response fails the send rather than being followed; a fresh send
  replaces the previous nonce, rendering the old link inert; no `deliveries` or `delivery_attempts`
  row is created; the payload is the fixed body and carries no event data.

- **Completion notes:** Done, 2026-08-31. Pins via `CURLOPT_RESOLVE` through the HTTP client's Guzzle
  options and refuses redirects with the first-party `withoutRedirecting()`. The nonce is minted
  before the send and persisted only after it succeeds, so a failed send never leaves a destination
  pending against a link nobody received. Eight tests, including the AC17 credential-exfiltration
  guard and proof that no `deliveries` or `delivery_attempts` row is created.
  **Sequencing note for the task plan:** T9 could not be built before T12 as ordered, because a
  signed URL cannot be minted for a route that does not exist. The two public routes and the
  controller were therefore created here rather than in M4.

## T10 — Rate limits on validation sends

- **Description:** Three named limiters via the `RateLimiter` facade, following the existing pattern
  in `FortifyServiceProvider`: one send per destination per five minutes, ten per destination per
  24 hours, one hundred per team per 24 hours. A blocked send reports **when it may be retried**.
- **Dependencies:** T9.
- **Files:** `app/Providers/AppServiceProvider.php` (or a dedicated provider, matching the existing
  limiter registration style), `app/Actions/SendDestinationValidationChallenge.php`
- **AC-trace:** PRD-18 AC21.
- **Verify step:** two sends inside five minutes; the second is refused with a retry-after.
- **Testing:** each of the three limits, and that a rate-limited automatic send on create still
  **saves the destination** — PM ruling 1: the destination saves even when the send is blocked.

- **Completion notes:** Done, 2026-08-31. Used the `RateLimiter` facade directly rather than
  registering a named limiter in a provider as the task suggested: named limiters exist to be
  resolved by the `throttle` middleware, and this is not an HTTP boundary. Limits live in a new
  `config/destination_validation.php` alongside the challenge lifetime and timeout.
  **Worth knowing:** `RateLimiter::tooManyAttempts()` with a max of zero does NOT block a first
  call — it only blocks once a timer key exists. A test written against a zero limit passes for the
  wrong reason. The tests use a spent real limit instead.

## T11 — Automatic send on create and on URL change

- **Description:** Creating a destination dispatches a challenge. Changing a destination's `url`
  resets it to `unvalidated`, clears the nonce and both challenge timestamps — voiding any
  outstanding link — and dispatches a fresh challenge. Both inside the controller's transaction.
- **Dependencies:** T9, T10.
- **Files:** the destination store/update controller and its Form Request
- **AC-trace:** PRD-18 AC5, AC15; PM ruling 1.
- **Verify step:** create a destination and confirm a challenge is queued; edit its URL and confirm
  it returns to unvalidated with a new nonce.
- **Testing:** create dispatches; a URL edit resets state, voids the old nonce and dispatches; an
  edit that does **not** touch the URL leaves a validated destination validated (AC13 — configuration
  is not gated); a rate-limited send still saves.

- **Completion notes:** Done, 2026-08-31. Ids are collected inside the transaction and dispatched
  after it commits, so a rolled-back create never challenges a destination that does not exist.
  **A real defect was caught here by the test.** The URL-change reset was silently dropped, because
  the new `validation_*` columns are not in the model's `#[Fillable]` list and `update()` therefore
  ignored them. Fixed with `forceFill`, and the exclusion from `#[Fillable]` was kept deliberately
  and documented on the model: nothing arriving from a request payload may mass-assign a destination
  into the validated state (AC3 — exactly one route to Validated).
