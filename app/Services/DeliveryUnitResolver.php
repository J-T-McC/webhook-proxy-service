<?php

namespace App\Services;

use App\Models\Delivery;
use App\Pipeline\DeliveryUnit;

/**
 * The single resolver of a {@see DeliveryUnit} from `(Delivery, int $attemptNumber)`
 * (ADR-020 Decision 7/Impact). Used by both delivery entry points —
 * `DeliverToDestination`'s by-reference job for attempt 1 and `RetryDelivery` for
 * attempts 2..N — so the two are provably identical rather than merely similar.
 *
 * Guards the parent `WebhookEvent`'s `payload_cleaned_at` (ADR-014 Decision 7,
 * binding) and returns `null` — never an empty payload — to signal "parent
 * cleaned" distinguishably from a resolved unit. The caller is responsible for
 * terminalizing on a `null` result (see `RetryDelivery::terminalizeCleaned()`,
 * whose shape both callers follow).
 *
 * Otherwise loads the destination `withTrashed()` (a destination soft-deleted
 * after its delivery row was created still receives its attempt, ruling 2),
 * takes headers from the captured event row, and resolves the dispatched bytes
 * via {@see StoredPayloadLookup::dispatchedBytesFor()} — the only interpreter of
 * `dispatched_payloads.body IS NULL` (ADR-013 Decision 3), which this class never
 * duplicates.
 */
class DeliveryUnitResolver
{
    public function __construct(private readonly StoredPayloadLookup $payloads) {}

    public function resolve(Delivery $delivery, int $attemptNumber): ?DeliveryUnit
    {
        $event = $delivery->webhookEvent;

        if ($event->payload_cleaned_at !== null) {
            return null;
        }

        $destination = $delivery->destination()->withTrashed()->firstOrFail();

        return new DeliveryUnit(
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
    }
}
