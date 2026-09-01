<?php

namespace App\Observers;

use App\Models\Proxy;
use App\Services\ProxyLookup;

/**
 * Keeps {@see ProxyLookup}'s cache honest.
 *
 * This is the invalidation half of the ingest lookup cache, and it is the part
 * that makes the cache safe to have at all: a proxy row carries live control
 * state, so an entry that outlived a write would keep a paused or deleted proxy
 * ingesting. Pause and resume both go through `save()`
 * (`ProxyPauseController`), so `saved` covers them with no special case.
 *
 * **A write that bypasses Eloquent fires none of these.** Nothing writes to
 * `proxies` through the query builder today; anything that starts to must
 * forget the entry itself.
 */
class ProxyObserver
{
    /**
     * Forgets both hashes on save, because a token rotation changes the key:
     * dropping only the new one would leave the old token working from cache
     * until its backstop expiry.
     */
    public function saved(Proxy $proxy): void
    {
        $original = $proxy->getOriginal('ingest_token_hash');

        if (is_string($original) && $original !== '') {
            ProxyLookup::forget($original);
        }

        self::bust($proxy);
    }

    public function deleted(Proxy $proxy): void
    {
        self::bust($proxy);
    }

    public function restored(Proxy $proxy): void
    {
        self::bust($proxy);
    }

    public function forceDeleted(Proxy $proxy): void
    {
        self::bust($proxy);
    }

    /**
     * Both keys the proxy is cached under: by token hash for ingest, and by id
     * for the delivery path.
     */
    private static function bust(Proxy $proxy): void
    {
        ProxyLookup::forget($proxy->ingest_token_hash);
        ProxyLookup::forgetId($proxy->id);
    }
}
