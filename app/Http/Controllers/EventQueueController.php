<?php

namespace App\Http\Controllers;

use App\Http\Resources\WebhookEventQueueResource;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The team-wide event queue view: every captured `WebhookEvent` across every
 * proxy the current team owns, newest first, so a member can see what is
 * backlogged (`status = pending`) rather than dispatched, without having to
 * check each proxy's own events page in turn.
 *
 * `WebhookEvent` is not one of `ApplyTeamScope`'s auto-scoped models (only
 * `Proxy`/`Destination`/`DeliveryAttempt` are), so this query filters on
 * `team_id` explicitly — the same pattern `PurgeExpiredPayloads` already
 * uses for the same reason.
 */
class EventQueueController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewEventQueue', Proxy::class);

        $team = $request->user()->currentTeam;

        $events = WebhookEvent::query()
            ->where('team_id', $team->id)
            ->with(['proxy' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'paused_at'])])
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (WebhookEvent $event) => new WebhookEventQueueResource($event));

        return Inertia::render('events/Index', [
            'events' => $events,
        ]);
    }
}
