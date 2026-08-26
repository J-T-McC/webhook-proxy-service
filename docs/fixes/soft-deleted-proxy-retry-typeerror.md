# Fix: soft-deleted-proxy-retry-typeerror

- **Date:** 2026-08-25
- **Author:** Senior Developer
- **Reported by:** Principal Engineer

## Problem
`DeliverToDestination::settleDelivery()` (`app/Actions/DeliverToDestination.php:197`)
reads `$delivery->proxy` and passes the result straight to
`RetryPolicy::attemptLimitFor(Proxy $proxy): int` (`app/Services/RetryPolicy.php:38`),
a non-nullable typed parameter. `Delivery::proxy()` (`app/Models/Delivery.php:62`) is a
plain `belongsTo`, and `Proxy` uses `SoftDeletes` — once a proxy is soft-deleted, the
default relation scope excludes it and resolves to `null`. A delivery attempt that
fails against a since-soft-deleted proxy therefore throws a `TypeError` from inside
the queue worker (or the FIFO inline path) instead of settling. The same shape existed
at `app/Http/Resources/DeliveryResource.php:40`
(`app(RetryPolicy::class)->attemptLimitFor($this->proxy)`), which would 500 the events
read surface for any delivery under a soft-deleted proxy.

PHPStan level 7 did not catch either call site because `Delivery`'s docblock asserted
`@property-read Proxy $proxy` (non-nullable) — a documentation bug that hid a real one.

## Cause
`$delivery->proxy` uses the default `belongsTo` relation, which carries `Proxy`'s own
`SoftDeletes` global scope and therefore excludes a soft-deleted proxy, resolving to
`null`. Both consumers assumed a non-null result.

## Chosen behaviour
An in-flight (or historical) delivery continues to resolve its retry policy from the
proxy's **own** settings, exactly as if the proxy had not been soft-deleted, rather
than falling back to the system default policy or refusing to settle. Reasoning:

- This is not a new design decision — it is the exact precedent already established
  and applied elsewhere in this codebase for "a parent was soft-deleted after a child
  row referencing it was created": `ProcessIngestedWebhook.php:50` loads
  `Proxy::withTrashed()->findOrFail($event->proxy_id)` ("an event captured before a
  later soft-delete of its proxy must still deliver — ADR-011 Decision 3"), and
  `DeliverStep.php:45` / `RetryDelivery.php:64` load the destination
  `withTrashed()` for the identical reason. Extending the same rule to the proxy for
  retry-policy resolution keeps the behaviour uniform across the pipeline rather than
  introducing a fourth, different rule for the fourth soft-deletable parent.
- **Rejected: widen `RetryPolicy::attemptLimitFor()` to accept `?Proxy`.**
  `RetryPolicy` is documented as the **single resolver** of the two `proxies` retry
  columns and of `config('retry.*')` (ADR-015 Decision 3, plan-06 binding invariant).
  Making its signature nullable would push a "what does null mean" policy decision
  (system default? reject?) into the one class that is supposed to be the sole,
  narrow authority on retry policy, and would silently paper over every future
  caller's own null-handling bug the same way the wrong non-null annotation papered
  over this one. Fetching the proxy trashed-inclusive at the two call sites keeps
  `RetryPolicy`'s contract exactly as ADR-015 defined it.
- **Rejected: fall back to the system-default policy for a soft-deleted proxy.**
  This would silently change an in-flight delivery's attempt limit/backoff strategy
  mid-flight the moment its proxy is deleted, with no signal to anyone that the
  policy shifted. The proxy's own configured policy (its actual retained column
  values — soft delete does not erase them) is the correct, and the least
  surprising, drop-in continuation.
- **Rejected: settle straight to failed on a soft-deleted proxy.** Nothing in the
  PRD/ADR set treats "proxy soft-deleted" as a reason to abandon retries of deliveries
  already dispatched under it, and doing so would need its own product decision
  (out of scope for a fast-path fix).

## Fix
- `app/Actions/DeliverToDestination.php:197` — `$delivery->proxy` →
  `$delivery->proxy()->withTrashed()->firstOrFail()`.
- `app/Http/Resources/DeliveryResource.php:40` — `$this->proxy` →
  `$this->proxy()->withTrashed()->firstOrFail()` (`JsonResource::__call` forwards the
  method call to the underlying `Delivery` model, so this resolves fresh regardless of
  what the eager-loaded `proxy` relation on the resource holds).
- `app/Models/Delivery.php` — corrected the `@property-read Proxy $proxy` annotation
  to `@property-read Proxy|null $proxy` (it was never actually guaranteed non-null;
  the wrong annotation is what hid this from PHPStan) and documented on the `proxy()`
  relation itself that a consumer needing trashed-inclusive resolution must ask for it
  explicitly (`proxy()->withTrashed()->firstOrFail()`), pointing at `RetryPolicy::
  attemptLimitFor()` as the reason.
- Checked every caller of `$delivery->proxy` (the only two in the codebase, both fixed
  above) and every caller of `RetryPolicy::attemptLimitFor()`/`delayBefore()` (the same
  two call sites) — no other consumer needed the same fix.

## Verification
- New regression tests, confirmed to fail with the exact reported `TypeError` before
  the fix (verified via `git stash` of the three source files, tests kept), and pass
  after:
  - `tests/Feature/Delivery/DeliverToDestinationTest::test_a_failed_attempt_settles_instead_of_throwing_when_the_proxy_has_been_soft_deleted`
    (below the soft-deleted proxy's own limit — settles to `retrying`, schedules the
    retry).
  - `tests/Feature/Delivery/DeliverToDestinationTest::test_a_failed_attempt_at_the_limit_exhausts_instead_of_throwing_when_the_proxy_has_been_soft_deleted`
    (at the soft-deleted proxy's own limit — settles to `failed`, fires
    `DeliveryExhausted` exactly once).
  - `tests/Unit/Http/Resources/DeliveryResourceTest::test_attempt_limit_still_resolves_when_the_proxy_has_been_soft_deleted`.
- `./vendor/bin/sail test --filter "DeliverToDestinationTest|DeliveryResourceTest"`:
  24 passed / 81 assertions.
- `./vendor/bin/sail test --parallel` (full suite): **716 passed / 716, 2618
  assertions** — fully green.
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.

## Follow-ups
None.
