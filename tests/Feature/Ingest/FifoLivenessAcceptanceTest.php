<?php

namespace Tests\Feature\Ingest;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\SweepStalledFifoDispatches;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end proof of the FIFO claim's correctness under contention and the
 * sweeper's liveness net (T16, ADR-005 (a)/(b)), complementing the
 * AdvanceProxyFifoQueue / SweepStalledFifoDispatches unit tests.
 */
class FifoLivenessAcceptanceTest extends TestCase
{
    /**
     * A FIFO proxy with one destination and `$count` pending rows ordered evt-1..N.
     *
     * @return array{0: Proxy, 1: Collection<int, FifoDispatch>}
     */
    private function fifoProxyWithPending(int $count): array
    {
        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://fifo.test/hook']);

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

    public function test_a_second_advancer_respects_an_existing_live_claim(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);

        // Advancer #1 already holds a live claim on the first row (in flight).
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);

        // Advancer #2 runs concurrently: it must NOT claim the second row.
        AdvanceProxyFifoQueue::run($proxy->id);

        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        $this->assertSame(1, FifoDispatch::where('proxy_id', $proxy->id)->where('status', FifoDispatchStatus::Claimed)->count());
        Http::assertNothingSent();
    }

    public function test_no_two_rows_are_ever_claimed_simultaneously_under_contention(): void
    {
        Queue::fake();

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);

        // At the moment advancer #1 is delivering the first event (its row is claimed
        // and in flight, OUTSIDE the claim transaction), a concurrent advancer #2
        // fires for the same proxy. The atomic claim must stop #2 claiming row 2.
        Http::fake(function () use ($proxy, $dispatches) {
            $claimedNow = FifoDispatch::where('proxy_id', $proxy->id)
                ->where('status', FifoDispatchStatus::Claimed)->count();
            $this->assertSame(1, $claimedNow, 'Exactly one row may be claimed while an event is in flight.');

            // Concurrent advancer #2 — must early-return on the live claim.
            AdvanceProxyFifoQueue::run($proxy->id);

            $this->assertSame(
                FifoDispatchStatus::Pending,
                $dispatches[1]->fresh()->status,
                'A concurrent advancer must not claim the next row while one is in flight.',
            );

            return Http::response('ok', 200);
        });

        AdvanceProxyFifoQueue::run($proxy->id);

        // Advancer #1 settled the first event; the second remains pending for the next run.
        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
    }

    public function test_two_runs_on_a_single_pending_row_deliver_it_exactly_once(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(1);

        AdvanceProxyFifoQueue::run($proxy->id);
        AdvanceProxyFifoQueue::run($proxy->id);

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSentCount(1);
    }

    public function test_an_orphaned_claim_is_reaped_and_the_line_advances(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(1);

        // A worker crashed mid-event: the row is claimed with an EXPIRED lease.
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);

        // The sweeper reaps the orphaned claim and nudges the idle proxy.
        SweepStalledFifoDispatches::run();

        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[0]->fresh()->status);
        AdvanceProxyFifoQueue::assertPushed(fn ($job, array $params) => $params[0] === $proxy->id);

        // The nudged advancer settles the reaped row (line advances again).
        AdvanceProxyFifoQueue::run($proxy->id);

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
    }
}
