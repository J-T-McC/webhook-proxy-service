<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Pipeline\DeliveryUnit;
use App\Services\StoredPayloadLookup;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsJob;

/**
 * Executes retry attempts (N ≥ 2) by reference (ADR-015 Decision 5, plan-06
 * §Architecture A). Reloads the `Delivery` and, unless its status is STILL
 * `retrying`, does nothing — a stale/superseded job (e.g. the sweeper (T15)
 * and this delivery's own delayed job both firing, or the delivery already
 * settled by another path). No attempt row, no event, no send (PRD-06 AC17:
 * "a cleaned event produces zero new delivery attempts except by rejecting
 * the request cleanly" — the same zero-new-attempts posture applies to any
 * stale fire).
 *
 * Otherwise guards the parent `WebhookEvent`'s `payload_cleaned_at`
 * (ADR-014 Decision 7, binding): cleaned ⇒ CAS the delivery straight to
 * `failed` (bypassing {@see DeliverToDestination} entirely — no attempt is
 * ever made, so no attempt row is written, matching AC17 literally), emit
 * `DeliveryExhausted` iff the CAS affected a row (the same once-guard shape
 * as T13's `settleDelivery`), log `payload.expired` (identifiers only —
 * never payload content), and send nothing.
 *
 * Otherwise resolves the bytes to (re)send as the recorded dispatched output
 * — `StoredPayloadLookup::dispatchedBytesFor()` (T12; ADR-013 Decision 3) —
 * rebuilds the {@see DeliveryUnit} (headers from the captured event row,
 * method from the destination loaded `withTrashed()` per plan-06 ruling 2,
 * this delivery's id, the given attempt number), and runs
 * `DeliverToDestination::run()`, which performs T13's settle/schedule logic
 * identically to attempt 1. No payload bytes are ever carried in this job's
 * own arguments (ADR-015 Decision 5) — only `$deliveryId`/`$attemptNumber`.
 */
class RetryDelivery
{
    use AsJob;

    public int $tries = 1;

    public function __construct(private readonly StoredPayloadLookup $payloads) {}

    public function handle(int $deliveryId, int $attemptNumber): void
    {
        $delivery = Delivery::query()->find($deliveryId);

        if ($delivery === null || $delivery->status !== DeliveryStatus::Retrying) {
            return;
        }

        $event = $delivery->webhookEvent;

        if ($event->payload_cleaned_at !== null) {
            $this->terminalizeCleaned($delivery, $event->ingest_id);

            return;
        }

        $destination = $delivery->destination()->withTrashed()->firstOrFail();

        $unit = new DeliveryUnit(
            ingestId: $event->ingest_id,
            teamId: $delivery->team_id,
            proxyId: $delivery->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: $event->headers,
            payload: $this->payloads->dispatchedBytesFor($event),
            deliveryId: $delivery->id,
            attemptNumber: $attemptNumber,
        );

        DeliverToDestination::run($unit);
    }

    /**
     * Terminalize a delivery whose parent event's payload has been erased
     * before this retry could run — AC17's structural guard: no send is ever
     * attempted, so no attempt row is ever written. Compare-and-set keyed on
     * `retrying` (the only status this method is ever reached from); a
     * zero-row CAS means another settler already won and this is a no-op.
     */
    private function terminalizeCleaned(Delivery $delivery, string $ingestId): void
    {
        $affected = Delivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Retrying)
            ->update(['status' => DeliveryStatus::Failed, 'next_attempt_at' => null]) > 0;

        if (! $affected) {
            return;
        }

        $delivery->status = DeliveryStatus::Failed;
        $delivery->next_attempt_at = null;

        event(new DeliveryExhausted($delivery));

        Log::info('payload.expired', ['ingest_id' => $ingestId]);
    }
}
