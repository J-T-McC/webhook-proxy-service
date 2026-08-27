<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateHorizon;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * Horizon guards its own routes with this gate, independently of the
     * middleware listed in `config/horizon.php`. Both consult
     * `AuthenticateHorizon::passes()`, so the dashboard stays closed even if
     * the middleware is removed from that list — one missing line in config
     * should not be able to expose the queue.
     *
     * The default scaffolding matches an allow-list of user email addresses.
     * That is deliberately not used: this application has no superadmin role,
     * and dashboard access is deployment configuration rather than a property
     * of an application account. The `$user` argument is therefore ignored —
     * an authenticated team member is not thereby an operator.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return AuthenticateHorizon::passes(request());
        });
    }
}
