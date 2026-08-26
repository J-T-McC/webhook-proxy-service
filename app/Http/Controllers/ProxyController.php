<?php

namespace App\Http\Controllers;

use App\Data\ProxyPermissions;
use App\Enums\AnalyticsWindow;
use App\Enums\ProxyMode;
use App\Http\Requests\StoreProxyRequest;
use App\Http\Requests\UpdateProxyRequest;
use App\Http\Resources\ProxyFormResource;
use App\Http\Resources\ProxyResource;
use App\Models\Destination;
use App\Models\Proxy;
use App\Services\DeliveryStatistics;
use App\Services\IngestTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProxyController extends Controller
{
    public function __construct(
        private DeliveryStatistics $statistics,
    ) {}

    /**
     * Paginated list of the current team's proxies (AC4/AC12d).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Proxy::class);

        // Keep the native paginator envelope (data/links/last_page) the Index
        // page relies on, mapping each row through the resource. Destinations are
        // not eager-loaded here, so the resource omits them for the list.
        $proxies = Proxy::query()
            ->latest()
            ->paginate(15)
            ->through(fn (Proxy $proxy) => new ProxyResource($proxy));

        // Page-level proxy affordances for the acting user on the current team
        // (ADR-009 §4 tier 1, Amendment B4). Each row's edit/delete visibility is
        // composed client-side from these booleans + ProxyResource.is_creator — no
        // per-record policy call.
        return Inertia::render('proxies/Index', [
            'proxies' => $proxies,
            'permissions' => $this->proxyPermissions($request),
        ]);
    }

    /**
     * Show the create-proxy form (AC1).
     */
    public function create(): Response
    {
        $this->authorize('create', Proxy::class);

        return Inertia::render('proxies/Create');
    }

    /**
     * Persist a new proxy with its destinations in one transaction (AC1/AC2/AC3/AC12).
     */
    public function store(StoreProxyRequest $request, IngestTokenService $tokens): RedirectResponse
    {
        $this->authorize('create', Proxy::class);

        $data = $request->validated();

        $proxy = DB::transaction(function () use ($data, $tokens): Proxy {
            // Pass the validated payload straight to mass-assignment: only
            // name/mode/processing_mode/retry_*/response_* are fillable (see
            // Proxy #[Fillable]), so the `destinations` key is ignored and the
            // ingest token/hash stay server-minted, never from input. The two
            // retry keys are added to the array only when the submitted mode
            // is Enhanced (ADR-018 Decision 3, review-06 Minor 8(b)); on a
            // Simple submission the conditional yields [], so
            // array_merge($data, []) is just $data — and $data still carries
            // both retry keys as NULL (validation permits null under
            // prohibited_if, and the client normalises both to null on every
            // Simple submission). So a Simple-mode create still writes both
            // columns as NULL, same as an omission would produce, since
            // there is nothing yet to preserve on a create; this mirrors
            // `update()`'s omission in outcome, not in mechanism — `update()`
            // is where the omission actually matters, because it is the only
            // path that could otherwise clobber a proxy's existing preserved
            // values. `?? null` lets an omitted/absent value default to NULL
            // (AC2/AC20) when the mode IS Enhanced — Proxy::make() only
            // assigns keys present in $data, so this is set explicitly rather
            // than relying on mass-assignment omission.
            $proxy = Proxy::make(array_merge(
                $data,
                $data['mode'] === ProxyMode::Enhanced->value ? [
                    'retry_attempt_limit' => $data['retry_attempt_limit'] ?? null,
                    'retry_backoff_strategy' => $data['retry_backoff_strategy'] ?? null,
                ] : [],
            ));
            $tokens->assignTo($proxy);
            $proxy->save();

            foreach ($this->destinationRows($data) as $destination) {
                $proxy->destinations()->create([
                    'team_id' => $proxy->team_id,
                    'url' => $destination['url'],
                    'http_method' => $destination['http_method'],
                ]);
            }

            // Guard the min-1 live invariant before commit (belt-and-suspenders to
            // the FormRequest's min:1).
            if ($proxy->destinations()->count() < 1) {
                throw ValidationException::withMessages([
                    'destinations' => __('A proxy must have at least one destination.'),
                ]);
            }

            return $proxy;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proxy created.')]);

        return to_route('proxies.show', ['proxy' => $proxy->id]);
    }

    /**
     * Show a single proxy with its ingest URL and destinations (AC4/AC12d).
     *
     * The leading `{current_team}` route parameter is accepted so implicit binding
     * of `{proxy}` aligns correctly under the team-prefixed group.
     */
    public function show(Request $request, string $current_team, Proxy $proxy): Response
    {
        $this->authorize('view', $proxy);

        // AC17/plan-11 Technical ruling 8: an unrecognised or absent `window`
        // resolves to the default rather than a 422 — never propagated further.
        // Carried from a Dashboard drill-through link (design-11 § Interactions)
        // when present, so the period survives the drill-down.
        $window = AnalyticsWindow::tryFrom((string) $request->query('window')) ?? AnalyticsWindow::default();

        // Share the page-level permission booleans alongside the resource so Show.vue
        // composes the edit/delete affordances client-side from these + is_creator
        // (ADR-009 Amendment B5) — server enforcement is unchanged.
        return Inertia::render('proxies/Show', [
            'proxy' => ProxyResource::make($proxy->loadMissing('destinations')),
            'permissions' => $this->proxyPermissions($request),
            'statistics' => $this->statistics->forProxy($proxy, $window),
            'destinations' => $this->statistics->destinationBreakdown($proxy, $window),
        ]);
    }

    /**
     * Show the pre-filled edit form (live destinations only) (AC16a).
     */
    public function edit(string $current_team, Proxy $proxy): Response
    {
        $this->authorize('update', $proxy);

        // ProxyFormResource is the single Amendment-A carve-out: it emits the
        // raw retry columns regardless of mode, so the Edit form can pre-fill
        // a dormant policy. No other caller may use it (AC14(b)).
        return Inertia::render('proxies/Edit', [
            'proxy' => ProxyFormResource::make($proxy->loadMissing('destinations')),
        ]);
    }

    /**
     * Update name/mode and reconcile destinations in one transaction (AC16a/AC16b).
     *
     * Reconciliation: existing live rows are updated by id, new rows are created,
     * omitted rows are soft-deleted; ≥1 live destination must remain before commit.
     * Editing never rotates the ingest token.
     */
    public function update(UpdateProxyRequest $request, string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('update', $proxy);

        $data = $request->validated();

        DB::transaction(function () use ($data, $proxy): void {
            // Persist response/retry config alongside name/mode. `?? null` lets
            // an omitted/explicit-null field clear a previously configured
            // response value (AC3, AC20). The two retry keys are OMITTED from
            // the array entirely unless the submitted mode is Enhanced
            // (ADR-018 Decision 3, review-06 Minor 8(b)) — a Simple-mode save
            // never writes either retry column, not a value, not NULL, so a
            // proxy already holding a dormant policy keeps it verbatim
            // (PRD-07 AC14). An Enhanced-mode save writes exactly what
            // validation returned; `?? null` there still lets an explicit
            // NULL clear to the unconfigured system-default sentinel
            // (PRD-06 AC2). Preservation is achieved by not writing, not by a
            // read-before-write.
            $proxy->update([
                'name' => $data['name'],
                'mode' => $data['mode'],
                'processing_mode' => $data['processing_mode'],
                'response_status' => $data['response_status'] ?? null,
                'response_body' => $data['response_body'] ?? null,
                ...($data['mode'] === ProxyMode::Enhanced->value ? [
                    'retry_attempt_limit' => $data['retry_attempt_limit'] ?? null,
                    'retry_backoff_strategy' => $data['retry_backoff_strategy'] ?? null,
                ] : []),
            ]);

            $keptIds = [];

            foreach ($this->destinationRows($data) as $row) {
                $existing = $row['id'] !== null
                    ? $proxy->destinations()->whereKey($row['id'])->first()
                    : null;

                if ($existing !== null) {
                    $existing->update(['url' => $row['url'], 'http_method' => $row['http_method']]);
                    $keptIds[] = $existing->id;

                    continue;
                }

                $created = $proxy->destinations()->create([
                    'team_id' => $proxy->team_id,
                    'url' => $row['url'],
                    'http_method' => $row['http_method'],
                ]);
                $keptIds[] = $created->id;
            }

            // Soft-delete the live destinations that were omitted from the submission.
            $proxy->destinations()->whereNotIn('id', $keptIds)->get()
                ->each(fn (Destination $destination) => $destination->delete());

            if ($proxy->destinations()->count() < 1) {
                throw ValidationException::withMessages([
                    'destinations' => __('A proxy must have at least one destination.'),
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('proxies.show', ['proxy' => $proxy->id]);
    }

    /**
     * Soft-delete the proxy and its live destinations in one transaction (AC16d).
     *
     * delivery_attempts are always retained (never cascade-removed, no soft delete).
     */
    public function destroy(string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('delete', $proxy);

        DB::transaction(function () use ($proxy): void {
            $proxy->destinations()->get()
                ->each(fn (Destination $destination) => $destination->delete());
            $proxy->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proxy deleted.')]);

        return to_route('proxies.index');
    }

    /**
     * Build the page-level proxy permission DTO for the acting user on their
     * current team (ADR-009 Amendment B4). A user without a current team gets an
     * all-false DTO — the fail-closed default.
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

    /**
     * Normalise the validated destinations payload into typed rows.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{id: int|null, url: string, http_method: string}>
     */
    private function destinationRows(array $data): array
    {
        $rows = $data['destinations'] ?? [];
        $normalised = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = is_array($row) ? $row : [];

            $normalised[] = [
                'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null,
                'url' => isset($row['url']) && is_string($row['url']) ? $row['url'] : '',
                'http_method' => isset($row['http_method']) && is_string($row['http_method']) ? $row['http_method'] : '',
            ];
        }

        return $normalised;
    }
}
