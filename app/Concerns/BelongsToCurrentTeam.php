<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-assigns `team_id` on create for team-owned models (AC5/AC15).
 *
 * Team-read scoping is NOT applied here. The current-team query constraint is
 * applied selectively by the ApplyTeamScope middleware on the team-scoped route
 * group only, so default framework routes that carry no team context (e.g. the
 * settings routes) are never wrongly constrained. The token-authenticated ingest
 * path likewise runs unscoped without needing to strip a global scope.
 */
trait BelongsToCurrentTeam
{
    /**
     * Boot the trait: register the team_id auto-assignment on create.
     */
    public static function bootBelongsToCurrentTeam(): void
    {
        static::creating(function (Model $model): void {
            $user = Auth::user();

            if (empty($model->getAttribute('team_id')) && $user instanceof User && $user->current_team_id !== null) {
                $model->setAttribute('team_id', $user->current_team_id);
            }
        });
    }
}
