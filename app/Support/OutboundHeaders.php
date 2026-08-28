<?php

namespace App\Support;

use App\Pipeline\DeliveryUnit;

/**
 * **The only place an outbound header set is built** (plan-10 Implementation
 * Note 3; AC17, AC27, AC30, AC38, AC54, AC55, AC58-AC60, AC64). Composes, in
 * order (ADR-023 Decision 1): (1) the inbound set minus ADR-008's constant
 * strip (`DeliveryUnit::forwardHeaders()`), (2) minus this proxy's own
 * verification header name(s) (AC27), (3) the destination's credential
 * header sent verbatim with no scheme prefix added (AC30) plus the proxy's
 * Standard Webhooks signing headers (T34; one `v1,<sig>` entry per live
 * signing secret, AC58) when the proxy has a live `signing` secret set, (4)
 * displacing any forwarded header whose lowercased name collides with an
 * added one (AC38, AC64, R9), (5) merge.
 *
 * `DeliveryUnit::STRIPPED_HEADERS` is deliberately untouched by this class
 * (plan-10 Implementation Note 4) — it is the fixed ADR-008 list, unrelated
 * to the verification/credential/signing names this class adds or strips;
 * adding the three `webhook-*` names to it would strip them from every
 * destination, including an unsigned proxy's, breaking AC63 (ADR-023
 * Decision 5).
 *
 * A destination with no credential, on a proxy with no verification and no
 * signing secret configured, produces a result byte-identical to
 * `DeliveryUnit::forwardHeaders()` alone (AC37, AC63) — nothing here changes
 * behaviour unless a credential, a verification scheme, or a signing secret
 * is actually configured.
 */
final class OutboundHeaders
{
    /**
     * @param  list<string>  $verificationHeaderNames  this proxy's own verification header name(s) to strip — empty when verification is not required (AC43: nothing strips a `webhook-signature` a sender happened to send when there is no verification configured to strip it for)
     * @param  list<string>  $signingSecrets  the proxy's live `signing`-purpose secret set (T36's
     *                                        `SecretStore::liveFor()`, current first, at most two —
     *                                        AC29's cap) — empty when signing is not enabled, in
     *                                        which case no signing header is added at all (AC63).
     * @return array<string, list<string|null>|string>
     */
    public static function build(
        DeliveryUnit $unit,
        array $verificationHeaderNames,
        ?string $credentialHeaderName,
        ?string $credentialValue,
        array $signingSecrets = [],
    ): array {
        $added = [];

        if ($credentialHeaderName !== null && $credentialValue !== null) {
            $added[$credentialHeaderName] = $credentialValue;
        }

        if ($signingSecrets !== []) {
            $added = [...$added, ...self::signingHeaders($unit, $signingSecrets)];
        }

        $headers = self::withoutNames($unit->forwardHeaders(), $verificationHeaderNames);
        $headers = self::withoutNames($headers, array_keys($added));

        return [...$headers, ...$added];
    }

    /**
     * The Standard Webhooks signing headers (AC54, AC55, AC58, AC59, AC60).
     * `webhook-id` is derived, never stored (ADR-023 Decision 3):
     * `msg_{dispatch_uuid}_{destination_id}` — the delivery's own natural
     * key, stable across every retry of that delivery (same `dispatch_uuid`,
     * same destination), new on a replay (a fresh `dispatch_uuid`), and
     * different per destination of one dispatch even though the signing key
     * is shared (AC60). `webhook-timestamp` is taken at this exact call —
     * this attempt's own time, never the original dispatch's. `webhook-signature`
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
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => implode(' ', $entries),
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
