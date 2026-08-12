<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Enums\AttemptStatus;
use Database\Factories\DeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable, always-retained record of one delivery attempt (ADR-003).
 * Payload-free by construction: no body column, no soft delete.
 *
 * @property int $id
 * @property int|null $delivery_id
 * @property int $team_id
 * @property int $proxy_id
 * @property int $destination_id
 * @property string $ingest_id
 * @property AttemptStatus $status
 * @property int|null $http_status
 * @property string|null $error_summary
 * @property int $attempt_number
 * @property Carbon $started_at
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proxy $proxy
 * @property-read Destination $destination
 * @property-read Delivery|null $delivery
 */
#[Fillable([
    'delivery_id',
    'team_id',
    'proxy_id',
    'destination_id',
    'ingest_id',
    'status',
    'http_status',
    'error_summary',
    'attempt_number',
    'started_at',
    'duration_ms',
])]
class DeliveryAttempt extends Model
{
    /** @use HasFactory<DeliveryAttemptFactory> */
    use BelongsToCurrentTeam, HasFactory;

    /**
     * The proxy this attempt belongs to.
     *
     * @return BelongsTo<Proxy, $this>
     */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Proxy::class);
    }

    /**
     * The destination this attempt targeted.
     *
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * The delivery this attempt belongs to. Nullable for pre-#6 rows only.
     *
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
        ];
    }
}
