<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
