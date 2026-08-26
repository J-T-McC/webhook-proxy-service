<?php

use App\Actions\SweepDueRetries;
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

// Retry liveness net (ADR-015 Decision 5): re-dispatch retries whose delayed job
// was dropped or lost. Fixed cadence — not a tunable, mirroring the FIFO sweeper.
Schedule::call(fn () => SweepDueRetries::run())
    ->everyMinute()
    ->description('Sweep due retries');

// Retention garbage collection (AC5; ADR-012 Decision 7): erase expired stored
// payloads in place, daily, off-peak. Fixed cadence — not a tunable, matching
// the FIFO sweeper's posture above.
Schedule::command('payloads:purge-expired')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->description('Erase expired stored payloads');

// Bounds how long a plaintext copy of an event's payload can persist in
// `failed_jobs` (Q-05-06 D2; ruling E1, Project Owner-accepted 2026-08-25).
// This is a mitigation, not the fix — #10 owns encrypting/scrubbing that
// store. 7 days gives on-call a full week, weekends included, to see and
// triage a failure before its record is pruned, while still bounding an
// otherwise-indefinite plaintext exposure to a small fraction of the 30-day
// payload retention window.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 7])
    ->daily()
    ->description('Prune failed job records older than 7 days');
