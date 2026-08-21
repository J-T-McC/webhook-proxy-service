<?php

namespace App\Http\Controllers;

use App\Data\ProxyPermissions;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Http\Resources\ProxyResource;
use App\Http\Resources\WebhookEventResource;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The received-events read surface for a proxy (T26/T27; AC12, AC15-AC17,
 * AC22; ADR-017 Decision 5). Every action here is read-only and gated
 * `ProxyPolicy::view` — no distinct read permission for events vs. the proxy
 * itself, and never any payload content (that's `ProxyEventPayloadController`,
 * T28's fetch-on-reveal endpoint).
 */
class ProxyEventController extends Controller
{
    /**
     * Paginated (15, newest-first) list of the proxy's captured events (AC15,
     * AC16). The leading `{current_team}` route parameter is accepted so
     * implicit binding of `{proxy}` aligns correctly under the team-prefixed
     * group.
     */
    public function index(Request $request, string $current_team, Proxy $proxy): Response
    {
        $this->authorize('view', $proxy);

        $events = WebhookEvent::query()
            ->where('proxy_id', $proxy->id)
            ->with(['deliveries' => fn ($query) => $query->with(['destination' => fn ($q) => $q->withTrashed()])])
            ->latest('id')
            ->paginate(15)
            ->through(fn (WebhookEvent $event) => new WebhookEventResource($event));

        return Inertia::render('proxies/events/Index', [
            'proxy' => ProxyResource::make($proxy),
            'events' => $events,
            'permissions' => $this->proxyPermissions($request),
            'fifoHeldByRetry' => $this->fifoHeldByRetry($proxy),
        ]);
    }

    /**
     * `true` iff the proxy is FIFO **and** has a live `awaiting_retry` row —
     * `false` for every Async proxy, always (AC15/AC16).
     */
    private function fifoHeldByRetry(Proxy $proxy): bool
    {
        if ($proxy->processing_mode !== ProcessingMode::Fifo) {
            return false;
        }

        return FifoDispatch::query()
            ->where('proxy_id', $proxy->id)
            ->where('status', FifoDispatchStatus::AwaitingRetry)
            ->exists();
    }

    /**
     * Build the page-level proxy permission DTO for the acting user on their
     * current team (ADR-009 Amendment B4), mirroring
     * `ProxyController::proxyPermissions()`. A user without a current team
     * gets an all-false DTO — the fail-closed default.
     */
    private function proxyPermissions(Request $request): ProxyPermissions
    {
        $user = $request->user();
        $team = $user?->currentTeam;

        if ($user === null || $team === null) {
            return new ProxyPermissions(
                canCreateProxy: false,
                canViewProxy: false,
                canUpdateProxy: false,
                canDeleteProxy: false,
                canUpdateAnyProxy: false,
                canDeleteAnyProxy: false,
                canReplayProxy: false,
            );
        }

        return $user->toProxyPermissions($team);
    }
}
