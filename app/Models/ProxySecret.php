<?php

namespace App\Models;

use App\Enums\SecretPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rotating secret for a proxy (ADR-021 Decision 2) — outbound signing,
 * the only purpose remaining after ADR-026 Decision B removed inbound
 * verification. `App\Services\SecretStore` is the single reader and writer of this
 * table (plan-10 Technical ruling 14); no other class queries `proxy_secrets`
 * directly. `value` is `encrypted` at rest and additionally `$hidden`, so an
 * accidental eager-load into a resource still never serializes a plaintext
 * secret (ADR-021 Decision 6.1).
 *
 * `is_current IS NOT NULL` ⟺ `expires_at IS NULL` is the invariant the schema
 * relies on: `true` marks the live secret, `NULL` marks a superseded one
 * counting down to `expires_at` (plan-10 § Data Model).
 *
 * @property int $id
 * @property int $team_id
 * @property int $proxy_id
 * @property SecretPurpose $purpose
 * @property string $value
 * @property bool|null $is_current
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proxy $proxy
 */
#[Fillable(['team_id', 'proxy_id', 'purpose', 'value', 'is_current', 'expires_at'])]
class ProxySecret extends Model
{
    /**
     * Never serialize the secret value into a resource, prop or DTO
     * (ADR-021 Decision 6.1) — a second guard alongside "never queried
     * outside SecretStore".
     *
     * @var list<string>
     */
    protected $hidden = ['value'];

    /**
     * The proxy this secret belongs to.
     *
     * @return BelongsTo<Proxy, $this>
     */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Proxy::class);
    }

    /**
     * Scope to the live set for a purpose: the current secret, or a
     * superseded one whose overlap window has not yet passed — "current
     * first, non-expired" (plan-10's live-set predicate). Liveness is a
     * property of the data, not of a sweeper run.
     *
     * @param  Builder<ProxySecret>  $query
     * @return Builder<ProxySecret>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->where('is_current', true)
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('is_current')
                            ->where(function (Builder $query): void {
                                $query->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now());
                            });
                    });
            })
            ->orderByDesc('is_current');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => SecretPurpose::class,
            'value' => 'encrypted',
            'is_current' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
