<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // ProxyPolicy is resolved by Laravel's automatic policy discovery
        // (App\Policies\{Model}Policy), so no manual Gate::policy registration
        // is required here.
        $this->configureRateLimiting();
    }

    /**
     * Configure the per-token ingest throttle (config-driven; high placeholder).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ingest', function (Request $request): Limit {
            // Key by the token hash so the plaintext token never lands in a cache key.
            $token = (string) $request->route('token');

            return Limit::perMinute((int) config('ingest.rate_limit_per_minute'))
                ->by('ingest:'.hash('sha256', $token));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
