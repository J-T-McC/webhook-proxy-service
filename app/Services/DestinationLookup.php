<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Scopes\TeamScope;
use App\Observers\DestinationObserver;
use Illuminate\Support\Facades\Cache;

/**
 * The delivery path's destination row read, cached.
 *
 * One read, on the hot path, of configuration rather than per-event state: the
 * single row a delivery is for ({@see DeliveryUnitResolver}).
 *
 * **Only rows by id are cached, never the validated set a proxy fans out to.**
 * A cached set cannot represent a destination added after it was stored, and
 * adding one is the ordinary case; `createQuietly()` — used across this
 * project's tests and seeders — suppresses the model events an observer would
 * need to notice. A row keyed by id has no such hole, because a new
 * destination has a new id and so can never be served stale.
 *
 * **Invalidation is by write.** `validation_state` is the gate deciding which
 * destinations receive traffic at all (#18 AC8), so a stale entry would let an
 * unvalidated destination be sent to. Every writer of that column goes through
 * `forceFill(...)->save()` — the approval controller, the challenge action and
 * the URL-change path in `ProxyController` — so
 * {@see DestinationObserver} catches all of them. The TTL is a
 * backstop, not the correctness mechanism.
 *
 * **Attributes are cached, never models.** A serialised Eloquent model does not
 * round-trip through every cache store; rehydrating from raw attributes does.
 *
 * Queries here drop {@see TeamScope} deliberately. This runs in queue workers,
 * which have no authenticated team, and the delivery row already fixes the
 * team — the same reasoning the pipeline already applies.
 */
class DestinationLookup
{
    /**
     * The destination behind an id, including a soft-deleted one.
     *
     * `DeliveryUnitResolver` resolves trashed destinations on purpose: one
     * soft-deleted after its delivery row was created still receives that
     * attempt (ADR-020 ruling 2). The SoftDeletes scope is therefore not
     * applied.
     */
    public function byIdWithTrashed(int $id): ?Destination
    {
        $cached = Cache::get(self::idKey($id));

        if (is_array($cached)) {
            return self::rehydrate($cached);
        }

        $destination = Destination::query()
            ->withoutGlobalScope(TeamScope::class)
            ->withTrashed()
            ->whereKey($id)
            ->first();

        if ($destination !== null) {
            Cache::put(self::idKey($id), $destination->getRawOriginal(), self::ttl());
        }

        return $destination;
    }

    /**
     * Drops the row's entry.
     */
    public static function forget(Destination $destination): void
    {
        Cache::forget(self::idKey($destination->id));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function rehydrate(array $attributes): Destination
    {
        return (new Destination)->newFromBuilder($attributes);
    }

    private static function idKey(int $id): string
    {
        return 'ingest:destination:id:'.$id;
    }

    private static function ttl(): int
    {
        return (int) config('ingest.proxy_cache_ttl_seconds');
    }
}
