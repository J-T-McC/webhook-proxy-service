<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Enums\WebhookEventStatus;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A durable capture of one received webhook (ADR-010), keyed by the same
 * `ingest_id` the fan-out `delivery_attempts` carry (ADR-003). Immutable WHILE
 * its payload content is retained — the only authorised mutator is the
 * retention expiry pass (`PurgeExpiredPayloads`), which erases `body` and
 * `headers` in place and stamps `payload_cleaned_at` (AC11, AC21; ADR-014
 * Decisions 2, 4, 7). `body` and `headers` are both encrypted at rest (ADR-010
 * Amendment B; ADR-014 Decision 2 raises the floor to cover headers too).
 *
 * Guard on `payload_cleaned_at`, NEVER on `body === null`/`headers === null`
 * (ADR-014 Decision 7, binding) — `App\Services\StoredPayloadLookup` is the
 * only resolver of the cleaned state.
 *
 * `status` (event queue view) is written in exactly one place —
 * `ProcessIngestedWebhook::handle()`, only for the original dispatch — never
 * mass-assignable and never touched by the retention expiry pass. It does
 * NOT encode "expired"; a caller deriving the queue's displayed status reads
 * `payload_cleaned_at` for that, same as everywhere else.
 *
 * @property int $id
 * @property int $team_id
 * @property int $proxy_id
 * @property string $ingest_id
 * @property string $method
 * @property array<string, mixed>|null $headers
 * @property string|null $content_type
 * @property string|null $body
 * @property int $byte_size
 * @property Carbon $received_at
 * @property Carbon|null $payload_cleaned_at
 * @property WebhookEventStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proxy $proxy
 * @property-read Collection<int, Delivery> $deliveries
 */
#[Fillable([
    'team_id',
    'proxy_id',
    'ingest_id',
    'method',
    'headers',
    'content_type',
    'body',
    'byte_size',
    'received_at',
])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use BelongsToCurrentTeam, HasFactory;

    /**
     * The proxy that received this webhook.
     *
     * @return BelongsTo<Proxy, $this>
     */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Proxy::class);
    }

    /**
     * The `deliveries` rows dispatched for this event — original send plus any
     * replays (T25; ADR-015/017). Required by `WebhookEventResource` to render
     * the events read surface's per-destination state.
     *
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypt the raw body at rest (ADR-010 Amendment B). byte_size records the
            // PLAINTEXT size, set before this cast encrypts.
            'body' => 'encrypted',
            // Encrypted at rest (ADR-014 Decision 2/AC22a) — MEDIUMTEXT NULL, not JSON,
            // because MySQL validates `json` on write and the encrypted envelope is not
            // valid JSON.
            'headers' => 'encrypted:array',
            'received_at' => 'datetime',
            'byte_size' => 'integer',
            // The AC21 cleaned-state signal. NOT added to #[Fillable] — the expiry pass
            // writes it through the query builder only, never mass assignment.
            'payload_cleaned_at' => 'datetime',
            // The event queue's dispatch-progress signal. NOT added to #[Fillable] —
            // ProcessIngestedWebhook writes it through the query builder only.
            'status' => WebhookEventStatus::class,
        ];
    }
}
