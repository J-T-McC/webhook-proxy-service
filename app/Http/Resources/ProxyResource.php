<?php

namespace App\Http\Resources;

use App\Models\Proxy;
use App\Services\RetryPolicy;
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
            // Per-proxy retry policy override in force (AC2, AC20, AC14(b);
            // ADR-018 Decision 4). Resolved through RetryPolicy's mode gate, NOT
            // the raw columns: an Enhanced proxy emits its column values, a
            // Simple proxy emits null for both, always — even if it holds a
            // dormant policy from a prior Enhanced save. This is a read-surface
            // rule only; the Edit form's pre-fill needs the raw persisted values
            // regardless of mode (Amendment A) and gets them from
            // `ProxyFormResource`, the sole sanctioned override of these two keys.
            'retry_attempt_limit' => app(RetryPolicy::class)->configuredAttemptLimitFor($this->resource),
            'retry_backoff_strategy' => app(RetryPolicy::class)->configuredStrategyFor($this->resource)?->value,
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
