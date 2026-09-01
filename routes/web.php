<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\DestinationValidationController;
use App\Http\Controllers\DestinationValidationSendController;
use App\Http\Controllers\EventQueueController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\ProxyEventController;
use App\Http\Controllers\ProxyEventPayloadController;
use App\Http\Controllers\ProxyEventReplayController;
use App\Http\Controllers\ProxyPauseController;
use App\Http\Controllers\ProxySigningController;
use App\Http\Controllers\ProxySigningOverlapController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\ApplyTeamScope;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// User documentation (docs/briefs/user-docs-page.md). Public: it has to be
// readable before registering, and a signed-in user reads the same page.
Route::inertia('/docs', 'Docs')->name('docs');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, ApplyTeamScope::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Team-wide event queue: every captured event across every proxy the
        // team owns (distinct from `proxies.events.index`, which is scoped to
        // one proxy).
        Route::get('events', [EventQueueController::class, 'index'])->name('events.index');

        // Proxy management (team-scoped; route-model binding resolves through the
        // team global scope, so a cross-team id 404s).
        Route::resource('proxies', ProxyController::class);
        Route::delete('proxies/{proxy}/destinations/{destination}', [DestinationController::class, 'destroy'])
            ->scopeBindings()
            ->name('proxies.destinations.destroy');
        // The member-facing Validate action (#18 AC14) — not the public
        // approval route below, which is signature-gated and team-less.
        Route::post('proxies/{proxy}/destinations/{destination}/validate', [DestinationValidationSendController::class, 'store'])
            ->scopeBindings()
            ->name('proxies.destinations.validate.store');
        Route::get('proxies/{proxy}/events', [ProxyEventController::class, 'index'])
            ->scopeBindings()
            ->name('proxies.events.index');
        Route::get('proxies/{proxy}/events/{event}', [ProxyEventController::class, 'show'])
            ->scopeBindings()
            ->name('proxies.events.show');
        Route::get('proxies/{proxy}/events/{event}/payload', ProxyEventPayloadController::class)
            ->scopeBindings()
            ->name('proxies.events.payload');
        Route::post('proxies/{proxy}/events/{event}/replay', [ProxyEventReplayController::class, 'store'])
            ->scopeBindings()
            ->name('proxies.events.replay');
        Route::post('proxies/{proxy}/pause', [ProxyPauseController::class, 'store'])
            ->name('proxies.pause.store');
        Route::delete('proxies/{proxy}/pause', [ProxyPauseController::class, 'destroy'])
            ->name('proxies.pause.destroy');
        Route::post('proxies/{proxy}/signing', [ProxySigningController::class, 'store'])
            ->name('proxies.signing.store');
        Route::delete('proxies/{proxy}/signing', [ProxySigningController::class, 'destroy'])
            ->name('proxies.signing.destroy');
        Route::delete('proxies/{proxy}/signing/overlap', [ProxySigningOverlapController::class, 'destroy'])
            ->name('proxies.signing.overlap.destroy');
    });

// Destination validation (#18). PUBLIC and UNAUTHENTICATED by design: the
// person who approves a destination is whoever receives the webhook there, who
// has no account and no other contact with the product (AC26). The signature is
// the only credential, and `signed` is the only gate.
//
// Outside the `{current_team}` prefix deliberately — the approver has no team,
// and the team scope must not hide the destination from its own approval route.
//
// The GET renders and never mutates (AC28). Approval is the POST, because a GET
// that approved on load would be triggered by link scanners, mail preview
// fetchers and corporate security proxies opening the link before any human
// does.
Route::middleware('signed')->group(function () {
    Route::get('destinations/{destination}/validate', [DestinationValidationController::class, 'show'])
        ->name('destinations.validate.show');
    Route::post('destinations/{destination}/validate', [DestinationValidationController::class, 'store'])
        ->name('destinations.validate.store');
});

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
