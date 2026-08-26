<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use App\Services\RetryPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `deliveries` row (original send or replay) on the events read surface
 * (T25; AC12, AC15-AC17; ADR-017 Decisions 2, 5). `attempt_limit` is the
 * proxy's *effective* policy — resolved via `RetryPolicy::attemptLimitFor()`,
 * the single resolver (ADR-015 Decision 3) — never the raw column, so a
 * NULL-column proxy still renders the system default. `proxy` is likewise
 * resolved trashed-inclusive (a since-deleted proxy's historical deliveries
 * must still render their effective limit, not throw) — same precedent as
 * `destination`, which reads through `withTrashed()` (eager-loaded by the
 * caller) so a since-deleted destination still renders its historical
 * url/method rather than 404ing the whole resource. Never `body`/`headers`
 * (AC22/AC25) — this row carries none. `created_at` (review-06 Minor 5,
 * rider 2) is the row's own creation timestamp, serialized verbatim — the
 * events-detail replay-group label/ordering (`events/Show.vue`) derives from
 * it directly, replacing the earlier started_at/id-based derivation.
 *
 * @mixin Delivery
 */
class DeliveryResource extends JsonResource
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
            'dispatch_uuid' => $this->dispatch_uuid,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'created_at' => $this->created_at,
            'next_attempt_at' => $this->next_attempt_at,
            'attempt_limit' => app(RetryPolicy::class)->attemptLimitFor($this->proxy()->withTrashed()->firstOrFail()),
            'destination' => [
                'http_method' => $this->destination->http_method->value,
                'url' => $this->destination->url,
            ],
            'attempts' => DeliveryAttemptResource::collection($this->whenLoaded('deliveryAttempts')),
        ];
    }
}
