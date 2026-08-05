<?php

namespace Tests\Unit\Actions;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\SweepStalledFifoDispatches;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SweepStalledFifoDispatchesTest extends TestCase
{
    private function pendingDispatch(Proxy $proxy): FifoDispatch
    {
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        return FifoDispatch::factory()->create([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
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
