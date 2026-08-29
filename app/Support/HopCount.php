<?php

namespace App\Support;

/**
 * The delivery-loop guard's indirect-cycle counter
 * (`docs/briefs/delivery-loop-guard.md`) — the `WebhookProxy-Hops` header
 * name and inbound-value parsing shared by `OutboundHeaders::build()`
 * (stamps outbound as inbound + 1) and `IngestController` (rejects at the
 * configured limit).
 *
 * Read case-insensitively against whichever header-array shape the caller
 * holds — `IngestController`'s `$request->headers->all()` (Symfony
 * lowercases header names when storing) and a hand-built `DeliveryUnit`
 * header array in tests are not guaranteed to share one casing convention,
 * so this never assumes lowercase keys.
 */
final class HopCount
{
    public const HEADER = 'WebhookProxy-Hops';

    /**
     * The inbound hop count: absent or non-numeric is 0, never an error
     * (delivery-loop-guard brief, Decisions).
     *
     * @param  array<string, list<string|null>|string>  $headers
     */
    public static function inboundFrom(array $headers): int
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== strtolower(self::HEADER)) {
                continue;
            }

            $raw = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
        }

        return 0;
    }
}
