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
