<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The retry/replay unit of work (ADR-015 Decision 1) — one row per
 * (dispatch, destination) pair. `status` is transitioned only by
 * compare-and-set on the query builder, keyed on the prior status (e.g.
 * `WHERE status IN ('pending','retrying')`) — a zero-row CAS means another
 * settler already won; NEVER a blind `save()` (plan-06 binding invariant).
 * `dispatch_uuid` identifies one logical dispatch (original send or replay,
 * ADR-017) across all of a proxy's destinations. Payload-free by
 * construction: retries resend the recorded dispatched output, replays
 * re-process raw through the pipeline — neither is carried on this row.
 *
 * @property int $id
 * @property int $team_id
 * @property int $proxy_id
 * @property int $destination_id
 * @property int $webhook_event_id
 * @property string $dispatch_uuid
 * @property DispatchKind $kind
 * @property DeliveryStatus $status
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proxy|null $proxy
 * @property-read Destination $destination
 * @property-read WebhookEvent $webhookEvent
 */
#[Fillable([
    'team_id',
    'proxy_id',
    'destination_id',
    'webhook_event_id',
    'dispatch_uuid',
    'kind',
    'status',
    'next_attempt_at',
])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use BelongsToCurrentTeam, HasFactory;

    /**
     * The proxy this delivery belongs to. Excludes a soft-deleted proxy by
     * `Proxy`'s own `SoftDeletes` scope — a consumer that must keep resolving
     * an in-flight/historical delivery's proxy after it's been soft-deleted
     * (e.g. `RetryPolicy::attemptLimitFor()`, non-nullable) needs
     * `proxy()->withTrashed()->firstOrFail()` explicitly.
     *
     * @return BelongsTo<Proxy, $this>
     */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Proxy::class);
    }

    /**
     * The destination this delivery targets.
     *
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * The captured event this delivery is dispatching.
     *
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    /**
     * The attempt records made against this delivery.
     *
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DispatchKind::class,
            'status' => DeliveryStatus::class,
            'next_attempt_at' => 'datetime',
        ];
    }
}
