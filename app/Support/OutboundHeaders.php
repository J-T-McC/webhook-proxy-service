<?php

namespace App\Support;

use App\Pipeline\DeliveryUnit;

/**
 * **The only place an outbound header set is built** (plan-10 Implementation
 * Note 3; AC17, AC27, AC30, AC38). Composes, in order: (1) the inbound set
 * minus ADR-008's constant strip (`DeliveryUnit::forwardHeaders()`), (2)
 * minus this proxy's own verification header name(s) (AC27), (3) the
 * destination's credential header, first displacing any forwarded header
 * whose lowercased name collides with it (AC38, R9), sent verbatim with no
 * scheme prefix added (AC30). T34 adds the fifth step, the proxy's signing
 * headers, to this same class later — no other class may build either half.
 *
 * `DeliveryUnit::STRIPPED_HEADERS` is deliberately untouched by this class
 * (plan-10 Implementation Note 4) — it is the fixed ADR-008 list, unrelated
 * to the verification/credential names this class strips.
 *
 * A destination with no credential, on a proxy with no verification
 * configured, produces a result byte-identical to `DeliveryUnit::forwardHeaders()`
 * alone (AC37) — nothing here changes behaviour unless a credential or a
 * verification scheme is actually configured.
 */
final class OutboundHeaders
{
    /**
     * @param  list<string>  $verificationHeaderNames  this proxy's own verification header name(s) to strip — empty when verification is not required (AC43: nothing strips a `webhook-signature` a sender happened to send when there is no verification configured to strip it for)
     * @return array<string, list<string|null>|string>
     */
    public static function build(
        DeliveryUnit $unit,
        array $verificationHeaderNames,
        ?string $credentialHeaderName,
        ?string $credentialValue,
    ): array {
        $headers = self::withoutNames($unit->forwardHeaders(), $verificationHeaderNames);

        if ($credentialHeaderName !== null && $credentialValue !== null) {
            $headers = self::withoutNames($headers, [$credentialHeaderName]);
            $headers[$credentialHeaderName] = $credentialValue;
        }

        return $headers;
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
