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

    /*
    |--------------------------------------------------------------------------
    | Response-Body Size Cap (bytes)
    |--------------------------------------------------------------------------
    |
    | Maximum accepted size for a proxy's user-defined ingest *response* body
    | (the acknowledgement returned immediately on ingest, independent of
    | delivery). Distinct from `max_body_bytes`, which caps the inbound request
    | body. Enforced as a validation rule when configuring a proxy. Default is
    | 8 KiB — a response contract is an acknowledgement / challenge-echo, not a
    | data channel.
    |
    */

    'response_body_max_bytes' => (int) env('INGEST_RESPONSE_BODY_MAX_BYTES', 8192),

];
