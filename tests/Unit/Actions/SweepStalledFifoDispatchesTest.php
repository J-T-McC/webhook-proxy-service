<?php

namespace Tests\Unit\Actions;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\SweepStalledFifoDispatches;
use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SweepStalledFifoDispatchesTest extends TestCase
{
    private function pendingDispatch(Proxy $proxy): FifoDispatch
    {
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        return FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
        ]);
    }

    /**
     * A held (`awaiting_retry`) `fifo_dispatches` row for `$proxy`, identifying
     * the dispatch `$dispatchUuid`.
     */
    private function heldDispatch(Proxy $proxy, string $dispatchUuid): FifoDispatch
    {
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        return FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $dispatchUuid,
            'status' => FifoDispatchStatus::AwaitingRetry,
        ]);
    }

    private function deliveryFor(Proxy $proxy, string $dispatchUuid, DeliveryStatus $status): Delivery
    {
        $destination = Destination::factory()->for($proxy)->createQuietly();

        return Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'dispatch_uuid' => $dispatchUuid,
            'status' => $status,
            'next_attempt_at' => $status === DeliveryStatus::Retrying ? now()->subMinute() : null,
        ]);
    }

    public function test_reaps_a_claimed_row_whose_lease_has_expired(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $orphan = $this->pendingDispatch($proxy);
        $orphan->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);

        SweepStalledFifoDispatches::run();

        $reaped = $orphan->fresh();
        $this->assertSame(FifoDispatchStatus::Pending, $reaped->status);
        $this->assertNull($reaped->claimed_at);
        $this->assertNull($reaped->lease_expires_at);
    }

    public function test_leaves_a_claimed_row_with_an_unexpired_lease_untouched(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $live = $this->pendingDispatch($proxy);
        $live->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);

        SweepStalledFifoDispatches::run();

        $this->assertSame(FifoDispatchStatus::Claimed, $live->fresh()->status);
        // The live claim blocks a nudge for its proxy.
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_dispatches_one_advancer_per_idle_proxy_with_pending_rows(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        // Two pending rows on the same proxy -> still exactly one dispatch.
        $this->pendingDispatch($proxy);
        $this->pendingDispatch($proxy);

        SweepStalledFifoDispatches::run();

        AdvanceProxyFifoQueue::assertPushed(1);
        AdvanceProxyFifoQueue::assertPushed(fn (AdvanceProxyFifoQueue $job, array $params) => $params[0] === $proxy->id);
    }

    public function test_does_not_dispatch_for_a_proxy_with_a_live_claim(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        // One live claim + one pending on the same proxy: the live claim suppresses the nudge.
        $claim = $this->pendingDispatch($proxy);
        $claim->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);
        $this->pendingDispatch($proxy);

        SweepStalledFifoDispatches::run();

        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_excludes_a_proxy_with_a_live_awaiting_retry_row_from_the_idle_nudge(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);

        // The head is held (no lease) — a pending row sits behind it.
        $dispatchUuid = (string) Str::uuid();
        $held = $this->heldDispatch($proxy, $dispatchUuid);
        $this->deliveryFor($proxy, $dispatchUuid, DeliveryStatus::Retrying);
        $this->pendingDispatch($proxy);

        SweepStalledFifoDispatches::run();

        // No nudge: the held row makes this proxy busy, not idle — even though it
        // also has a genuinely pending row (T18, ADR-016 Decision 4(b)).
        AdvanceProxyFifoQueue::assertNotPushed();
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $held->fresh()->status);
    }

    public function test_excludes_a_paused_proxy_with_pending_rows_from_the_idle_nudge(): void
    {
        // Item #15, Q-15-01(1): a paused proxy sits in exactly the "pending, no
        // claim, no hold" shape the nudge exists to unstick — without this
        // exclusion the pause would silently lift within one tick.
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            'paused_at' => now(),
        ]);
        $this->pendingDispatch($proxy);

        SweepStalledFifoDispatches::run();

        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_releases_a_stuck_hold_whose_dispatch_has_gone_fully_terminal(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);

        // Simulated crash: the last delivery settled (both terminal) but the fifo
        // row's own transition never happened — the one crash window T18 closes.
        $dispatchUuid = (string) Str::uuid();
        $held = $this->heldDispatch($proxy, $dispatchUuid);
        $this->deliveryFor($proxy, $dispatchUuid, DeliveryStatus::Succeeded);
        $this->deliveryFor($proxy, $dispatchUuid, DeliveryStatus::Failed);

        SweepStalledFifoDispatches::run();

        $fresh = $held->fresh();
        $this->assertSame(FifoDispatchStatus::Settled, $fresh->status);
        $this->assertNotNull($fresh->settled_at);
        AdvanceProxyFifoQueue::assertPushed(fn (AdvanceProxyFifoQueue $job, array $params) => $params[0] === $proxy->id);
    }

    public function test_leaves_an_awaiting_retry_row_with_a_non_terminal_delivery_untouched_by_both_passes(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);

        $dispatchUuid = (string) Str::uuid();
        $held = $this->heldDispatch($proxy, $dispatchUuid);
        $this->deliveryFor($proxy, $dispatchUuid, DeliveryStatus::Retrying);

        SweepStalledFifoDispatches::run();

        // Pass (a) never touches it (no lease to reap); pass (c) never touches it
        // either (a non-terminal delivery remains) — still held, untouched.
        $fresh = $held->fresh();
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $fresh->status);
        $this->assertNull($fresh->claimed_at);
        $this->assertNull($fresh->lease_expires_at);
        $this->assertNull($fresh->settled_at);
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    /**
     * Regression: pass (a), the orphaned-claim reaper, is unaffected by the (b)/(c)
     * additions — proven alongside a held row so both live in the same sweep pass.
     */
    public function test_the_orphaned_claim_reaper_is_unaffected_by_the_new_passes(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $orphan = $this->pendingDispatch($proxy);
        $orphan->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);

        // A held row on a DIFFERENT proxy, unrelated to the reaper pass.
        $otherProxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $dispatchUuid = (string) Str::uuid();
        $this->heldDispatch($otherProxy, $dispatchUuid);
        $this->deliveryFor($otherProxy, $dispatchUuid, DeliveryStatus::Retrying);

        SweepStalledFifoDispatches::run();

        $reaped = $orphan->fresh();
        $this->assertSame(FifoDispatchStatus::Pending, $reaped->status);
        $this->assertNull($reaped->claimed_at);
        $this->assertNull($reaped->lease_expires_at);
        AdvanceProxyFifoQueue::assertPushed(fn (AdvanceProxyFifoQueue $job, array $params) => $params[0] === $proxy->id);
        AdvanceProxyFifoQueue::assertNotPushed(fn (AdvanceProxyFifoQueue $job, array $params) => $params[0] === $otherProxy->id);
    }

    public function test_the_sweep_is_registered_to_run_every_minute(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => $e->description === 'Sweep stalled FIFO dispatches',
        );

        $this->assertNotNull($event, 'Expected the FIFO sweep to be scheduled.');
        $this->assertSame('* * * * *', $event->expression, 'The sweep must run everyMinute().');
    }
}
