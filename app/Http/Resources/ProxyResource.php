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
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'mode' => $this->mode->value,
            // Per-proxy processing mode (ADR-011). Read by the shared Create/Edit form
            // select, the Show badge, and the Index column.
            'processing_mode' => $this->processing_mode->value,
            // User-defined upstream response config (nullable = unconfigured → the
            // resolver returns 202). Exposed so the shared Create/Edit form pre-fills
            // them; the index doesn't render them but the shape stays consistent.
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            // Per-proxy retry policy (AC2, AC20; ADR-015 Decision 3). Raw column
            // values, nullable = unconfigured -> RetryPolicy resolves the system
            // default. Exposed so the shared Create/Edit form pre-fills them and
            // the Show-page Retry policy card can derive its display state.
            'retry_attempt_limit' => $this->retry_attempt_limit,
            'retry_backoff_strategy' => $this->retry_backoff_strategy?->value,
            // Built server-side from config, never the request Host header (ADR-006).
            'ingest_url' => $this->ingestUrl(),
            'destinations' => DestinationResource::collection($this->whenLoaded('destinations')),
            // Ownership-for-display only: a plain id comparison, never a policy call
            // (ADR-009 Amendment B3). `created_by` is already on the row, so this adds
            // no query and no per-record policy evaluation — the M2 N+1 fix. The client
            // composes update/delete affordances from this + the page-level
            // ProxyPermissions booleans; the server ProxyPolicy remains authoritative (B2).
            // A null `created_by` (pre-feature / no-actor proxy) yields false — fail-closed.
            'is_creator' => $user !== null && (int) $this->created_by === (int) $user->id,
        ];
    }
}
