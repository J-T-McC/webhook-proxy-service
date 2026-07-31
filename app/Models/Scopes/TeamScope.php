<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Constrains team-owned models to the authenticated user's current team.
 *
 * Applied only when a user is authenticated with a current team. Unauthenticated
 * contexts (console, the token-authenticated ingest path) are not constrained by
 * this scope; the ingest path removes it explicitly via
 * withoutGlobalScope(TeamScope::class), which keeps the SoftDeletes scope intact.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class TeamScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user instanceof User && $user->current_team_id !== null) {
            $builder->where($model->getTable().'.team_id', $user->current_team_id);
        }
    }
}
