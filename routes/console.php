<?php

use App\Actions\SweepStalledFifoDispatches;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

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
