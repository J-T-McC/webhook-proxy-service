<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Concerns\HasCreator;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Observers\ProxyObserver;
use App\Services\IngestTokenService;
use Database\Factories\ProxyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 * @property int|null $retry_attempt_limit
 * @property RetryBackoffStrategy|null $retry_backoff_strategy
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string $ingest_token_hash
 * @property string $ingest_token
 * @property list<string>|null $sensitive_fields
 * @property Carbon|null $paused_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, Destination> $destinations
 * @property-read Collection<int, DeliveryAttempt> $deliveryAttempts
 * @property-read Collection<int, WebhookEvent> $webhookEvents
 * @property-read Collection<int, ProxySecret> $secrets
 */
#[Fillable([
    'team_id',
    'name',
    'mode',
    'processing_mode',
    'retry_attempt_limit',
    'retry_backoff_strategy',
    'response_status',
    'response_body',
    'sensitive_fields',
])]
#[ObservedBy(ProxyObserver::class)]
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
     * The proxy's captured events (T23; AC15-AC17, plan §API). Needed so
     * `{event}` route parameters can resolve via `->scopeBindings()` through
     * the proxy — a cross-team/cross-proxy event id therefore 404s rather than
     * resolving.
     *
     * @return HasMany<WebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    /**
     * The proxy's rotating secrets (signing). `SecretStore` is the single
     * reader and writer of this relation's underlying table (plan-10
     * Technical ruling 14) — nothing else queries `proxy_secrets` directly,
     * and this relation is never eager-loaded onto a resource.
     *
     * @return HasMany<ProxySecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(ProxySecret::class);
    }

    /**
     * Map the `{event}` route parameter to the `webhookEvents()` relation for
     * scoped bindings (T24). Eloquent's own convention would otherwise guess
     * `events()` (`Str::plural(Str::camel($childType))` on the route parameter
     * name) — which does not exist on this model — so this override is the
     * minimal, documented seam for a route-parameter name that legitimately
     * differs from its backing relation name (`{destination}` still resolves
     * via the untouched default convention, since `destinations()` already
     * matches it).
     */
    protected function childRouteBindingRelationshipName($childType): string
    {
        return $childType === 'event' ? 'webhookEvents' : parent::childRouteBindingRelationshipName($childType);
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
     * Whether dispatch is currently paused for this proxy (item #15, AC1).
     * `paused_at` is the two-state signal AND the "since when" timestamp
     * (AC14) — never a separate boolean column.
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
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
            'retry_backoff_strategy' => RetryBackoffStrategy::class,
            'response_status' => 'integer',
            'ingest_token' => 'encrypted',
            'sensitive_fields' => 'array',
            'paused_at' => 'datetime',
        ];
    }
}
