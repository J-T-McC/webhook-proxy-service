<?php

namespace Tests\Unit\Actions;

use App\Actions\AdvanceProxyFifoQueue;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdvanceProxyFifoQueueTest extends TestCase
{
    /**
     * A FIFO proxy with one destination and `$count` pending fifo_dispatches rows,
     * whose webhook_event_ids ascend in receive order (evt-1, evt-2, ...).
     *
     * @return array{0: Proxy, 1: Collection<int, FifoDispatch>}
     */
    private function fifoProxyWithPending(int $count): array
    {
        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly();

        $dispatches = collect(range(1, $count))->map(function (int $i) use ($proxy): FifoDispatch {
            $event = WebhookEvent::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
                'ingest_id' => "evt-{$i}",
            ]);

            return FifoDispatch::factory()->create([
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
                'webhook_event_id' => $event->id,
            ]);
        });

        return [$proxy, $dispatches];
    }

    public function test_settles_pending_rows_one_at_a_time_in_webhook_event_id_order(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(3);

        foreach (['evt-1', 'evt-2', 'evt-3'] as $position => $expectedIngestId) {
            AdvanceProxyFifoQueue::run($proxy->id);

            // The expected row is settled and delivered before the next is touched.
            $settled = $dispatches[$position]->fresh();
            $this->assertSame(FifoDispatchStatus::Settled, $settled->status);
            $this->assertNotNull($settled->settled_at);
            $this->assertDatabaseHas('delivery_attempts', ['ingest_id' => $expectedIngestId]);

            // Later rows remain pending until their turn.
            for ($later = $position + 1; $later < 3; $later++) {
                $this->assertSame(FifoDispatchStatus::Pending, $dispatches[$later]->fresh()->status);
            }
        }

        // Deliveries happened in receive order.
        $this->assertSame(
            ['evt-1', 'evt-2', 'evt-3'],
            DeliveryAttempt::orderBy('id')->pluck('ingest_id')->all(),
        );

        // Each successful advance self-dispatches to advance the line.
        AdvanceProxyFifoQueue::assertPushed(3);
    }

    public function test_is_a_no_op_when_no_rows_are_pending(): void
    {
        Queue::fake();
        Http::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);

        AdvanceProxyFifoQueue::run($proxy->id);

        Http::assertNothingSent();
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_is_a_no_op_when_a_live_claim_already_exists(): void
    {
        Queue::fake();
        Http::fake();

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);

        // Simulate another advancer already holding a live claim on the first row.
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);

        AdvanceProxyFifoQueue::run($proxy->id);

        // Nothing else claimed, no delivery, no self-dispatch.
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        Http::assertNothingSent();
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_the_claim_commits_before_the_outbound_delivery_fires(): void
    {
        Queue::fake();

        [$proxy, $dispatches] = $this->fifoProxyWithPending(1);
        $dispatchId = $dispatches[0]->id;

        // At the moment of the outbound send, the claim transaction must be closed
        // (level 0) and the row already committed as 'claimed' — the row lock is
        // never held across the network call (ADR-005 (a)).
        Http::fake(function () use ($dispatchId) {
            $this->assertSame(0, DB::transactionLevel());
            $fresh = FifoDispatch::query()->findOrFail($dispatchId);
            $this->assertSame(FifoDispatchStatus::Claimed, $fresh->status);

            return Http::response('ok', 200);
        });

        AdvanceProxyFifoQueue::run($proxy->id);

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
    }
}
