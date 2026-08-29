<?php

namespace App\Support;

use App\Pipeline\DeliveryUnit;

/**
 * **The only place an outbound header set is built** (plan-10 Implementation
 * Note 3; AC17, AC30, AC38, AC54, AC55, AC58-AC60, AC64). Composes, in order
 * (ADR-023 Decision 1): (1) the inbound set minus ADR-008's constant strip
 * (`DeliveryUnit::forwardHeaders()`), (2) the destination's credential
 * header sent verbatim with no scheme prefix added (AC30) plus the proxy's
 * Standard Webhooks signing headers (T34; one `v1,<sig>` entry per live
 * signing secret, AC58) when the proxy has a live `signing` secret set —
 * a credential header whose lowercased name collides with one of the
 * three signing headers is itself displaced by the signing header
 * (review-10 Finding 5; nothing in `credential_header_name`'s validation
 * forbids a `WebhookProxy-*` name, so this collision is only ever an
 * accident, never an integration's deliberate choice, and the branded
 * signing contract wins rather than being duplicated or silently
 * dropped) — (3) displacing any forwarded header whose lowercased name
 * collides with an added one (AC38, AC64, R9), (4) merge.
 *
 * `DeliveryUnit::STRIPPED_HEADERS` is deliberately untouched by this class
 * (plan-10 Implementation Note 4) — it is the fixed ADR-008/ADR-026 list,
 * unrelated to the credential/signing names this class adds; adding the
 * three `WebhookProxy-*` names to it would strip them from every
 * destination, including an unsigned proxy's, breaking AC63 (ADR-023
 * Decision 5).
 *
 * A destination with no credential, on a proxy with no signing secret
 * configured, produces a result byte-identical to
 * `DeliveryUnit::forwardHeaders()` alone (AC37, AC63) — nothing here changes
 * behaviour unless a credential or a signing secret is actually configured.
 */
final class OutboundHeaders
{
    /**
     * @param  list<string>  $signingSecrets  the proxy's live `signing`-purpose secret set (T36's
     *                                        `SecretStore::liveFor()`, current first, at most two —
     *                                        AC29's cap) — empty when signing is not enabled, in
     *                                        which case no signing header is added at all (AC63).
     * @return array<string, list<string|null>|string>
     */
    public static function build(
        DeliveryUnit $unit,
        ?string $credentialHeaderName,
        ?string $credentialValue,
        array $signingSecrets = [],
    ): array {
        $added = [];

        if ($credentialHeaderName !== null && $credentialValue !== null) {
            $added[$credentialHeaderName] = $credentialValue;
        }

        if ($signingSecrets !== []) {
            $signing = self::signingHeaders($unit, $signingSecrets);

            // A credential header named after one of this service's own
            // signing headers is displaced by the signing header,
            // case-insensitively — the same rule (3) below applies to the
            // forwarded set, applied here within $added itself (review-10
            // Finding 5). Without this, a same-cased collision would let the
            // spread below silently drop the credential, and a
            // differently-cased one would survive as a second PHP array key
            // and emit two headers of the same name over the wire.
            $added = [...self::withoutNames($added, array_keys($signing)), ...$signing];
        }

        $headers = self::withoutNames($unit->forwardHeaders(), array_keys($added));

        return [...$headers, ...$added];
    }

    /**
     * The Standard Webhooks-format signing headers, emitted under this
     * service's own branded names — `WebhookProxy-Id`, `WebhookProxy-Timestamp`,
     * `WebhookProxy-Signature` (AC54, AC55, AC58, AC59, AC60; ADR-025 Decision
     * 2). `WebhookProxy-Id` is derived, never stored (ADR-023 Decision 3):
     * `msg_{dispatch_uuid}_{destination_id}` — the delivery's own natural
     * key, stable across every retry of that delivery (same `dispatch_uuid`,
     * same destination), new on a replay (a fresh `dispatch_uuid`), and
     * different per destination of one dispatch even though the signing key
     * is shared (AC60). `WebhookProxy-Timestamp` is taken at this exact call —
     * this attempt's own time, never the original dispatch's. `WebhookProxy-Signature`
     * carries one `v1,<base64>` entry per live secret (AC58), each computed
     * by `StandardWebhooks::sign()` (T7) over the exact bytes about to be
     * dispatched (AC59) — `$unit->payload`, unchanged by this class.
     *
     * @param  list<string>  $signingSecrets
     * @return array<string, string>
     */
    private static function signingHeaders(DeliveryUnit $unit, array $signingSecrets): array
    {
        $id = "msg_{$unit->dispatchUuid}_{$unit->destination->id}";
        $timestamp = now()->getTimestamp();

        $entries = array_map(
            fn (string $secret): string => 'v1,'.StandardWebhooks::sign($id, $timestamp, $unit->payload, $secret),
            $signingSecrets,
        );

        return [
            'WebhookProxy-Id' => $id,
            'WebhookProxy-Timestamp' => (string) $timestamp,
            'WebhookProxy-Signature' => implode(' ', $entries),
        ];
    }

    /**
     * Removes every header whose name matches one of `$names`,
     * case-insensitively (AC38, R9 — `Http::withHeaders()` takes a PHP array
     * and would otherwise happily emit `authorization` and `Authorization`
     * as two separate headers).
     *
     * @param  array<string, list<string|null>|string>  $headers
     * @param  list<string>  $names
     * @return array<string, list<string|null>|string>
     */
    private static function withoutNames(array $headers, array $names): array
    {
        $lowered = array_map(strtolower(...), $names);

        return array_filter(
            $headers,
            fn (string $name): bool => ! in_array(strtolower($name), $lowered, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
