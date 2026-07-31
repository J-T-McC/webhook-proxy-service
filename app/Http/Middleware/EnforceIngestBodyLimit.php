<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects ingest requests whose body exceeds the configured cap
 * (`config('ingest.max_body_bytes')`) with 413. The cap is a deliberately high
 * placeholder — not risk-tuned (Owner decision 2026-07-30).
 */
class EnforceIngestBodyLimit
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('ingest.max_body_bytes');

        $declared = $request->headers->get('Content-Length');
        $size = $declared !== null ? (int) $declared : strlen($request->getContent());

        abort_if($size > $max, 413, 'Ingest payload too large.');

        return $next($request);
    }
}
