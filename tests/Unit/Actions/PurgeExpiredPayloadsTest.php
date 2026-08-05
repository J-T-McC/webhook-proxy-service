<?php

namespace Tests\Unit\Actions;

use App\Actions\PurgeExpiredPayloads;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeExpiredPayloadsTest extends TestCase
{
    private function expiredEventFor(Proxy $proxy): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays(31),
        ]);
    }

    public function test_no_collectable_rows_is_a_noop(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        PurgeExpiredPayloads::run();

        $fresh = $event->fresh();
        $this->assertNull($fresh->payload_cleaned_at);
        $this->assertNotNull($fresh->body);
        $this->assertNotNull($fresh->headers);
    }

    public function test_a_single_expired_event_with_no_holds_is_erased_in_both_stores(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        $output = DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'body' => '{"diverged":true}',
        ]);

        PurgeExpiredPayloads::run();

        $rawEvent = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNull($rawEvent->body);
        $this->assertNull($rawEvent->headers);
        $this->assertNotNull($rawEvent->payload_cleaned_at);

        $rawOutput = DB::table('dispatched_payloads')->where('id', $output->id)->first();
        $this->assertNull($rawOutput->body);
    }

    public function test_an_unexpired_event_is_untouched_including_updated_at(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays(29),
        ]);
        $before = DB::table('webhook_events')->where('id', $event->id)->first();

        PurgeExpiredPayloads::run();

        $after = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertEquals($before, $after);
    }

    public function test_a_second_run_over_an_already_cleaned_event_is_a_noop(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);

        PurgeExpiredPayloads::run();
        $firstPass = DB::table('webhook_events')->where('id', $event->id)->first();

        PurgeExpiredPayloads::run();
        $secondPass = DB::table('webhook_events')->where('id', $event->id)->first();

        $this->assertEquals($firstPass, $secondPass);
    }

    public function test_a_soft_deleted_teams_expired_payload_is_still_cleaned(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $team = Team::query()->findOrFail($proxy->team_id);
        $event = $this->expiredEventFor($proxy);
        $team->delete();

        PurgeExpiredPayloads::run();

        $rawEvent = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNotNull($rawEvent->payload_cleaned_at);
        $this->assertNull($rawEvent->body);
    }

    public function test_the_purge_command_is_registered_and_scheduled_daily_without_overlapping(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => $e->description === 'Erase expired stored payloads',
        );

        $this->assertNotNull($event, 'Expected the payload purge to be scheduled.');
        $this->assertSame('0 2 * * *', $event->expression, 'The purge must run daily().');
        $this->assertTrue($event->withoutOverlapping);
    }
}
