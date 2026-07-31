<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Enums\ProxyMode;
use Database\Factories\ProxyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property ProxyMode $mode
 * @property string $ingest_token_hash
 * @property string $ingest_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read Collection<int, Destination> $destinations
 * @property-read Collection<int, DeliveryAttempt> $deliveryAttempts
 */
#[Fillable(['team_id', 'name', 'mode'])]
class Proxy extends Model
{
    /** @use HasFactory<ProxyFactory> */
    use BelongsToCurrentTeam, HasFactory, SoftDeletes;

    /**
     * The team that owns the proxy.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The proxy's live (non-soft-deleted) destinations.
     *
     * @return HasMany<Destination, $this>
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    /**
     * The proxy's retained delivery attempts.
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
            'mode' => ProxyMode::class,
            'ingest_token' => 'encrypted',
        ];
    }
}
