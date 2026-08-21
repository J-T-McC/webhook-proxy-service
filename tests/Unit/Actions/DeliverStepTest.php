<?php

namespace Tests\Unit\Actions;

use App\Actions\DeliverStep;
use App\Actions\DeliverToDestination;
use App\Enums\ProcessingMode;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\DeliveryUnit;
use App\Pipeline\PipelineContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
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

    public function test_builds_exactly_n_units_each_carrying_the_matching_delivery_rows_id(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();
        $deliveries = $this->deliveriesFor($proxy, $destinations);

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        $expectedDeliveryIds = $deliveries->pluck('id')->sort()->values()->all();
        $pushedDeliveryIds = [];

        DeliverToDestination::assertPushed(function ($job, array $params) use (&$pushedDeliveryIds) {
            /** @var DeliveryUnit $unit */
            $unit = $params[0];
            $pushedDeliveryIds[] = $unit->deliveryId;

            return true;
        });

        sort($pushedDeliveryIds);
        $this->assertSame($expectedDeliveryIds, $pushedDeliveryIds);
    }

    public function test_fifo_proxy_runs_each_delivery_inline_without_queueing(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destinations = Destination::factory()->for($proxy)->count(2)->createQuietly();
        $this->deliveriesFor($proxy, $destinations);

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
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();
        $this->deliveriesFor($proxy, $destinations);

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
        $boom = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://boom.test/hook']);
        $rest = Destination::factory()->for($proxy)->count(2)->createQuietly();
        $this->deliveriesFor($proxy, $rest->push($boom));

        DeliverStep::make()->handle($this->contextFor($proxy), fn (PipelineContext $c) => $c);

        // All three destinations produced an attempt row — the loop was not aborted.
        $this->assertSame(3, DeliveryAttempt::count());
    }
}
