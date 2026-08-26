<?php

namespace Tests\Unit\Actions;

use App\Actions\PurgeExpiredPayloads;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
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

    private function isCleaned(WebhookEvent $event): bool
    {
        return DB::table('webhook_events')->where('id', $event->id)->value('payload_cleaned_at') !== null;
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

    // Review-05 finding 1 (Major) — plan §Validation's Config sanity invariant:
    // `retention.purge_batch` must be a positive integer and
    // `retention.dispatch_horizon_minutes` a non-negative integer, enforced at
    // command entry, before any team is touched.

    public function test_run_throws_when_purge_batch_is_zero(): void
    {
        Config::set('retention.purge_batch', 0);

        $this->expectException(RuntimeException::class);

        PurgeExpiredPayloads::run();
    }

    public function test_run_throws_when_purge_batch_is_negative(): void
    {
        Config::set('retention.purge_batch', -5);

        $this->expectException(RuntimeException::class);

        PurgeExpiredPayloads::run();
    }

    public function test_run_throws_when_purge_batch_env_value_is_blank(): void
    {
        // Reproduces review-05 finding 1(b): `RETENTION_PURGE_BATCH=` (blank)
        // casts to 0 at config resolution.
        putenv('RETENTION_PURGE_BATCH=');

        try {
            $resolved = require base_path('config/retention.php');
        } finally {
            putenv('RETENTION_PURGE_BATCH');
        }

        Config::set('retention.purge_batch', $resolved['purge_batch']);

        $this->expectException(RuntimeException::class);

        PurgeExpiredPayloads::run();
    }

    public function test_run_throws_when_purge_batch_env_value_is_non_numeric(): void
    {
        // Reproduces review-05 finding 1(b): a non-numeric
        // `RETENTION_PURGE_BATCH` also casts to 0 at config resolution.
        putenv('RETENTION_PURGE_BATCH=not-a-number');

        try {
            $resolved = require base_path('config/retention.php');
        } finally {
            putenv('RETENTION_PURGE_BATCH');
        }

        Config::set('retention.purge_batch', $resolved['purge_batch']);

        $this->expectException(RuntimeException::class);

        PurgeExpiredPayloads::run();
    }

    public function test_run_throws_when_dispatch_horizon_minutes_is_negative(): void
    {
        Config::set('retention.dispatch_horizon_minutes', -1);

        $this->expectException(RuntimeException::class);

        PurgeExpiredPayloads::run();
    }

    public function test_a_zero_dispatch_horizon_minutes_is_allowed(): void
    {
        // Zero is a valid (if degenerate) non-negative horizon and must not
        // be rejected — only negative values are.
        Config::set('retention.dispatch_horizon_minutes', 0);

        PurgeExpiredPayloads::run();

        $this->assertTrue(true, 'PurgeExpiredPayloads::run() must not throw for a zero horizon.');
    }

    // T19 (AC18; ADR-015 Decision 7) — GC hold H5: an event with a `retrying`
    // delivery, or a `pending` delivery still within the dispatch horizon, is
    // held from erasure; terminal deliveries hold nothing.

    public function test_h5_a_retrying_delivery_holds_the_event(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Retrying,
        ]);

        PurgeExpiredPayloads::run();

        $this->assertFalse($this->isCleaned($event), 'A retrying delivery must hold the event regardless of age.');
    }

    public function test_h5_a_young_pending_delivery_holds_the_event(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Pending,
            'created_at' => now(),
        ]);

        PurgeExpiredPayloads::run();

        $this->assertFalse($this->isCleaned($event), 'A pending delivery younger than the dispatch horizon must hold the event.');
    }

    public function test_h5_an_old_pending_delivery_does_not_hold_the_event(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Pending,
            'created_at' => now()->subMinutes(config('retention.dispatch_horizon_minutes') + 30),
        ]);

        PurgeExpiredPayloads::run();

        $this->assertTrue($this->isCleaned($event), 'A pending delivery older than the dispatch horizon must not hold the event.');
    }

    public function test_h5_terminal_deliveries_hold_nothing(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Succeeded,
        ]);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Failed,
        ]);

        PurgeExpiredPayloads::run();

        $this->assertTrue($this->isCleaned($event), 'Terminal (succeeded/failed) deliveries must hold nothing, including a failed one.');
    }

    public function test_h5_a_hold_that_reappears_between_selection_and_erase_causes_the_erase_to_affect_zero_rows(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $before = DB::table('webhook_events')->where('id', $event->id)->first();

        $inserted = false;
        DB::listen(function ($query) use ($event, $destination, &$inserted): void {
            if ($inserted || ! str_contains($query->sql, 'select `id` from `webhook_events`')) {
                return;
            }

            $inserted = true;

            // Simulate a hold reappearing between selection and the erase UPDATE: a
            // deliveries row for this event flips (back) to `retrying` right after its
            // id was selected but before eraseOne()'s compare-and-set UPDATE runs.
            DB::table('deliveries')->insert([
                'team_id' => $event->team_id,
                'proxy_id' => $event->proxy_id,
                'destination_id' => $destination->id,
                'webhook_event_id' => $event->id,
                'dispatch_uuid' => (string) Str::uuid(),
                'kind' => DispatchKind::Original->value,
                'status' => DeliveryStatus::Retrying->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        PurgeExpiredPayloads::run();

        $after = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNull($after->payload_cleaned_at, 'The reappeared hold must skip the event, never erase it.');
        $this->assertEquals($before, $after, 'The event must survive the run byte-for-byte.');
    }

    public function test_an_invalid_purge_batch_never_reaches_the_batch_terminator(): void
    {
        // Proves review-05 finding 1(b)'s infinite loop cannot occur: seed
        // real, collectable data so the do/while loop at purgeForTeam() would
        // spin forever on a `LIMIT 0` selection if the guard were absent, then
        // assert the guard rejects the config before any team's
        // `webhook_events` selection query is ever issued — the loop body is
        // unreachable, not merely safe once entered.
        $proxy = Proxy::factory()->createQuietly();
        $this->expiredEventFor($proxy);

        Config::set('retention.purge_batch', 0);

        $webhookEventSelects = 0;
        DB::listen(function ($query) use (&$webhookEventSelects): void {
            if (str_contains($query->sql, 'webhook_events')) {
                $webhookEventSelects++;
            }
        });

        $thrown = null;

        try {
            PurgeExpiredPayloads::run();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected a RuntimeException before the batch terminator could be reached.');
        $this->assertSame(0, $webhookEventSelects, 'No webhook_events query may execute with an invalid batch size.');
    }
}
