<?php

namespace App\Support;

/**
 * Encodes a model's raw attributes for storage in any cache driver.
 *
 * **Why this exists rather than caching the array directly.** A row's raw
 * attributes can hold bytes that are not valid UTF-8 — `proxies.ingest_token_hash`
 * is `BINARY(32)` — and the `database` cache driver stores its value in a utf8mb4
 * text column, which rejects them:
 *
 *     SQLSTATE[HY000]: General error: 1366 Incorrect string value
 *
 * Redis is binary-safe and shows no such problem, so caching that works in
 * development on Redis fails on the driver `config/cache.php` actually defaults
 * to. Base64 makes the payload plain ASCII and driver-independent.
 *
 * Cheap enough to ignore: the encode happens once per cache write, and the rows
 * involved are a kilobyte or so.
 */
final class CachedRow
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function encode(array $attributes): string
    {
        return base64_encode(serialize($attributes));
    }

    /**
     * Null for anything that is not a payload this class wrote — a cache entry
     * left by an older build, or a corrupted one. The caller falls through to
     * the database, which is always correct if slower.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(mixed $cached): ?array
    {
        if (! is_string($cached)) {
            return null;
        }

        $raw = base64_decode($cached, strict: true);

        if ($raw === false) {
            return null;
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : null;
    }
}
