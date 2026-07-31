<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DestinationController extends Controller
{
    /**
     * Soft-remove a single destination, guarding the min-1 live invariant
     * (AC16c/AC16b). The destination is scoped to the proxy via the route's
     * scoped binding, and the proxy via the team scope (cross-team → 404).
     */
    public function destroy(string $current_team, Proxy $proxy, Destination $destination): RedirectResponse
    {
        Gate::authorize('update', $proxy);

        DB::transaction(function () use ($proxy, $destination): void {
            // Re-count only live rows under a row lock to guard the concurrent
            // last-two-remove race.
            $liveCount = $proxy->destinations()->lockForUpdate()->count();

            if ($liveCount <= 1) {
                throw ValidationException::withMessages([
                    'destination' => __('A proxy must keep at least one destination.'),
                ]);
            }

            $destination->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Destination removed.')]);

        return to_route('proxies.show', ['proxy' => $proxy->id]);
    }
}
