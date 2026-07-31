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
 * Whenever a user is authenticated this scope ALWAYS adds a team_id predicate —
 * fail-closed. A signed-in user who has no current team (current_team_id === null)
 * is constrained to the sentinel id 0, which no row can own, so they see ZERO rows
 * rather than every team's data. (Previously the predicate was skipped in that
 * case, silently leaking all teams' records to a team-less user.)
 *
 * Unauthenticated system contexts (console, the token-authenticated ingest /
 * delivery pipeline) are intentionally NOT constrained here — no authenticated web
 * route reaches this branch, and the ingest path removes this scope explicitly via
 * withoutGlobalScope(TeamScope::class) where it legitimately resolves a proxy by
 * token (keeping the SoftDeletes scope intact).
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

        if (! $user instanceof User) {
            return;
        }

        // `?? 0` is the fail-closed short-circuit: a team-less authenticated user
        // matches a team id no row owns, so the result set is empty, never global.
        $builder->where($model->getTable().'.team_id', $user->current_team_id ?? 0);
    }
}
