<?php

namespace App\Services;

use App\Enums\SecretPurpose;
use App\Exceptions\SecretUnavailableException;
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
 * cannot see) so a retry against a soft-deleted proxy still resolves — the
 * proxy's live signing set below is what this load is now sufficient for
 * alone (ADR-026 Decision 3).
 *
 * Also carries `$delivery->dispatch_uuid` (T34; ADR-023 Decision 3) — with
 * the destination's id, the ingredients `OutboundHeaders` derives
 * `webhook-id` from at send time, needing no new column.
 *
 * Asks `SecretStore` for the proxy's live `signing` secret set (T36; plan-10
 * Technical ruling 14) once per resolve, so `OutboundHeaders` (T34) has what
 * it needs at send time without querying `proxy_secrets` directly. A
 * `SecretUnavailableException` here is deliberately NOT thrown out of
 * `resolve()` — this runs before `DeliverToDestination::handle()` creates the
 * `DeliveryAttempt` row, so an uncaught throw here would leave AC11's
 * required per-destination Failed record with an `error_summary` unwritten.
 * It is carried on the resolved `DeliveryUnit` instead
 * ({@see DeliveryUnit::$signingSecretsUnavailable}) and surfaced inside
 * `DeliverToDestination::send()`'s own failure handling (T39), so every
 * destination of the proxy fails its attempt identically, with a recorded,
 * value-free reason, rather than the job simply vanishing.
 */
class DeliveryUnitResolver
{
    public function __construct(
        private readonly StoredPayloadLookup $payloads,
        private readonly SecretStore $secrets,
    ) {}

    public function resolve(Delivery $delivery, int $attemptNumber): ?DeliveryUnit
    {
        $event = $delivery->webhookEvent;

        if ($event->payload_cleaned_at !== null) {
            return null;
        }

        $destination = $delivery->destination()->withTrashed()->firstOrFail();
        $proxy = $delivery->proxy()->withTrashed()->firstOrFail();

        [$signingSecrets, $signingSecretsUnavailable] = $this->signingSecretsFor($proxy);

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
            dispatchUuid: $delivery->dispatch_uuid,
            signingSecrets: $signingSecrets,
            signingSecretsUnavailable: $signingSecretsUnavailable,
        );
    }

    /**
     * The proxy's live `signing` secret set (T36; AC54, AC60) — 0, 1 or 2
     * entries (AC29's cap), current first. `SecretStore::liveFor()` is a
     * single lookup on the proxy already loaded above, so every destination
     * of a signing-enabled proxy is signed with it uniformly (AC54) — there is
     * no per-row lookup for this to miss a destination added after signing was
     * enabled. A decrypt failure is caught and deferred rather than thrown —
     * see this class's own docblock for why.
     *
     * @return array{0: list<string>, 1: SecretUnavailableException|null}
     */
    private function signingSecretsFor(Proxy $proxy): array
    {
        try {
            return [$this->secrets->liveFor($proxy, SecretPurpose::Signing), null];
        } catch (SecretUnavailableException $e) {
            return [[], $e];
        }
    }
}
