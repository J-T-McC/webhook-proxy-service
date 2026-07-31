<?php

use App\Http\Controllers\IngestController;
use App\Http\Middleware\EnforceIngestBodyLimit;
use App\Http\Middleware\EnsureIngestIsSecure;
use Illuminate\Support\Facades\Route;

/*
 * Public ingest endpoint — registered OUTSIDE the web group (no session, CSRF-exempt).
 * Defense-in-depth guards: app-layer HTTPS assert, body-size cap, per-token throttle.
 */
Route::match(['post', 'put'], '/ingest/{token}', IngestController::class)
    ->middleware([
        EnsureIngestIsSecure::class,
        EnforceIngestBodyLimit::class,
        'throttle:ingest',
    ])
    ->name('ingest');
