<?php

namespace App\Http\Resources;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The team-wide event queue's row shape (`EventQueueController`). Distinct
 * from `WebhookEventResource`: no `deliveries`, but carries the owning proxy
 * (id/name/paused) that a single-proxy page never needs to repeat.
 *
 * `status` is a three-value DISPLAY string — `pending`/`dispatched` from the
 * stored column, or `expired` computed here — never the raw column alone (see
 * `WebhookEvent`'s docblock).
 *
 * `expired` means the payload was cleaned BEFORE the event was ever dispatched,
 * so it never went and never will. A cleaned payload on an event that already
 * dispatched is retention doing its job and still reads `dispatched`: every
 * event's payload is eventually cleaned, so treating that alone as `expired`
 * would make the whole queue read `expired` regardless of what shipped, which
 * is the opposite of what this page is for. `proxy` is expected
 * eager-loaded (`with('proxy')`, `withTrashed()` so a deleted proxy still
 * shows its name) by the caller; this resource never queries for it.
 *
 * @mixin WebhookEvent
 */
class WebhookEventQueueResource extends JsonResource
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
            'status' => $this->payload_cleaned_at !== null
                && $this->status === WebhookEventStatus::Pending
                    ? 'expired'
                    : $this->status->value,
            'proxy' => [
                'id' => $this->proxy_id,
                'name' => $this->whenLoaded('proxy', fn () => $this->proxy->name),
                'paused' => $this->whenLoaded('proxy', fn () => $this->proxy->paused_at !== null),
            ],
        ];
    }
}
