<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Enums\HttpMethod;
use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $proxy_id
 * @property int $team_id
 * @property string $url
 * @property HttpMethod $http_method
 * @property string|null $credential_header_name
 * @property string|null $credential_secret
 * @property Carbon|null $credential_set_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Proxy $proxy
 */
#[Fillable(['proxy_id', 'team_id', 'url', 'http_method', 'credential_header_name', 'credential_set_at'])]
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use BelongsToCurrentTeam, HasFactory, SoftDeletes;

    /**
     * The proxy this destination belongs to.
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
            'http_method' => HttpMethod::class,
            'credential_secret' => 'encrypted',
            'credential_set_at' => 'datetime',
        ];
    }
}
