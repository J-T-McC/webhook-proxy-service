<?php

namespace App\Services;

use App\Enums\VerificationScheme;
use App\Models\Delivery;
use App\Models\Proxy;
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
 *
 * Also loads the proxy `withTrashed()` (T27, R3; a plain `belongsTo` on a
 * `SoftDeletes` model resolves `null` for a soft-deleted proxy, which PHPStan
 * cannot see) so a retry against a soft-deleted proxy still resolves — and
 * carries that proxy's own verification header name(s) on the resulting unit
 * for `OutboundHeaders`' strip step (T26) at send time.
 *
 * Also carries `$delivery->dispatch_uuid` (T34; ADR-023 Decision 3) — with
 * the destination's id, the ingredients `OutboundHeaders` derives
 * `webhook-id` from at send time, needing no new column.
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
        $proxy = $delivery->proxy()->withTrashed()->firstOrFail();

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
            verificationHeaderNames: $this->verificationHeaderNamesFor($proxy),
            dispatchUuid: $delivery->dispatch_uuid,
        );
    }

    /**
     * This proxy's own inbound verification header name(s), to be stripped
     * outbound (AC27) — the member-named header under `shared-secret`, the
     * three fixed Standard Webhooks headers under `standard-webhooks`, or
     * none when verification is not required (AC43: nothing strips a
     * `webhook-signature` a sender happened to send when there is no
     * verification configuration to strip it for).
     *
     * @return list<string>
     */
    private function verificationHeaderNamesFor(Proxy $proxy): array
    {
        return match ($proxy->verification_scheme) {
            null => [],
            VerificationScheme::SharedSecret => $proxy->verification_header_name !== null
                ? [$proxy->verification_header_name]
                : [],
            VerificationScheme::StandardWebhooks => ['webhook-id', 'webhook-timestamp', 'webhook-signature'],
        };
    }
}
