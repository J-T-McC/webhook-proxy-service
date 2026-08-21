<?php

namespace App\Http\Resources;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\WebhookEvent;
use App\Services\StoredPayloadLookup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The events read-surface descriptor (T25; AC12, AC15-AC17, AC22, AC25;
 * ADR-017 Decision 5). `payload_state` is resolved exclusively through
 * `StoredPayloadLookup` — the single resolver of the cleaned signal (ADR-014
 * Decision 7) — NEVER inferred from `body === null`. This resource, and
 * everything it composes (`DeliveryResource`, `DeliveryAttemptResource`),
 * never emits `body`/`headers` under any state (AC22/AC25's fetch-on-reveal
 * posture — content is served only by `ProxyEventPayloadController`, T28).
 *
 * **Legacy fallback (Q-06-03 ruling 3):** an event captured before #6 has zero
 * `deliveries` rows. When the `deliveries` relation is loaded and empty, this
 * resource derives a presentation-only per-destination state from the
 * event's latest `delivery_attempts` row per destination
 * (succeeded -> Delivered, failed -> Failed, dispatched -> Retrying) — never
 * a fabricated `Delivery` row, never a database write. The derived rows are
 * shaped to match `DeliveryResource`'s keys so the client renders both
 * uniformly, with the fields #6 cannot know about (`id`, `dispatch_uuid`,
 * `next_attempt_at`, `attempt_limit`, `attempts`) left `null`.
 *
 * @mixin WebhookEvent
 */
class WebhookEventResource extends JsonResource
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
            'received_at' => $this->received_at,
            'byte_size' => $this->byte_size,
            'content_type' => $this->content_type,
            'method' => $this->method,
            'payload_state' => app(StoredPayloadLookup::class)->for($this->ingest_id)->value,
            'deliveries' => $this->whenLoaded('deliveries', function () {
                /** @var Collection<int, Delivery> $deliveries */
                $deliveries = $this->deliveries;

                return $deliveries->isNotEmpty()
                    ? DeliveryResource::collection($deliveries)
                    : $this->legacyDeliveries();
            }),
        ];
    }

    /**
     * The legacy-fallback derivation (ruling 3): the latest `delivery_attempts`
     * row per destination for this event's `ingest_id`, mapped to a
     * `DeliveryResource`-shaped array. Read-only — queries
     * `delivery_attempts` directly and creates nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function legacyDeliveries(): array
    {
        $latestPerDestination = DeliveryAttempt::query()
            ->where('ingest_id', $this->ingest_id)
            ->with(['destination' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->get()
            ->unique('destination_id');

        return $latestPerDestination
            ->map(fn (DeliveryAttempt $attempt): array => [
                'id' => null,
                'dispatch_uuid' => null,
                'kind' => DispatchKind::Original->value,
                'status' => $this->legacyStatusFor($attempt->status)->value,
                'next_attempt_at' => null,
                'attempt_limit' => null,
                'destination' => [
                    'http_method' => $attempt->destination->http_method->value,
                    'url' => $attempt->destination->url,
                ],
                'attempts' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * Maps a legacy `delivery_attempts.status` to the presentation-only
     * `DeliveryStatus` used for the derived row (ruling 3): a resolved
     * outcome maps to its terminal `DeliveryStatus`, and an in-flight
     * `dispatched` attempt (no known outcome) is rendered as `Retrying`.
     */
    private function legacyStatusFor(AttemptStatus $status): DeliveryStatus
    {
        return match ($status) {
            AttemptStatus::Succeeded => DeliveryStatus::Succeeded,
            AttemptStatus::Failed => DeliveryStatus::Failed,
            AttemptStatus::Dispatched => DeliveryStatus::Retrying,
        };
    }
}
