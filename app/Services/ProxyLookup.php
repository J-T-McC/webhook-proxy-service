<?php

namespace App\Services;

use App\Models\Proxy;
use App\Observers\ProxyObserver;
use Illuminate\Support\Facades\Cache;

/**
 * The ingest endpoint's proxy lookup, cached.
 *
 * Every ingest request resolves a proxy from its token hash before it can do
 * anything else, so this is the one read on the hot path that runs whether or
 * not the request turns out to be valid.
 *
 * **Invalidation is by write, not by expiry.** A proxy row carries live control
 * state — `paused_at` (#15), `deleted_at` (AC12c), the retry policy and the
 * response configuration — so a cache that merely aged out would leave a paused
 * proxy ingesting for the length of the TTL. {@see ProxyObserver}
 * forgets the entry on every save, delete and restore, which covers pause and
 * resume because both go through `save()`. The TTL below is a backstop against
 * an entry that somehow outlives its row, not the correctness mechanism.
 *
 * **Misses are never cached.** The token is the authenticator, so unknown
 * tokens are exactly what an attacker enumerating the endpoint produces.
 * Caching those would let anyone fill the cache with garbage keys.
 *
 * **The attribute array is cached, not the model.** A serialised Eloquent model
 * came back from this Redis client as `__PHP_Incomplete_Class` and every read
 * missed, so the cache was inert. Storing raw attributes and rehydrating avoids
 * depending on how any store serialises objects, and keeps relations and loaded
 * state out of the cached value.
 */
class ProxyLookup
{
    /**
     * The proxy this token hash belongs to, or null when there is no live one.
     *
     * Soft-deleted proxies stay excluded: the model's SoftDeletes scope applies
     * to the query, and a delete busts the entry, so a deleted proxy can never
     * be served from cache.
     */
    public function byTokenHash(string $tokenHash): ?Proxy
    {
        $cached = Cache::get(self::key($tokenHash));

        if (is_array($cached)) {
            return self::rehydrate($cached);
        }

        $proxy = Proxy::query()
            ->where('ingest_token_hash', $tokenHash)
            ->first();

        if ($proxy !== null) {
            Cache::put(self::key($tokenHash), $proxy->getRawOriginal(), self::ttl());
        }

        return $proxy;
    }

    /**
     * Rebuilds the model from stored attributes as though it came from the
     * database, so casts and the encrypted `ingest_token` behave identically
     * and the instance is not treated as newly created.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function rehydrate(array $attributes): Proxy
    {
        return (new Proxy)->newFromBuilder($attributes);
    }

    /**
     * Drop a token hash's entry.
     */
    public static function forget(string $tokenHash): void
    {
        Cache::forget(self::key($tokenHash));
    }

    /**
     * The hash is raw BINARY(32), so it is hex-encoded rather than used as a
     * cache key directly — a raw binary key is not safe across cache stores.
     * The plaintext token never appears in a key (ADR-006).
     */
    private static function key(string $tokenHash): string
    {
        return 'ingest:proxy:'.bin2hex($tokenHash);
    }

    private static function ttl(): int
    {
        return (int) config('ingest.proxy_cache_ttl_seconds');
    }
}
