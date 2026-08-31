<?php

namespace App\Http\Controllers;

use App\Actions\SendDestinationValidationChallenge;
use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The member-facing Validate action (#18 AC14, AC21, AC44) — dispatches a
 * validation challenge to one destination. Distinct from
 * {@see DestinationValidationController}, the public approval surface: this
 * route is authenticated, team-scoped, and gated by the existing
 * update-destination permission (AC44 — the same `update` proxy ability
 * `DestinationController::destroy` uses, no new permission).
 *
 * Send outcomes surface as a toast; the row's own state (including the
 * rate-limited line that replaces the button) re-renders from the refreshed
 * `security` prop, which is where the blocked-until fact lives.
 */
class DestinationValidationSendController extends Controller
{
    public function store(
        string $current_team,
        Proxy $proxy,
        Destination $destination,
        SendDestinationValidationChallenge $action,
    ): RedirectResponse {
        $this->authorize('update', $proxy);

        if ($action->blockedBy($destination) !== null) {
            // No toast: the refreshed props carry the blocked-until line the
            // row renders in the button's place, which already says which
            // limit and when it clears (AC21).
            return to_route('proxies.show', ['proxy' => $proxy->id]);
        }

        $sent = $action->handle($destination);

        Inertia::flash('toast', $sent
            ? ['type' => 'success', 'message' => __('Validation challenge sent.')]
            : ['type' => 'error', 'message' => __('The validation challenge could not be sent. Check the destination URL and try again.')]);

        return to_route('proxies.show', ['proxy' => $proxy->id]);
    }
}
