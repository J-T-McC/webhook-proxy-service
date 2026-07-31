<?php

namespace Tests\Unit\Pipeline;

use App\Actions\DeliverStep;
use App\Enums\HttpMethod;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Pipeline\PipelineContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliverStepTest extends TestCase
{
    private function context(Proxy $proxy): PipelineContext
    {
        return new PipelineContext(
            ingestId: 'ingest-1',
            proxy: $proxy->fresh(),
            method: 'POST',
            headers: ['Content-Type' => ['application/json']],
            rawBody: '{"a":1}',
        );
    }

    public function test_it_delivers_to_each_live_destination_and_skips_trashed(): void
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
        $trashed = Destination::factory()->for($proxy)->create([
            'url' => 'https://trashed.example.test/hook',
        ]);
        $trashed->delete();

        $ctx = $this->context($proxy);
        $returned = DeliverStep::make()->handle($ctx, fn (PipelineContext $c) => $c);

        // Terminal step still closes the chain by returning $next($ctx).
        $this->assertSame($ctx, $returned);

        // One attempt per LIVE destination, none for the trashed one.
        $this->assertSame(2, DeliveryAttempt::count());
        $this->assertSame(1, DeliveryAttempt::where('destination_id', $a->id)->count());
        $this->assertSame(1, DeliveryAttempt::where('destination_id', $b->id)->count());
        $this->assertSame(0, DeliveryAttempt::where('destination_id', $trashed->id)->count());

        // Each destination's own method is used on the wire.
        Http::assertSent(fn ($request) => $request->url() === 'https://a.example.test/hook' && $request->method() === 'POST');
        Http::assertSent(fn ($request) => $request->url() === 'https://b.example.test/hook' && $request->method() === 'PUT');
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

        DeliverStep::make()->handle($this->context($proxy), fn (PipelineContext $c) => $c);

        // Both destinations still got an attempt; the healthy one still delivered.
        $this->assertSame(2, DeliveryAttempt::count());
        Http::assertSent(fn ($request) => $request->url() === 'https://ok.example.test/hook');
    }
}
