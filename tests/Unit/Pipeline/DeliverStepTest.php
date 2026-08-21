<?php

namespace Tests\Unit\Pipeline;

use App\Actions\DeliverStep;
use App\Enums\HttpMethod;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\PipelineContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliverStepTest extends TestCase
{
    private const INGEST_ID = 'ingest-1';

    private function context(Proxy $proxy): PipelineContext
    {
        return new PipelineContext(
            ingestId: self::INGEST_ID,
            proxy: $proxy->fresh(),
            method: 'POST',
            headers: ['Content-Type' => ['application/json']],
            rawBody: '{"a":1}',
        );
    }

    /**
     * Mirrors T8's `firstOrCreate` shape: one `deliveries` row per destination,
     * keyed to the same dispatch (`self::INGEST_ID`, the context's default
     * `dispatchUuid`), created ahead of the `DeliverStep` run.
     */
    private function deliveryFor(Proxy $proxy, Destination $destination): Delivery
    {
        $event = WebhookEvent::query()->where('proxy_id', $proxy->id)->first()
            ?? WebhookEvent::factory()->create(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        return Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'webhook_event_id' => $event->id,
            'destination_id' => $destination->id,
            'dispatch_uuid' => self::INGEST_ID,
        ]);
    }

    public function test_it_delivers_to_each_live_destination_and_skips_one_with_no_delivery_row(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->create();
        $a = Destination::factory()->for($proxy)->create([
            'http_method' => HttpMethod::Post,
            'url' => 'https://a.example.test/hook',
        ]);
        $b = Destination::factory()->for($proxy)->create([
            'http_method' => HttpMethod::Put,
            'url' => 'https://b.example.test/hook',
        ]);
        // No delivery row created for this one — DeliverStep never sees it.
        $noDeliveryRow = Destination::factory()->for($proxy)->create([
            'url' => 'https://no-row.example.test/hook',
        ]);

        $this->deliveryFor($proxy, $a);
        $this->deliveryFor($proxy, $b);

        $ctx = $this->context($proxy);
        $returned = DeliverStep::make()->handle($ctx, fn (PipelineContext $c) => $c);

        // Terminal step still closes the chain by returning $next($ctx).
        $this->assertSame($ctx, $returned);

        // One attempt per destination with a matching delivery row.
        $this->assertSame(2, DeliveryAttempt::count());
        $this->assertSame(1, DeliveryAttempt::where('destination_id', $a->id)->count());
        $this->assertSame(1, DeliveryAttempt::where('destination_id', $b->id)->count());
        $this->assertSame(0, DeliveryAttempt::where('destination_id', $noDeliveryRow->id)->count());

        // Each destination's own method is used on the wire.
        Http::assertSent(fn ($request) => $request->url() === 'https://a.example.test/hook' && $request->method() === 'POST');
        Http::assertSent(fn ($request) => $request->url() === 'https://b.example.test/hook' && $request->method() === 'PUT');
    }

    public function test_a_destination_trashed_after_its_delivery_row_was_created_still_receives_its_attempt(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->create();
        $live = Destination::factory()->for($proxy)->create(['url' => 'https://live.example.test/hook']);
        $trashed = Destination::factory()->for($proxy)->create(['url' => 'https://trashed.example.test/hook']);

        // Both delivery rows created while both destinations were still live (T8).
        $this->deliveryFor($proxy, $live);
        $this->deliveryFor($proxy, $trashed);

        // The destination is soft-deleted AFTER its delivery row was created.
        $trashed->delete();

        DeliverStep::make()->handle($this->context($proxy), fn (PipelineContext $c) => $c);

        // Ruling 2: a destination trashed after delivery-row creation still delivers.
        $this->assertSame(2, DeliveryAttempt::count());
        $this->assertSame(1, DeliveryAttempt::where('destination_id', $live->id)->count());
        $this->assertSame(1, DeliveryAttempt::where('destination_id', $trashed->id)->count());
    }

    public function test_one_failing_destination_does_not_prevent_the_others(): void
    {
        Event::fake();
        Http::fake([
            'fail.example.test/*' => fn () => throw new ConnectionException('down'),
            '*' => Http::response('ok', 200),
        ]);

        $proxy = Proxy::factory()->create();
        $failing = Destination::factory()->for($proxy)->create(['url' => 'https://fail.example.test/hook']);
        $ok = Destination::factory()->for($proxy)->create(['url' => 'https://ok.example.test/hook']);

        $this->deliveryFor($proxy, $failing);
        $this->deliveryFor($proxy, $ok);

        DeliverStep::make()->handle($this->context($proxy), fn (PipelineContext $c) => $c);

        // Both destinations still got an attempt; the healthy one still delivered.
        $this->assertSame(2, DeliveryAttempt::count());
        Http::assertSent(fn ($request) => $request->url() === 'https://ok.example.test/hook');
    }
}
