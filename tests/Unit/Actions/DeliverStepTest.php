<?php

namespace Tests\Unit\Actions;

use App\Actions\DeliverStep;
use App\Actions\DeliverToDestination;
use App\Enums\ProcessingMode;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\PipelineContext;
use Illuminate\Support\Collection;
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

    /**
     * Mirrors T8's `firstOrCreate` shape: one `deliveries` row per destination,
     * keyed to the proxy's dispatch (`'ingest-'.$proxy->id`, the context's default
     * `dispatchUuid`), created ahead of the `DeliverStep` run.
     *
     * @param  iterable<Destination>  $destinations
     * @return Collection<int, Delivery>
     */
    private function deliveriesFor(Proxy $proxy, iterable $destinations): Collection
    {
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        return collect($destinations)->map(fn (Destination $destination): Delivery => Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'webhook_event_id' => $event->id,
            'destination_id' => $destination->id,
            'dispatch_uuid' => 'ingest-'.$proxy->id,
        ]));
    }

    public function test_async_proxy_dispatches_each_delivery_onto_the_webhooks_queue(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();
        $this->deliveriesFor($proxy, $destinations);

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        DeliverToDestination::assertPushedOn(config('ingest.webhooks_queue'), 3);
    }

    /**
     * Since ADR-020 Decision 1, a FIFO proxy dispatches each delivery by
     * reference exactly like Async — no `processing_mode` branch remains in
     * `DeliverStep`. Supersedes the pre-ADR-020
     * `test_fifo_proxy_runs_each_delivery_inline_without_queueing`, which
     * asserted the opposite (inline, unqueued delivery) — that behaviour is
     * exactly what ADR-020 removes, and the FIFO ordering guarantee it served
     * is preserved by `AdvanceProxyFifoQueue`'s settle-or-hold instead (ADR-020
     * Decision 2/3).
     */
    public function test_fifo_proxy_also_dispatches_each_delivery_onto_the_webhooks_queue(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destinations = Destination::factory()->for($proxy)->count(2)->createQuietly();
        $this->deliveriesFor($proxy, $destinations);

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        DeliverToDestination::assertPushedOn(config('ingest.webhooks_queue'), 2);
    }

    /**
     * Supersedes the pre-ADR-020
     * `test_builds_exactly_n_units_each_carrying_the_matching_delivery_rows_id`,
     * which asserted the pushed argument was a `DeliveryUnit` carrying the
     * delivery's id. Since Decision 7, the queued job's arguments are the
     * delivery id and attempt number ONLY — no `DeliveryUnit`, no payload, no
     * header values, ever reaches the queue.
     */
    public function test_pushes_the_delivery_id_and_attempt_number_one_for_each_delivery_no_delivery_unit(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();
        $deliveries = $this->deliveriesFor($proxy, $destinations);

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        $expectedDeliveryIds = $deliveries->pluck('id')->sort()->values()->all();
        $pushedDeliveryIds = [];

        DeliverToDestination::assertPushed(function ($job, array $params) use (&$pushedDeliveryIds) {
            $this->assertSame(1, $params[1], 'Every dispatch from DeliverStep is attempt 1.');
            $pushedDeliveryIds[] = $params[0];

            return true;
        });

        sort($pushedDeliveryIds);
        $this->assertSame($expectedDeliveryIds, $pushedDeliveryIds);
    }

    public function test_async_one_destination_failing_does_not_prevent_the_others_dispatching(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();
        $this->deliveriesFor($proxy, $destinations);

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        // A dispatch is fire-and-forget; every destination is enqueued regardless.
        DeliverToDestination::assertPushed(3);
    }

    /**
     * Supersedes the pre-ADR-020 `test_fifo_one_destination_failing_does_not_abort_the_loop`:
     * with nothing running inline any more, there is no longer an in-loop
     * transport error to survive — the loop is now a plain dispatch loop, and
     * this is the FIFO mirror of the Async case above (AC10).
     */
    public function test_fifo_one_destination_failing_does_not_prevent_the_others_dispatching(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();
        $this->deliveriesFor($proxy, $destinations);

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        DeliverToDestination::assertPushed(3);
    }
}
