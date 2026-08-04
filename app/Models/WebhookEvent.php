<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A durable, raw-only, immutable capture of one received webhook (ADR-010).
 * Holds the raw payload (bytes + method + inbound headers + content-type + byte
 * size), keyed by the same `ingest_id` the fan-out `delivery_attempts` carry
 * (ADR-003). No dispatched/derived output, no retention/GC state, no soft delete —
 * raw-only and immutable by construction. `body` is encrypted at rest (ADR-010
 * Amendment B); `headers` stay plaintext until #10.
 *
 * @property int $id
 * @property int $team_id
 * @property int $proxy_id
 * @property string $ingest_id
 * @property string $method
 * @property array<string, mixed> $headers
 * @property string|null $content_type
 * @property string $body
 * @property int $byte_size
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proxy $proxy
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
            'headers' => 'array',
            'received_at' => 'datetime',
            'byte_size' => 'integer',
        ];
    }
}
