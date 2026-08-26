<?php

namespace App\Http\Controllers;

use App\Data\Analytics\EventListFilters;
use App\Data\ProxyPermissions;
use App\Enums\AnalyticsWindow;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Http\Resources\ProxyResource;
use App\Http\Resources\WebhookEventResource;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
     * AC16), narrowed by up to three optional query parameters — `window`,
     * `destination`, `outcome` (T21; AC10, AC21; plan-11 §§ Architecture E,
     * Technical rulings 3 and 8, Validation). The leading `{current_team}`
     * route parameter is accepted so implicit binding of `{proxy}` aligns
     * correctly under the team-prefixed group.
     *
     * **Arrived directly, no filter query parameter at all** (`destination`
     * and `outcome` both unresolved): the query runs exactly as it did before
     * this task — no `received_at` narrowing, no chip — so the surface stays
     * byte-identical to the pre-#11 shipped one (this task's own AC, "renders
     * byte-identical props to today's shipped surface"). A resolved
     * `destination` or `outcome` is what activates filtering; `window`
     * (which always resolves to a concrete value, defaulting to 30 days per
     * ruling 8) rides along only once one of the other two is present — every
     * entry point in `design-11`'s Flow E table that carries `window` also
     * carries `destination` and/or `outcome`, so this reading never diverges
     * from a real drill-through and keeps `?window=` alone (never a named
     * entry point) a no-op, exactly like arriving with no query string at
     * all.
     */
    public function index(Request $request, string $current_team, Proxy $proxy): Response
    {
        $this->authorize('view', $proxy);

        ['predicate' => $predicate, 'filters' => $filters] = $this->resolveFilters($request, $proxy);

        $events = WebhookEvent::query()
            ->where('proxy_id', $proxy->id)
            ->when($predicate !== null, fn ($query) => $predicate($query))
            ->with(['deliveries' => fn ($query) => $query->with(['destination' => fn ($q) => $q->withTrashed()])])
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (WebhookEvent $event) => new WebhookEventResource($event));

        return Inertia::render('proxies/events/Index', [
            // `destinations` eager-loaded (beyond T26's original scope) so
            // `ProxyResource` carries the proxy's current live destinations —
            // T37's ReplayDialog needs them for its checklist (AC10), the same
            // "current destinations" the Replay confirmation must offer from
            // both the Index row action and the Show page's header action.
            'proxy' => ProxyResource::make($proxy->loadMissing('destinations')),
            'events' => $events,
            'filters' => $filters,
            'permissions' => $this->proxyPermissions($request),
            'fifoHeldByRetry' => $this->fifoHeldByRetry($proxy),
        ]);
    }

    /**
     * Event detail: every `deliveries` row (original + any replays) with its
     * `attempts` eager-loaded (AC12, AC16). Grouping by `dispatch_uuid`/`kind`
     * is a client-side (Vue) presentation concern — the resource returns the
     * flat `deliveries` collection, each row already carrying `kind`/
     * `dispatch_uuid`. The leading `{current_team}` route parameter is
     * accepted so implicit binding of `{proxy}` aligns correctly under the
     * team-prefixed group; `{event}` resolves via `Proxy::webhookEvents()`
     * scoped binding, so a cross-team/cross-proxy event id 404s.
     */
    public function show(Request $request, string $current_team, Proxy $proxy, WebhookEvent $event): Response
    {
        $this->authorize('view', $proxy);

        $event->load([
            'deliveries' => fn ($query) => $query->with([
                'destination' => fn ($q) => $q->withTrashed(),
                'deliveryAttempts',
            ]),
        ]);

        return Inertia::render('proxies/events/Show', [
            'proxy' => ProxyResource::make($proxy->loadMissing('destinations')),
            'event' => new WebhookEventResource($event),
            'permissions' => $this->proxyPermissions($request),
        ]);
    }

    /**
     * Turns up to three query parameters (`window`, `destination`, `outcome`)
     * into (a) a closure narrowing the `WebhookEvent` query and (b) the
     * `EventListFilters` chip descriptors — from one place, so the chips and
     * the query can never disagree about what was applied (plan-11 §
     * Services & Actions). `predicate` is `null` when neither `destination`
     * nor `outcome` resolved — the "arrived directly" case (see `index()`'s
     * doc-block).
     *
     * @return array{predicate: (callable(Builder<WebhookEvent>): void)|null, filters: EventListFilters}
     */
    private function resolveFilters(Request $request, Proxy $proxy): array
    {
        $window = AnalyticsWindow::tryFrom((string) $request->query('window')) ?? AnalyticsWindow::default();
        $destination = $this->resolveDestination($request, $proxy);
        $outcomeUnit = $this->resolveOutcomeUnit($request);

        $filters = new EventListFilters(
            window: $window,
            destination: $destination === null ? null : [
                'id' => $destination->id,
                'url' => $destination->url,
                'httpMethod' => $destination->http_method->value,
                'isDeleted' => $destination->trashed(),
            ],
            outcome: $outcomeUnit === null ? null : [
                'unit' => $outcomeUnit,
                'label' => $this->outcomeLabel($outcomeUnit),
            ],
        );

        if ($destination === null && $outcomeUnit === null) {
            return ['predicate' => null, 'filters' => $filters];
        }

        return [
            'predicate' => fn (Builder $query) => $this->applyFilters($query, $proxy, $window, $destination, $outcomeUnit),
            'filters' => $filters,
        ];
    }

    /**
     * `destination` — resolved via `Destination::withTrashed()`, scoped to
     * this proxy (`proxy_id` — the authorization check that matters here, the
     * proxy itself already gated by `ProxyPolicy::view`). `withTrashed()` is
     * what keeps a deleted destination's drill-through link working
     * (`Q-11-03(9)`). A non-numeric or unresolvable id drops the filter
     * (plan Technical ruling 8) — never a 422.
     */
    private function resolveDestination(Request $request, Proxy $proxy): ?Destination
    {
        $id = $request->query('destination');

        if (! is_numeric($id)) {
            return null;
        }

        return Destination::withTrashed()->where('proxy_id', $proxy->id)->find((int) $id);
    }

    /**
     * `outcome` — matched against the two known tokens; anything else drops
     * the filter (plan Technical ruling 8). The resolved unit also decides
     * which of the two subqueries `applyFilters()` runs.
     *
     * @return 'delivery'|'attempt'|null
     */
    private function resolveOutcomeUnit(Request $request): ?string
    {
        return match ($request->query('outcome')) {
            'delivery_failed' => 'delivery',
            'attempt_failed' => 'attempt',
            default => null,
        };
    }

    /**
     * The Outcome chip's label text (design-11 Screen 4 — "Outcome: Terminal
     * failure (deliveries)" / "Outcome: Terminal failure (attempts)"; the
     * "Outcome: " prefix is added by the Vue chip component, T24).
     *
     * @param  'delivery'|'attempt'  $unit
     */
    private function outcomeLabel(string $unit): string
    {
        return $unit === 'delivery' ? 'Terminal failure (deliveries)' : 'Terminal failure (attempts)';
    }

    /**
     * Narrows the `WebhookEvent` query per plan-11 § Architecture E / the
     * plan's Technical ruling 3. **No outcome active:** `window` applies to
     * `webhook_events.received_at`; `destination` (if present) narrows via
     * the existing proxy↔destination relationship (`deliveries.destination_id`).
     * **Outcome active:** the window moves inside a subquery over the
     * failing records at the outcome's own unit — delivery-grain against
     * `deliveries.updated_at` (`deliveries (proxy_id, status, updated_at)`),
     * attempt-grain against `delivery_attempts.updated_at`
     * (`delivery_attempts (proxy_id, status, updated_at)`) — reading the same
     * predicate the source figure used, so AC10's reconciliation holds at the
     * record-set level. The attempt-grain subquery matches on `ingest_id`,
     * not `delivery_id`, so a pre-#6 attempt row (`delivery_id = NULL`) is
     * included by construction — the same population AC13 puts in the
     * attempt-level figure, and `ProxyEventReplayController` dispatches a
     * replay under the event's existing `ingest_id`, so a replayed attempt
     * matches too.
     *
     * @param  Builder<WebhookEvent>  $query
     * @param  'delivery'|'attempt'|null  $outcomeUnit
     */
    private function applyFilters(
        Builder $query,
        Proxy $proxy,
        AnalyticsWindow $window,
        ?Destination $destination,
        ?string $outcomeUnit,
    ): void {
        $start = CarbonImmutable::now()->sub($window->interval());
        $end = CarbonImmutable::now();

        if ($outcomeUnit === null) {
            $query->whereBetween('received_at', [$start, $end]);

            if ($destination !== null) {
                $query->whereHas(
                    'deliveries',
                    fn ($deliveryQuery) => $deliveryQuery->where('destination_id', $destination->id),
                );
            }

            return;
        }

        if ($outcomeUnit === 'delivery') {
            $query->whereIn('id', function ($subQuery) use ($proxy, $destination, $start, $end) {
                $subQuery->select('webhook_event_id')
                    ->from('deliveries')
                    ->where('proxy_id', $proxy->id)
                    ->where('status', 'failed')
                    ->whereBetween('updated_at', [$start, $end]);

                if ($destination !== null) {
                    $subQuery->where('destination_id', $destination->id);
                }
            });

            return;
        }

        $query->whereIn('ingest_id', function ($subQuery) use ($proxy, $destination, $start, $end) {
            $subQuery->select('ingest_id')
                ->from('delivery_attempts')
                ->where('proxy_id', $proxy->id)
                ->where('status', 'failed')
                ->whereBetween('updated_at', [$start, $end]);

            if ($destination !== null) {
                $subQuery->where('destination_id', $destination->id);
            }
        });
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
