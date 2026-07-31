<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App-layer HTTPS assert for the ingest endpoint (HTTPS-only invariant, Owner
 * security decision 2026-07-30 / PRD-01 AC17). Defense-in-depth alongside edge
 * TLS termination: a non-HTTPS ingest request is rejected, never silently
 * accepted.
 *
 * Note: behind a TLS-terminating load balancer, trusted proxies + X-Forwarded-Proto
 * must be configured (ops) for `isSecure()` to observe the client's HTTPS.
 */
class EnsureIngestIsSecure
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(! $request->isSecure(), 403, 'HTTPS is required.');

        return $next($request);
    }
}
