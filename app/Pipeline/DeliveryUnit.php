<?php

namespace App\Pipeline;

use App\Exceptions\SecretUnavailableException;
use App\Models\Destination;

/**
 * A plain per-destination unit of work — the input to the DeliverToDestination
 * action, distinct from the in-memory {@see PipelineContext}.
 */
final class DeliveryUnit
{
    /**
     * Inbound header names that are NEVER forwarded to a destination (ADR-008).
     *
     * A maintained deny-list (lowercased for case-insensitive matching), extensible
     * by later items (#10) without touching the fan-out logic. `Content-Type` and
     * every other benign descriptive header are forwarded.
     *
     * @var list<string>
     */
    public const STRIPPED_HEADERS = [
        // Host — destination's own host must be used (ADR-006 guard).
        'host',
        // Hop-by-hop (RFC 7230 §6.1) + Content-Length (recomputed by the client).
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'content-length',
        // Inbound session/cookie state.
        'cookie',
        // Sender's credential to us — not the destination's.
        'authorization',
        // Inbound webhook signature / verification headers (computed for us, not the
        // destination). Outbound signing is #10.
        'stripe-signature',
        'x-hub-signature',
        'x-hub-signature-256',
        'x-signature',
        'x-webhook-signature',
    ];

    /**
     * @param  array<string, list<string|null>>  $headers  inbound headers
     * @param  int  $deliveryId  the `deliveries` row this attempt belongs to (ADR-015 Decision 1)
     * @param  string  $dispatchUuid  the owning `Delivery`'s natural-key half (T34; ADR-023 Decision
     *                                3) — with {@see self::$destination}'s id, derives `webhook-id`
     *                                without a new column. Defaulted to `''` so every pre-#10
     *                                construction site (delivery-path tests unrelated to #10) stays
     *                                valid unchanged.
     * @param  list<string>  $signingSecrets  the proxy's live `signing`-purpose secret set (T36) —
     *                                        current first, at most two (AC29's cap); empty when
     *                                        signing is not enabled, in which case `OutboundHeaders`
     *                                        (T34) adds no signing headers at all (AC63).
     * @param  SecretUnavailableException|null  $signingSecretsUnavailable  set instead of
     *                                                                      `$signingSecrets` throwing out of `DeliveryUnitResolver::resolve()`
     *                                                                      (T36) when the proxy's signing secret cannot be
     *                                                                      decrypted — carried here so the failure surfaces inside
     *                                                                      `DeliverToDestination::send()` instead (T39, AC11's
     *                                                                      all-or-none rule), after the `DeliveryAttempt` row
     *                                                                      already exists.
     */
    public function __construct(
        public readonly string $ingestId,
        public readonly int $teamId,
        public readonly int $proxyId,
        public readonly Destination $destination,
        public readonly string $method,
        public readonly array $headers,
        public readonly string $payload,
        public readonly int $deliveryId,
        public readonly int $attemptNumber,
        public readonly string $dispatchUuid = '',
        public readonly array $signingSecrets = [],
        public readonly ?SecretUnavailableException $signingSecretsUnavailable = null,
    ) {}

    /**
     * The outbound header set: all inbound headers except the stripped sensitive
     * set, matched case-insensitively (ADR-008). No header is added.
     *
     * @return array<string, list<string|null>>
     */
    public function forwardHeaders(): array
    {
        return array_filter(
            $this->headers,
            fn (string $name): bool => ! in_array(strtolower($name), self::STRIPPED_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
