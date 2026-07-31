<?php

namespace App\Http\Middleware;

use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Scopes\TeamScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the current-team read constraint to the team-owned models for the
 * duration of a team-scoped request only.
 *
 * Team scoping is deliberately NOT a global model scope (which was always-on and
 * wrongly constrained default framework routes with no team context). Instead
 * this middleware registers {@see TeamScope} on the three team-owned models while
 * the request is in flight and removes it afterwards, so:
 *  - default/settings routes and the token-authenticated ingest path stay unscoped;
 *  - route-model binding still 404s cross-team ids — PROVIDED this middleware runs
 *    BEFORE SubstituteBindings (see the priority registration in bootstrap/app.php),
 *    so the binding lookup query already carries the team predicate.
 *
 * Removal on the way out matters for process isolation: Eloquent global scopes live
 * in a shared static, so a leaked TeamScope would bleed into later requests in the
 * same process (tests, queue workers, Octane). Only TeamScope is stripped; the
 * SoftDeletes scope registered at model boot is preserved.
 */
class ApplyTeamScope
{
    /**
     * The team-owned models this middleware scopes.
     *
     * @var list<class-string<Model>>
     */
    private const MODELS = [
        Proxy::class,
        Destination::class,
        DeliveryAttempt::class,
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::MODELS as $model) {
            $model::addGlobalScope(new TeamScope);
        }

        try {
            return $next($request);
        } finally {
            $scopes = Model::getAllGlobalScopes();

            foreach (self::MODELS as $model) {
                unset($scopes[$model][TeamScope::class]);
            }

            Model::setAllGlobalScopes($scopes);
        }
    }
}
