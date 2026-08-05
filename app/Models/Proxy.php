<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Concerns\HasCreator;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Services\IngestTokenService;
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
 * @property int|null $created_by
 * @property string $name
 * @property ProxyMode $mode
 * @property ProcessingMode $processing_mode
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string $ingest_token_hash
 * @property string $ingest_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, Destination> $destinations
 * @property-read Collection<int, DeliveryAttempt> $deliveryAttempts
 */
#[Fillable(['team_id', 'name', 'mode', 'processing_mode', 'response_status', 'response_body'])]
class Proxy extends Model
{
    /** @use HasFactory<ProxyFactory> */
    use BelongsToCurrentTeam, HasCreator, HasFactory, SoftDeletes;

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
     * The absolute public ingest URL for this proxy.
     *
     * Built from server config (`config('ingest.url')`) and the decrypted token —
     * never the request `Host` header (ADR-006 Host-header injection guard).
     */
    public function ingestUrl(): string
    {
        return rtrim((string) config('ingest.url'), '/').'/ingest/'.$this->ingest_token;
    }

    /**
     * Rotate this proxy's ingest token and persist it. No UI exists at item #1.
     */
    public function rotateIngestToken(): void
    {
        app(IngestTokenService::class)->rotate($this);
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
            'processing_mode' => ProcessingMode::class,
            'response_status' => 'integer',
            'ingest_token' => 'encrypted',
        ];
    }
}
