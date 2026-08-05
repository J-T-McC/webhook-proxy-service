<?php

namespace Tests\Feature\Retention;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\PurgeExpiredPayloads;
use App\Enums\AttemptStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end proof of each in-flight hold (H2-H4), of the compare-and-set
 * closing the select->act race, and of GC composing with a live FIFO line
 * without stalling or reordering it (AC8) — complementing T11's unit-level
 * happy path.
 */
class RetentionInFlightHoldsAcceptanceTest extends TestCase
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

    public function test_h2_fifo_hold_blocks_erasure_until_the_dispatch_row_settles(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        $dispatch = FifoDispatch::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'status' => FifoDispatchStatus::Pending,
        ]);

        PurgeExpiredPayloads::run();
        $this->assertFalse($this->isCleaned($event), 'A pending fifo_dispatches row must hold the event.');

        $dispatch->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);
        PurgeExpiredPayloads::run();
        $this->assertFalse($this->isCleaned($event), 'A claimed fifo_dispatches row must hold the event.');

        $dispatch->update(['status' => FifoDispatchStatus::Settled, 'settled_at' => now()]);
        PurgeExpiredPayloads::run();
        $this->assertTrue($this->isCleaned($event), 'Once settled the hold lifts and the event is cleaned.');
    }

    public function test_h3_async_hold_blocks_erasure_until_every_delivery_attempt_is_terminal(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        $attempt = DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => $event->ingest_id,
            // Default factory status is Dispatched (non-terminal).
        ]);

        PurgeExpiredPayloads::run();
        $this->assertFalse($this->isCleaned($event), 'A non-terminal (dispatched) delivery attempt must hold the event.');

        $attempt->update(['status' => AttemptStatus::Succeeded]);
        PurgeExpiredPayloads::run();
        $this->assertTrue($this->isCleaned($event), 'Once every attempt is terminal the hold lifts.');
    }

    public function test_h4_horizon_hold_blocks_erasure_until_past_the_dispatch_horizon_when_no_attempts_exist(): void
    {
        // Decouple the horizon from the 30-day retention window (default horizon of
        // 60 minutes is always far shorter than any past-cutoff event) so the two
        // events below can straddle the horizon while both are already past H1.
        Config::set('retention.dispatch_horizon_minutes', 35 * 24 * 60); // 35 days

        $proxy = Proxy::factory()->createQuietly();
        $withinHorizon = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays(32), // past the 30-day retention window, younger than the 35-day horizon
        ]);
        $pastHorizon = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays(36), // past both the retention window and the horizon
        ]);

        PurgeExpiredPayloads::run();

        $this->assertFalse($this->isCleaned($withinHorizon), 'Zero attempts and still within the horizon must hold the event.');
        $this->assertTrue($this->isCleaned($pastHorizon), 'Zero attempts but past the horizon must be cleaned.');
    }

    public function test_a_hold_that_reappears_between_selection_and_erase_causes_the_erase_to_affect_zero_rows(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        $before = DB::table('webhook_events')->where('id', $event->id)->first();

        $inserted = false;
        DB::listen(function ($query) use ($event, &$inserted): void {
            if ($inserted || ! str_contains($query->sql, 'select `id` from `webhook_events`')) {
                return;
            }

            $inserted = true;

            // Simulate a hold reappearing between selection and the erase UPDATE: a
            // fifo_dispatches row for this event flips (back) to `pending` right after
            // its id was selected but before eraseOne()'s compare-and-set UPDATE runs.
            DB::table('fifo_dispatches')->insert([
                'team_id' => $event->team_id,
                'proxy_id' => $event->proxy_id,
                'webhook_event_id' => $event->id,
                'status' => FifoDispatchStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        PurgeExpiredPayloads::run();

        $after = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNull($after->payload_cleaned_at, 'The reappeared hold must skip the event, never erase it.');
        $this->assertEquals($before, $after, 'The event must survive the run byte-for-byte.');
        $this->assertSame(
            FifoDispatchStatus::Pending->value,
            DB::table('fifo_dispatches')->where('webhook_event_id', $event->id)->value('status'),
        );
    }

    public function test_a_gc_pass_over_a_live_fifo_line_leaves_it_untouched_and_the_line_still_advances_in_order(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://fifo.test/hook']);

        $dispatches = collect(['evt-1', 'evt-2', 'evt-3'])->map(function (string $body) use ($proxy): FifoDispatch {
            $event = WebhookEvent::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
                'body' => $body,
                'byte_size' => strlen($body),
                'created_at' => now()->subDays(31), // expired, but held by H2 while unsettled
            ]);

            return FifoDispatch::factory()->createQuietly([
                'webhook_event_id' => $event->id,
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
            ]);
        });

        // Freeze the line mid-advance: the first row is live-claimed (in flight).
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);
        $claimBefore = $dispatches[0]->fresh();

        // A GC pass runs over this proxy's fully-expired events while the line is live.
        PurgeExpiredPayloads::run();

        // The claim, its lease, and the pending set are all untouched (H2 hold) —
        // and none of the three events were cleaned.
        $claimAfter = $dispatches[0]->fresh();
        $this->assertSame(FifoDispatchStatus::Claimed, $claimAfter->status);
        $this->assertEquals($claimBefore->lease_expires_at, $claimAfter->lease_expires_at);
        $this->assertEquals($claimBefore->claimed_at, $claimAfter->claimed_at);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[2]->fresh()->status);
        foreach ($dispatches as $dispatch) {
            $this->assertNull(
                DB::table('webhook_events')->where('id', $dispatch->webhook_event_id)->value('payload_cleaned_at'),
            );
        }

        // Settle the frozen claim (the in-flight delivery completing) and let the line
        // advance normally: the GC pass in between must not have disturbed order.
        $dispatches[0]->update(['status' => FifoDispatchStatus::Settled, 'settled_at' => now()]);
        AdvanceProxyFifoQueue::run($proxy->id);
        AdvanceProxyFifoQueue::run($proxy->id);

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[1]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[2]->fresh()->status);

        $sentBodies = collect(Http::recorded())->map(fn ($pair) => $pair[0]->body())->all();
        $this->assertSame(['evt-2', 'evt-3'], $sentBodies);
    }
}
