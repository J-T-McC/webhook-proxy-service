<?php

namespace App\Events;

use App\Actions\DeliverToDestination;
use App\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a delivery reaches its terminal `failed` state — the resolved
 * retry-attempt limit (`RetryPolicy::attemptLimitFor()`) was reached (ADR-015
 * Decision 6). The compare-and-set transition to `failed` in
 * {@see DeliverToDestination} is the once-guard: it fires iff
 * that CAS affected a row, so a racing duplicate settle never re-emits it.
 * No listener is registered at #6 — this is the seam #13 (notifications)
 * subscribes to later. Team/proxy/destination/event are all reachable via
 * the carried `Delivery` model's relations.
 */
class DeliveryExhausted
{
    use Dispatchable;

    public function __construct(public readonly Delivery $delivery) {}
}
