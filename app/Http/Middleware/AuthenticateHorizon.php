<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic authentication for the Horizon dashboard, checked against two
 * environment variables rather than against the `users` table.
 *
 * This application has no superadmin role — a user belongs to teams, and team
 * membership does not imply operational access to the queue. Horizon's default
 * `viewHorizon` gate expects an allow-list of user email addresses, which would
 * make operational access a property of an application account. Basic auth
 * keeps the two separate: the dashboard's credentials are deployment
 * configuration, not a user record.
 *
 * The browser's own credential prompt is the login form here, which is why the
 * 401 carries a `WWW-Authenticate` header.
 *
 * **This fails closed.** If either environment variable is missing or empty,
 * every request is rejected — a deployment that forgets to set them gets a
 * locked dashboard, never an open one. Do not "helpfully" fall through to
 * allowing access when unconfigured.
 *
 * `passes()` is deliberately public and is also called by the `viewHorizon`
 * gate in `App\Providers\HorizonServiceProvider`. Horizon guards its routes
 * with that gate independently of this middleware, so having both consult the
 * same check means removing this class from `config/horizon.php`'s middleware
 * list cannot silently open the dashboard.
 *
 * Basic credentials are sent base64-encoded, not encrypted, so this is only
 * meaningful over HTTPS. It is the transport, not this class, that keeps them
 * off the wire in cleartext.
 */
class AuthenticateHorizon
{
    public function handle(Request $request, Closure $next): Response
    {
        // Unconfigured and wrong-credentials are answered differently on
        // purpose. A 401 invites the browser to prompt, which is the right
        // answer when a correct credential exists to be typed. When none is
        // configured no input can ever succeed, so prompting would loop the
        // operator forever — 403 says so plainly. Both are closed; only the
        // explanation differs.
        if (! self::configured()) {
            abort(403, 'Horizon dashboard credentials are not configured.');
        }

        if (! self::passes($request)) {
            return response('Unauthorized.', 401, [
                'WWW-Authenticate' => 'Basic realm="Horizon", charset="UTF-8"',
            ]);
        }

        return $next($request);
    }

    /**
     * Whether both dashboard credentials are set to a non-empty value.
     */
    public static function configured(): bool
    {
        return (string) config('horizon.basic_auth.username') !== ''
            && (string) config('horizon.basic_auth.password') !== '';
    }

    /**
     * Whether this request carries the configured dashboard credentials.
     */
    public static function passes(Request $request): bool
    {
        if (! self::configured()) {
            return false;
        }

        // Both comparisons run every time, and both use `hash_equals`, so the
        // response time does not vary with how much of either credential was
        // correct. Written as two assignments combined afterwards rather than
        // as a single `&&` expression, because `&&` short-circuits and would
        // skip the password comparison whenever the username was wrong.
        $userMatches = hash_equals(
            (string) config('horizon.basic_auth.username'),
            (string) $request->getUser(),
        );
        $passwordMatches = hash_equals(
            (string) config('horizon.basic_auth.password'),
            (string) $request->getPassword(),
        );

        return $userMatches && $passwordMatches;
    }
}
