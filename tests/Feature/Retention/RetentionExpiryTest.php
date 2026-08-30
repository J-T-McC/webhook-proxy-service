<?php

namespace Tests\Feature\Retention;

use App\Actions\PurgeExpiredPayloads;
use App\Enums\ProxyMode;
use App\Models\DeliveryAttempt;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\WebhookEvent;
use App\Services\RetentionPolicy;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end proof of the retention-window requirements (AC1-AC4, AC7) over the
 * real `PurgeExpiredPayloads` pass and real `RetentionPolicy` — complementing
 * T11's unit-level happy path.
 */
class RetentionExpiryTest extends TestCase
{
    private function eventFor(Proxy $proxy, int $ageInDays): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays($ageInDays),
        ]);
    }

    private function isCleaned(WebhookEvent $event): bool
    {
        return DB::table('webhook_events')->where('id', $event->id)->value('payload_cleaned_at') !== null;
    }

    public function test_an_event_past_the_window_is_cleaned_one_within_it_is_untouched(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $expired = $this->eventFor($proxy, 31);
        $retained = $this->eventFor($proxy, 29);
        $before = DB::table('webhook_events')->where('id', $retained->id)->first();

        PurgeExpiredPayloads::run();

        $this->assertTrue($this->isCleaned($expired));
        $this->assertFalse($this->isCleaned($retained));
        $after = DB::table('webhook_events')->where('id', $retained->id)->first();
        $this->assertEquals($before, $after);
    }

    public function test_the_window_is_measured_from_capture_not_from_recent_delivery_activity(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->eventFor($proxy, 31);

        // A recent, terminal delivery attempt does not reset the retention clock —
        // the anchor is webhook_events.created_at, never last delivery activity.
        DeliveryAttempt::factory()->succeeded()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => $event->ingest_id,
            'started_at' => now(),
        ]);

        PurgeExpiredPayloads::run();

        $this->assertTrue($this->isCleaned($event));
    }

    public function test_two_teams_sharing_the_default_window_are_both_cleaned_on_the_same_run(): void
    {
        $proxyA = Proxy::factory()->createQuietly();
        $proxyB = Proxy::factory()->createQuietly();
        $eventA = $this->eventFor($proxyA, 31);
        $eventB = $this->eventFor($proxyB, 31);

        PurgeExpiredPayloads::run();

        $this->assertTrue($this->isCleaned($eventA));
        $this->assertTrue($this->isCleaned($eventB));
    }

    public function test_a_substituted_window_for_one_team_cleans_only_that_teams_payloads(): void
    {
        $proxyA = Proxy::factory()->createQuietly();
        $proxyB = Proxy::factory()->createQuietly();
        $eventA = $this->eventFor($proxyA, 31);
        $eventB = $this->eventFor($proxyB, 31);

        $unaffectedTeamId = $proxyB->team_id;
        $policy = new class extends RetentionPolicy
        {
            public int $wideWindowTeamId = 0;

            public function windowFor(Team $team): CarbonInterval
            {
                if ($team->id === $this->wideWindowTeamId) {
                    // Far wider than 31 days old -> team A's event is not yet expired.
                    return CarbonInterval::days(1000);
                }

                return parent::windowFor($team);
            }
        };
        $policy->wideWindowTeamId = $proxyA->team_id;
        $this->app->instance(RetentionPolicy::class, $policy);

        PurgeExpiredPayloads::run();

        $this->assertFalse($this->isCleaned($eventA), 'The substituted (wider) window must leave team A untouched.');
        $this->assertTrue($this->isCleaned($eventB), 'Team B, on the default window, must still be cleaned.');
        $this->assertSame($unaffectedTeamId, $proxyB->team_id);
    }

    public function test_simple_and_enhanced_mode_proxies_raw_payloads_are_both_cleaned(): void
    {
        $simple = Proxy::factory()->createQuietly(['mode' => ProxyMode::Simple]);
        $enhanced = Proxy::factory()->enhanced()->createQuietly();
        $simpleEvent = $this->eventFor($simple, 31);
        $enhancedEvent = $this->eventFor($enhanced, 31);

        PurgeExpiredPayloads::run();

        $this->assertTrue($this->isCleaned($simpleEvent));
        $this->assertTrue($this->isCleaned($enhancedEvent));
    }
}
