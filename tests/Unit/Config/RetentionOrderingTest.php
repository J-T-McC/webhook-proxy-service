<?php

namespace Tests\Unit\Config;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Q-10-02 finding B: `queue:prune-failed`'s window (`routes/console.php`),
 * Horizon's `failed`/`monitored` trim windows (`config/horizon.php`), and the
 * resolved `retention.days` payload window (`config/retention.php`,
 * env-overridable) must stay correctly ordered — the failed-job/Horizon
 * windows strictly below the retention window — so a `failed_jobs` record
 * (which can carry a copy of a captured payload) cannot outlive the retention
 * pass meant to erase the content it references.
 *
 * Reads all three from their actual sources rather than asserting hardcoded
 * literals, so the assertion holds at whatever the current environment
 * resolves them to (`RETENTION_DAYS` is env-overridable; the other two are
 * fixed literals in different files).
 */
class RetentionOrderingTest extends TestCase
{
    public function test_failed_job_and_horizon_trim_windows_resolve_below_the_retention_window(): void
    {
        $schedule = app(Schedule::class);

        $pruneEvent = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command, 'queue:prune-failed'),
        );

        $this->assertNotNull($pruneEvent, 'Expected queue:prune-failed to be scheduled.');
        $this->assertMatchesRegularExpression('/--hours=(\d+)/', $pruneEvent->command);

        preg_match('/--hours=(\d+)/', $pruneEvent->command, $matches);
        $pruneFailedMinutes = ((int) $matches[1]) * 60;

        $horizonFailedMinutes = (int) config('horizon.trim.failed');
        $horizonMonitoredMinutes = (int) config('horizon.trim.monitored');

        $retentionMinutes = (int) config('retention.days') * 24 * 60;

        $this->assertGreaterThan(
            $pruneFailedMinutes,
            $retentionMinutes,
            'queue:prune-failed window must resolve strictly below the retention window.',
        );
        $this->assertGreaterThan(
            $horizonFailedMinutes,
            $retentionMinutes,
            "Horizon's failed-job trim window must resolve strictly below the retention window.",
        );
        $this->assertGreaterThan(
            $horizonMonitoredMinutes,
            $retentionMinutes,
            "Horizon's monitored-job trim window must resolve strictly below the retention window.",
        );
    }
}
