<?php

use App\Http\Middleware\ApplyTeamScope;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Public ingest routes — outside the web group (no session, CSRF-exempt).
            Route::group([], __DIR__.'/../routes/ingest.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the immediate connection's X-Forwarded-* headers (all of it — no
        // deploy target/static load-balancer IP range is defined yet, docs/reviews/
        // review-01-walking-skeleton.md finding #2; Laravel's own documented
        // alternative for platforms without an enumerable proxy IP range, e.g.
        // AWS ALB/ELB, Heroku, Cloudflare). Required for `EnsureIngestIsSecure`'s
        // `isSecure()` to observe the client's true HTTPS scheme when TLS is
        // terminated at a load balancer in front of the app, rather than reading
        // the app's own plaintext connection from the LB as `http`. Safe ONLY
        // because the app must never be reachable except through that LB — an
        // infra/network-layer guarantee this line does not itself enforce.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);

        // Team scoping is applied selectively by ApplyTeamScope on the team-scoped
        // route group (see routes/web.php), never globally. It MUST run before
        // SubstituteBindings so route-model binding queries carry the team predicate
        // and cross-team ids 404.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ApplyTeamScope::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
