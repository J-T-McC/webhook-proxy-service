<?php

namespace App\Http\Resources;

use App\Models\DeliveryAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery-attempt row on the events read surface (T25; AC12, AC17;
 * ADR-017 Decision 5). Immutable attempt facts only — no payload content
 * (ADR-003; DeliveryAttempt is payload-free by construction).
 *
 * @mixin DeliveryAttempt
 */
class DeliveryAttemptResource extends JsonResource
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
            'attempt_number' => $this->attempt_number,
            'status' => $this->status->value,
            'http_status' => $this->http_status,
            'error_summary' => $this->error_summary,
            'started_at' => $this->started_at,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
