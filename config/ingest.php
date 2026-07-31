<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest Base URL
    |--------------------------------------------------------------------------
    |
    | The absolute base URL used to build a proxy's public ingest endpoint
    | (`{ingest.url}/ingest/{token}`). This is the *sole* source of the ingest
    | host — the request `Host` header is never used to build ingest URLs
    | (ADR-006 Host-header injection guard). Falls back to the application URL
    | when `INGEST_URL` is not set.
    |
    */

    'url' => env('INGEST_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Ingest Body-Size Cap (bytes)
    |--------------------------------------------------------------------------
    |
    | Maximum accepted ingest request body size. Requests over this cap are
    | rejected with 413. PLACEHOLDER — deliberately very high (50 MB), NOT a
    | risk-tuned value; revisit before MVP / public exposure.
    |
    */

    'max_body_bytes' => (int) env('INGEST_MAX_BODY_BYTES', 52_428_800),

    /*
    |--------------------------------------------------------------------------
    | Per-Token Ingest Rate Limit (requests / minute)
    |--------------------------------------------------------------------------
    |
    | Per-token throttle applied to the ingest endpoint. Over-rate requests are
    | rejected with 429. PLACEHOLDER — deliberately very high, NOT risk-tuned;
    | revisit before MVP / public exposure.
    |
    */

    'rate_limit_per_minute' => (int) env('INGEST_RATE_LIMIT_PER_MINUTE', 6_000),

];
