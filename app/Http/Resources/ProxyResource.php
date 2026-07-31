<?php

namespace App\Http\Resources;

use App\Models\Proxy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The proxy prop shape for the index/show/edit Inertia pages.
 *
 * `$wrap = null` disables the default `data` envelope so Inertia receives the
 * attributes directly (e.g. `proxy.id`, not `proxy.data.id`) — Inertia resolves a
 * resource prop by calling toResponse()->getData(). Destinations are only included
 * when eager-loaded (show/edit), so the paginated index list stays lean.
 *
 * @mixin Proxy
 */
class ProxyResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mode' => $this->mode->value,
            // Built server-side from config, never the request Host header (ADR-006).
            'ingest_url' => $this->ingestUrl(),
            'destinations' => DestinationResource::collection($this->whenLoaded('destinations')),
        ];
    }
}
