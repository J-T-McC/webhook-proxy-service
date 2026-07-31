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

];
