<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use Database\Factories\DispatchedPayloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The dispatched-output store (AC12, AC13, AC15; ADR-013 Decision 1) — one row
 * per received event, holding the payload actually sent downstream. `body` is
 * only ever set when the dispatched payload diverges from the raw capture
 * (ADR-013 Decision 2); it is NULL both when identical and after the
 * retention expiry pass erases it. `BelongsToCurrentTeam` is defence for a
 * future read path only — no read path is built at #5 (Q-05-01 Option B).
 *
 * @property int $id
 * @property int $team_id
 * @property int $proxy_id
 * @property int $webhook_event_id
 * @property string|null $body
 * @property int $byte_size
 * @property Carbon $dispatched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebhookEvent $webhookEvent
 * @property-read Proxy $proxy
 */
#[Fillable(['team_id', 'proxy_id', 'webhook_event_id', 'body', 'byte_size', 'dispatched_at'])]
class DispatchedPayload extends Model
{
    /** @use HasFactory<DispatchedPayloadFactory> */
    use BelongsToCurrentTeam, HasFactory;

    /**
     * The captured event this dispatched payload belongs to.
     *
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    /**
     * The proxy that dispatched this payload.
     *
     * @return BelongsTo<Proxy, $this>
     */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Proxy::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted at rest, mirroring webhook_events.body (ADR-010 Amendment B).
            // byte_size records the PLAINTEXT dispatched size, set before this cast
            // encrypts.
            'body' => 'encrypted',
            'byte_size' => 'integer',
            'dispatched_at' => 'datetime',
        ];
    }
}
