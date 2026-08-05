<?php

use App\Actions\SweepStalledFifoDispatches;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;
use Lorisleiva\Actions\Facades\Actions;

// Registers `AsCommand` actions as Artisan commands (lorisleiva/laravel-actions
// does not do this automatically) — first needed by `PurgeExpiredPayloads` (#5).
Actions::registerCommands();

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// FIFO liveness net (ADR-005 (b), ADR-011): reap orphaned claims and nudge idle
// FIFO proxies whose line stalled. Fixed cadence — not a tunable.
Schedule::call(fn () => SweepStalledFifoDispatches::run())
    ->everyMinute()
    ->description('Sweep stalled FIFO dispatches');

// Retention garbage collection (AC5; ADR-012 Decision 7): erase expired stored
// payloads in place, daily, off-peak. Fixed cadence — not a tunable, matching
// the FIFO sweeper's posture above.
Schedule::command('payloads:purge-expired')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->description('Erase expired stored payloads');
