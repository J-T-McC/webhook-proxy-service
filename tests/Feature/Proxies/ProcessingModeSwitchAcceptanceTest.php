<?php

namespace Tests\Feature\Proxies;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\DeliverToDestination;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lorisleiva\Actions\ActionManager;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Tests\TestCase;

/**
 * End-to-end proof of the plan's mid-flight mode-change ruling (T18): switching
 * `processing_mode` is a routine config change with no draining/cancellation, and no
 * accepted event is lost, duplicated, or reordered among its own-mode peers. The
 * switch is applied at the model level here; endpoint-level persistence/validation is
 * covered by T19/T20.
 */
class ProcessingModeSwitchAcceptanceTest extends TestCase
{
    private function ingestRaw(Proxy $proxy, string $rawBody): TestResponse
    {
        return $this->call(
            'POST',
            'https://localhost/ingest/'.$proxy->ingest_token,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody,
        );
    }

    /**
     * Runs every currently-faked, queued `DeliverToDestination` job in place —
     * standing in for a real queue worker (T16/T17, ADR-016 Decision 1). Idempotent
     * against re-invocation: an already-settled attempt is a resume no-op.
     */
    private function runPushedDeliveries(): void
    {
        Queue::pushed(ActionManager::$jobDecorator, function (JobDecorator $job) {
            if ($job->decorates(DeliverToDestination::class)) {
                DeliverToDestination::run(...$job->getParameters());
            }

            return true;
        });
    }

    public function test_switching_processing_mode_persists_in_both_directions(): void
    {
        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);

        $proxy->update(['processing_mode' => ProcessingMode::Fifo]);
        $this->assertSame(ProcessingMode::Fifo, $proxy->fresh()->processing_mode);

        $proxy->update(['processing_mode' => ProcessingMode::Async]);
        $this->assertSame(ProcessingMode::Async, $proxy->fresh()->processing_mode);
    }

    public function test_pre_switch_fifo_events_still_drain_in_order_after_switching_to_async(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://fifo.test/hook']);

        // Two events ingested while FIFO; the advancer dispatch is faked, so the two
        // ordering rows sit pending (enqueued but not yet drained).
        $this->ingestRaw($proxy, 'evt-1')->assertStatus(202);
        $this->ingestRaw($proxy, 'evt-2')->assertStatus(202);
        $this->assertSame(2, FifoDispatch::where('status', FifoDispatchStatus::Pending)->count());

        // Routine mid-flight switch to async — no draining, no cancellation.
        $proxy->update(['processing_mode' => ProcessingMode::Async]);

        // The pre-switch rows still drain via the advancer, one at a time, in order.
        // Delivery now follows the new (async) mode — dispatched, not inline — so
        // each claimed row holds (`awaiting_retry`, no lease) until its queued
        // delivery actually settles (ADR-016 Decision 1); the busy-gate then keeps
        // the next row from being claimed until that happens (ADR-016 Decision 1's
        // widened busy check), exactly as it would under a real queue worker.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->runPushedDeliveries();

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->runPushedDeliveries();

        $rows = FifoDispatch::where('proxy_id', $proxy->id)->orderBy('webhook_event_id')->get();
        $this->assertTrue($rows->every(fn (FifoDispatch $r) => $r->status === FifoDispatchStatus::Settled));
        $this->assertTrue(
            $rows[0]->settled_at <= $rows[1]->settled_at,
            'Pre-switch FIFO rows must settle in receive order.',
        );

        // Their delivery followed the new (async) mode — dispatched, not inline.
        DeliverToDestination::assertPushed(2);
    }

    public function test_events_ingested_after_switching_to_async_follow_async(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly();

        $proxy->update(['processing_mode' => ProcessingMode::Async]);

        $this->ingestRaw($proxy, 'post-switch')->assertStatus(202);

        // No new ordering row; the async pipeline job is dispatched instead.
        $this->assertSame(0, FifoDispatch::count());
        ProcessIngestedWebhook::assertPushed(1);
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_events_ingested_after_switching_to_fifo_start_a_fresh_ordered_line(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        Destination::factory()->for($proxy)->createQuietly();

        $proxy->update(['processing_mode' => ProcessingMode::Fifo]);

        $this->ingestRaw($proxy, 'post-switch')->assertStatus(202);

        // A fresh FIFO line: one pending ordering row + an advancer dispatch.
        $this->assertSame(1, FifoDispatch::where('status', FifoDispatchStatus::Pending)->count());
        AdvanceProxyFifoQueue::assertPushed(1);
        ProcessIngestedWebhook::assertNotPushed();
    }
}
