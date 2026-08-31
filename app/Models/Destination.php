<?php

namespace App\Models;

use App\Concerns\BelongsToCurrentTeam;
use App\Enums\DestinationValidationState;
use App\Enums\DestinationValidationStatus;
use App\Enums\HttpMethod;
use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property DestinationValidationState $validation_state
 * @property Carbon|null $validated_at
 * @property Carbon|null $validation_challenge_sent_at
 * @property Carbon|null $validation_challenge_expires_at
 * @property string|null $validation_nonce
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Proxy $proxy
 */
// The `validation_*` columns are deliberately absent: nothing that reaches this
// model from a request payload may move a destination into the validated state.
// They are written only by the approval route and by the URL-change reset, both
// via forceFill (#18 AC3 — exactly one route to Validated).
#[Fillable(['proxy_id', 'team_id', 'url', 'http_method', 'credential_header_name', 'credential_secret', 'credential_set_at'])]
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use BelongsToCurrentTeam, HasFactory, SoftDeletes;

    /**
     * Mirrors the column default so a model that has not been reloaded still
     * reports a state. Without it `validationStatus()` reads null on a freshly
     * created instance, which would make the display state depend on whether
     * the caller happened to refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'validation_state' => DestinationValidationState::Unvalidated->value,
    ];

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
            'validation_state' => DestinationValidationState::class,
            'validated_at' => 'datetime',
            'validation_challenge_sent_at' => 'datetime',
            'validation_challenge_expires_at' => 'datetime',
        ];
    }

    /**
     * The four product-facing validation states (PRD-18 AC1), derived for
     * display. `Expired` has no stored counterpart — it is `Pending` whose
     * challenge window has closed.
     *
     * Never use this to decide whether to deliver. The gate is `validated()`,
     * which asks the stored column directly.
     */
    public function validationStatus(): DestinationValidationStatus
    {
        if ($this->validation_state === DestinationValidationState::Pending
            && $this->validation_challenge_expires_at?->isPast()) {
            return DestinationValidationStatus::Expired;
        }

        return DestinationValidationStatus::from($this->validation_state->value);
    }

    /**
     * The delivery gate (#18 AC8). Every one of the four enforcement points
     * shares this definition rather than repeating a `where`, so there is one
     * place to be wrong.
     *
     * Deliberately a positive test against `Validated` rather than a negation:
     * a state added later is excluded by default, which is the safe direction
     * for a gate whose failure mode is delivering to somebody who never
     * consented. Expired needs no special case — it is stored as `Pending`.
     *
     * @param  Builder<Destination>  $query
     */
    #[Scope]
    protected function validated(Builder $query): void
    {
        $query->where('validation_state', DestinationValidationState::Validated);
    }
}
