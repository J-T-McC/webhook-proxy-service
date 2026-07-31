<?php

namespace App\Concerns;

use App\Models\Scopes\TeamScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Applies the current-team global scope and auto-assigns `team_id` on create for
 * team-owned models (AC5/AC15). The ingest path strips only the TeamScope, never
 * the SoftDeletes scope.
 */
trait BelongsToCurrentTeam
{
    /**
     * Boot the trait: register the global scope and the team_id auto-assignment.
     */
    public static function bootBelongsToCurrentTeam(): void
    {
        static::addGlobalScope(new TeamScope);

        static::creating(function (Model $model): void {
            $user = Auth::user();

            if (empty($model->getAttribute('team_id')) && $user instanceof User && $user->current_team_id !== null) {
                $model->setAttribute('team_id', $user->current_team_id);
            }
        });
    }
}
