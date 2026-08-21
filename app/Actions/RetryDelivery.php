<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsJob;

/**
 * Executes retry attempts (N ≥ 2) by reference (ADR-015 Decision 5). T13
 * dispatches this job from {@see DeliverToDestination}'s post-settle CAS
 * transition as a named forward reference — the `AsJob` dispatch surface
 * (`$tries`, `::dispatch()`/`::assertPushed()`) is wired here so that call
 * compiles and is assertable, but `handle()` is intentionally a bare no-op:
 * T14 implements the real body (reload the delivery, skip unless still
 * `retrying`, guard the parent event's `payload_cleaned_at`, resolve the
 * recorded dispatched output via `StoredPayloadLookup`, rebuild the
 * `DeliveryUnit`, and run `DeliverToDestination::run()`).
 */
class RetryDelivery
{
    use AsJob;

    public int $tries = 1;

    /**
     * T14 implements this. Left a deliberate no-op for now so a delivery
     * scheduled for retry under T13 alone — including via the real `sync`
     * queue driver in tests without `Queue::fake()` — does nothing rather
     * than failing loudly on not-yet-built behaviour.
     */
    public function handle(int $deliveryId, int $attemptNumber): void
    {
        // Intentionally empty — implemented in T14.
    }
}
