<?php

namespace App\Observers;

use App\Models\Destination;
use App\Services\DestinationLookup;

/**
 * Keeps {@see DestinationLookup}'s cache honest.
 *
 * The invalidation half of the destination cache, and the reason it is safe to
 * have one. `validation_state` decides which destinations receive traffic at
 * all (#18 AC8), and every writer of it — the approval controller, the
 * challenge action, and the URL-change path in `ProxyController` — goes through
 * `forceFill(...)->save()`, so `saved` covers each of them with no special case.
 *
 * **A write that bypasses Eloquent fires none of these.** Anything that starts
 * updating `destinations` through the query builder must forget the entry
 * itself.
 */
class DestinationObserver
{
    public function saved(Destination $destination): void
    {
        DestinationLookup::forget($destination);
    }

    public function deleted(Destination $destination): void
    {
        DestinationLookup::forget($destination);
    }

    public function restored(Destination $destination): void
    {
        DestinationLookup::forget($destination);
    }

    public function forceDeleted(Destination $destination): void
    {
        DestinationLookup::forget($destination);
    }
}
