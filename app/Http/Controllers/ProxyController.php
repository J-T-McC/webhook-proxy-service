<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProxyRequest;
use App\Models\Destination;
use App\Models\Proxy;
use App\Services\IngestTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        Gate::authorize('viewAny', Proxy::class);

        $proxies = Proxy::query()
            ->latest()
            ->paginate(15)
            ->through(fn (Proxy $proxy) => [
                'id' => $proxy->id,
                'name' => $proxy->name,
                'mode' => $proxy->mode->value,
                'ingest_url' => $proxy->ingestUrl(),
            ]);

        return Inertia::render('proxies/Index', [
            'proxies' => $proxies,
        ]);
    }

    /**
     * Show the create-proxy form (AC1).
     */
    public function create(): Response
    {
        Gate::authorize('create', Proxy::class);

        return Inertia::render('proxies/Create');
    }

    /**
     * Persist a new proxy with its destinations in one transaction (AC1/AC2/AC3/AC12).
     */
    public function store(StoreProxyRequest $request, IngestTokenService $tokens): RedirectResponse
    {
        $data = $request->validated();

        $proxy = DB::transaction(function () use ($data, $tokens): Proxy {
            $proxy = new Proxy(['name' => $data['name'], 'mode' => $data['mode']]);
            $tokens->assignTo($proxy);
            $proxy->save();

            foreach ($data['destinations'] as $destination) {
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
        Gate::authorize('view', $proxy);

        return Inertia::render('proxies/Show', [
            'proxy' => $this->proxyPayload($proxy),
        ]);
    }

    /**
     * The detail-page proxy payload (ingest URL built server-side from config).
     *
     * @return array<string, mixed>
     */
    private function proxyPayload(Proxy $proxy): array
    {
        return [
            'id' => $proxy->id,
            'name' => $proxy->name,
            'mode' => $proxy->mode->value,
            'ingest_url' => $proxy->ingestUrl(),
            'destinations' => $proxy->destinations->map(fn (Destination $destination) => [
                'id' => $destination->id,
                'url' => $destination->url,
                'http_method' => $destination->http_method->value,
            ])->values(),
        ];
    }
}
