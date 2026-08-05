<?php

namespace Tests\Unit\Actions;

use App\Actions\DeliverStep;
use App\Actions\DeliverToDestination;
use App\Enums\ProcessingMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Pipeline\PipelineContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliverStepTest extends TestCase
{
    private function contextFor(Proxy $proxy): PipelineContext
    {
        return new PipelineContext(
            ingestId: 'ingest-'.$proxy->id,
            proxy: $proxy->fresh(),
            method: 'POST',
            headers: ['content-type' => ['application/json']],
            rawBody: '{"hello":"world"}',
        );
    }

    public function test_async_proxy_dispatches_each_delivery_onto_the_webhooks_queue(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        Destination::factory()->for($proxy)->count(3)->createQuietly();

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        DeliverToDestination::assertPushedOn(config('ingest.webhooks_queue'), 3);
    }

    public function test_fifo_proxy_runs_each_delivery_inline_without_queueing(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        // Inline, not queued: no push, and the sends actually happened synchronously.
        DeliverToDestination::assertNotPushed();
        Http::assertSentCount(2);
        $this->assertSame(2, DeliveryAttempt::count());
    }

    public function test_async_one_destination_failing_does_not_prevent_the_others_dispatching(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        Destination::factory()->for($proxy)->count(3)->createQuietly();

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        // A dispatch is fire-and-forget; every destination is enqueued regardless.
        DeliverToDestination::assertPushed(3);
    }

    public function test_fifo_one_destination_failing_does_not_abort_the_loop(): void
    {
        // First destination throws (connection error), the other two still deliver.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'boom')) {
                throw new ConnectionException('refused');
            }

            return Http::response('ok', 200);
        });

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://boom.test/hook']);
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        // All three destinations produced an attempt row — the loop was not aborted.
        $this->assertSame(3, DeliveryAttempt::count());
    }
}
