<?php

namespace Tests\Feature\Console;

use App\Actions\ExpireProxySecrets;
use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\Team;
use App\Services\SecretStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T15 — the `secrets:purge-expired` daily sweeper, the liveness net for a
 * lost or dropped {@see ExpireProxySecrets} delayed job (R10; ADR-021
 * Decision 3, ADR-015 Decision 5's shape).
 */
class PurgeExpiredProxySecretsTest extends TestCase
{
    public function test_the_sweep_is_registered_and_scheduled_daily(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => $e->description === 'Erase superseded proxy secrets past their overlap window',
        );

        $this->assertNotNull($event, 'Expected secrets:purge-expired to be scheduled.');
        $this->assertSame('0 0 * * *', $event->expression, 'The sweep must run daily().');
        $this->assertStringContainsString('secrets:purge-expired', $event->command);
    }

    public function test_the_sweeper_deletes_an_expired_row_when_the_delayed_job_never_ran(): void
    {
        // Fake the queue so ExpireProxySecrets' own delayed dispatch never
        // actually runs — simulating a lost job (R10).
        Queue::fake();

        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->state(['team_id' => $team->id])->createQuietly();

        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'old-secret');
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'new-secret');

        ExpireProxySecrets::assertPushed(1);

        $superseded = ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing)
            ->whereNull('is_current')
            ->firstOrFail();

        // The job never ran, so move the row's own overlap into the past
        // directly, exactly as if 24 real hours had elapsed.
        $superseded->update(['expires_at' => now()->subMinute()]);

        $this->artisan('secrets:purge-expired')->assertSuccessful();

        $this->assertNull(ProxySecret::query()->find($superseded->id));

        // The current secret is untouched.
        $this->assertNotNull(ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing)
            ->where('is_current', true)
            ->first());
    }
}
