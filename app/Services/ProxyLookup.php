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

        if ($cached instanceof Proxy) {
            return $cached;
        }

        $proxy = Proxy::query()
            ->where('ingest_token_hash', $tokenHash)
            ->first();

        if ($proxy !== null) {
            Cache::put(self::key($tokenHash), $proxy, self::ttl());
        }

        return $proxy;
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
