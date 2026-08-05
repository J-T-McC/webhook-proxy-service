<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Enums\FifoDispatchStatus;
use Database\Factories\FifoDispatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One FIFO ordering row per received event, for FIFO proxies only (ADR-011
 * Decision 2). Holds claim/lease state only — no payload or delivery outcome.
 * `webhook_event_id` is the monotonic order key (UNIQUE). The atomic `FOR UPDATE`
 * claim over these rows (AdvanceProxyFifoQueue) is the FIFO correctness primitive.
 *
 * @property int $id
 * @property int $team_id
 * @property int $proxy_id
 * @property int $webhook_event_id
 * @property FifoDispatchStatus $status
 * @property Carbon|null $claimed_at
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proxy $proxy
 * @property-read WebhookEvent $webhookEvent
 */
#[Fillable([
    'team_id',
    'proxy_id',
    'webhook_event_id',
    'status',
    'claimed_at',
    'lease_expires_at',
    'settled_at',
])]
class FifoDispatch extends Model
{
    /** @use HasFactory<FifoDispatchFactory> */
    use BelongsToCurrentTeam, HasFactory;

    /**
     * The proxy whose ordered line this row belongs to.
     *
     * @return BelongsTo<Proxy, $this>
     */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Proxy::class);
    }

    /**
     * The captured event this row orders.
     *
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FifoDispatchStatus::class,
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
