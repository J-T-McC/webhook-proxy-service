<?php

namespace Tests\Feature\Queue;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Q-05-06 D2 / ruling E1 (Project Owner, 2026-08-25): a scheduled
 * `queue:prune-failed` bounds how long a plaintext copy of an event's
 * payload can persist in `failed_jobs` while #10 is unbuilt. Mitigation
 * only — not a substitute for #10's encryption/scrubbing work.
 */
class PruneFailedJobsScheduleTest extends TestCase
{
    private function insertFailedJob(string $failedAt): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'stub',
            'failed_at' => $failedAt,
        ]);

        return $uuid;
    }

    public function test_the_prune_is_registered_and_scheduled_daily_for_seven_days(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => $e->description === 'Prune failed job records older than 7 days',
        );

        $this->assertNotNull($event, 'Expected queue:prune-failed to be scheduled.');
        $this->assertSame('0 0 * * *', $event->expression, 'The prune must run daily().');
        $this->assertStringContainsString('queue:prune-failed', $event->command);
        $this->assertStringContainsString('--hours=168', $event->command);
    }

    public function test_a_failed_job_older_than_seven_days_is_pruned_and_a_younger_one_survives(): void
    {
        // `Schedule::command()` events run as a real subprocess (Event::run() ->
        // execute()), invisible to this test's wrapped transaction — so, as with
        // `payloads:purge-expired` (PurgeExpiredPayloadsTest), the scheduled
        // *registration* (above) and the command's own *effect* (here) are
        // proven separately; this invokes the artisan command directly, with the
        // exact `--hours` value the schedule entry passes.
        $oldUuid = $this->insertFailedJob(now()->subDays(8)->toDateTimeString());
        $recentUuid = $this->insertFailedJob(now()->subDays(6)->toDateTimeString());

        $this->artisan('queue:prune-failed', ['--hours' => 24 * 7])->assertSuccessful();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $oldUuid]);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $recentUuid]);
    }
}
