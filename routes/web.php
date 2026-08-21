<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\ProxyEventController;
use App\Http\Controllers\ProxyEventReplayController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\ApplyTeamScope;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, ApplyTeamScope::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Proxy management (team-scoped; route-model binding resolves through the
        // team global scope, so a cross-team id 404s).
        Route::resource('proxies', ProxyController::class);
        Route::delete('proxies/{proxy}/destinations/{destination}', [DestinationController::class, 'destroy'])
            ->scopeBindings()
            ->name('proxies.destinations.destroy');
        Route::get('proxies/{proxy}/events', [ProxyEventController::class, 'index'])
            ->scopeBindings()
            ->name('proxies.events.index');
        Route::get('proxies/{proxy}/events/{event}', [ProxyEventController::class, 'show'])
            ->scopeBindings()
            ->name('proxies.events.show');
        Route::post('proxies/{proxy}/events/{event}/replay', [ProxyEventReplayController::class, 'store'])
            ->scopeBindings()
            ->name('proxies.events.replay');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
