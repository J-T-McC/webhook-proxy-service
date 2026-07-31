<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProxyRequest;
use App\Http\Requests\UpdateProxyRequest;
use App\Http\Resources\ProxyResource;
use App\Models\Destination;
use App\Models\Proxy;
use App\Services\IngestTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProxyController extends Controller
{
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

        return Inertia::render('proxies/Index', [
            'proxies' => $proxies,
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
        $data = $request->validated();

        $proxy = DB::transaction(function () use ($data, $tokens): Proxy {
            // Pass the validated payload straight to mass-assignment: only
            // name/mode are fillable (see Proxy #[Fillable]), so the `destinations`
            // key is ignored and the ingest token/hash stay server-minted, never
            // from input. (new Proxy() over Proxy::make() per larastan.noModelMake.)
            $proxy = new Proxy($data);
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
    public function show(string $current_team, Proxy $proxy): Response
    {
        $this->authorize('view', $proxy);

        return Inertia::render('proxies/Show', [
            'proxy' => ProxyResource::make($proxy->loadMissing('destinations')),
        ]);
    }

    /**
     * Show the pre-filled edit form (live destinations only) (AC16a).
     */
    public function edit(string $current_team, Proxy $proxy): Response
    {
        $this->authorize('update', $proxy);

        return Inertia::render('proxies/Edit', [
            'proxy' => ProxyResource::make($proxy->loadMissing('destinations')),
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
        $data = $request->validated();

        DB::transaction(function () use ($data, $proxy): void {
            $proxy->update(['name' => $data['name'], 'mode' => $data['mode']]);

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
