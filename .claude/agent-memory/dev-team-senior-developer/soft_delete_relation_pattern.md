---
name: soft-delete-relation-pattern
description: This app's established fix for a belongsTo to a SoftDeletes model feeding a non-nullable typed parameter — fetch trashed-inclusive at the call site, don't widen the consumer
metadata:
  type: project
---

A default `belongsTo` relation to a model using `SoftDeletes` (`Proxy`, `Team`) silently
resolves to `null` once the parent is soft-deleted — the parent's own global scope excludes
it. This app has repeated precedent for handling that at the *call site* rather than by
widening the consumer's type: `ProcessIngestedWebhook.php` loads
`Proxy::withTrashed()->findOrFail($event->proxy_id)`, `DeliverStep.php`/`RetryDelivery.php`
load `destination()->withTrashed()`, and (fixed 2026-08-25,
[[soft-deleted-proxy-retry-typeerror]]) `DeliverToDestination`/`DeliveryResource` now load
`delivery->proxy()->withTrashed()->firstOrFail()` before handing the result to
`RetryPolicy::attemptLimitFor(Proxy $proxy)` (non-nullable — it is documented as the single
resolver of retry policy, ADR-015 Decision 3; do not widen it to accept null).

**Consequence for PHPStan/model docblocks:** a `@property-read Proxy $proxy` annotation on a
model whose relation is a plain trashed-exclusive `belongsTo` to a `SoftDeletes` model is
factually wrong (should be `Proxy|null`) and will hide exactly this bug from PHPStan level 7 —
check the annotation matches real nullability before trusting it silences a real null-safety
gap. `JsonResource::__call`/`__get` (`DelegatesToResource`) forward property/method access to
the underlying Eloquent model, so `$this->proxy()->withTrashed()->first()` inside a Resource's
`toArray()` works exactly like calling it on the model directly, regardless of what an
eager-loaded (trashed-exclusive) relation on the resource already holds.
