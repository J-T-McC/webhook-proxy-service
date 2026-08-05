<?php

namespace Tests\Feature\Ingest;

use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end proof that a FIFO proxy settles events in receive order and never
 * blocks or is blocked by another proxy (T15, AC6/AC7), complementing the
 * AdvanceProxyFifoQueue unit-level advancer test.
 */
class FifoOrderingAcceptanceTest extends TestCase
{
    private function ingestRaw(Proxy $proxy, string $rawBody): TestResponse
    {
        // Raw body so the forwarded request body equals what we sent — lets us
        // assert delivery ORDER by the recorded request bodies.
        return $this->call(
            'POST',
            'https://localhost/ingest/'.$proxy->ingest_token,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody,
        );
    }

    public function test_events_are_delivered_in_the_order_they_were_received(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://fifo.test/hook']);

        // Ingested in sequence; each request drains its own advance inline (sync).
        foreach (['evt-1', 'evt-2', 'evt-3'] as $body) {
            $this->ingestRaw($proxy, $body)->assertStatus(202);
        }

        // All three settled, and delivered in receive order.
        $this->assertSame(3, DeliveryAttempt::count());
        $this->assertSame(3, FifoDispatch::where('status', FifoDispatchStatus::Settled)->count());

        $sentBodies = collect(Http::recorded())->map(fn ($pair) => $pair[0]->body())->all();
        $this->assertSame(['evt-1', 'evt-2', 'evt-3'], $sentBodies);
    }

    public function test_a_busy_fifo_line_does_not_block_another_proxys_delivery(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        // Proxy A's line is already "in flight": a live (unexpired) claim blocks it.
        $proxyA = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxyA)->createQuietly(['url' => 'https://a.test/hook']);
        $this->ingestRaw($proxyA, 'a-1');
        // Freeze A mid-flight: reset its settled row to a live claim so A cannot advance.
        FifoDispatch::query()->where('proxy_id', $proxyA->id)->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);
        Http::fake(['*' => Http::response('ok', 200)]); // reset recorder

        // Proxy B (independent FIFO line) ingests and must deliver immediately.
        $proxyB = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxyB)->createQuietly(['url' => 'https://b.test/hook']);

        $this->ingestRaw($proxyB, 'b-1')->assertStatus(202);

        // B delivered despite A's line being blocked (AC7 — per-proxy isolation).
        Http::assertSent(fn ($r) => $r->url() === 'https://b.test/hook' && $r->body() === 'b-1');
        $this->assertSame(
            FifoDispatchStatus::Settled,
            FifoDispatch::where('proxy_id', $proxyB->id)->firstOrFail()->status,
        );

        // A's frozen claim is untouched — B's advance never crossed into A's line.
        $this->assertSame(
            FifoDispatchStatus::Claimed,
            FifoDispatch::where('proxy_id', $proxyA->id)->firstOrFail()->status,
        );
    }

    public function test_two_fifo_proxies_each_deliver_their_own_events_in_order(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxyA = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxyA)->createQuietly(['url' => 'https://a.test/hook']);
        $proxyB = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxyB)->createQuietly(['url' => 'https://b.test/hook']);

        // Interleaved ingests across the two proxies.
        $this->ingestRaw($proxyA, 'a-1')->assertStatus(202);
        $this->ingestRaw($proxyB, 'b-1')->assertStatus(202);
        $this->ingestRaw($proxyA, 'a-2')->assertStatus(202);
        $this->ingestRaw($proxyB, 'b-2')->assertStatus(202);

        $aBodies = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === 'https://a.test/hook')
            ->map(fn ($pair) => $pair[0]->body())->values()->all();
        $bBodies = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->url() === 'https://b.test/hook')
            ->map(fn ($pair) => $pair[0]->body())->values()->all();

        $this->assertSame(['a-1', 'a-2'], $aBodies);
        $this->assertSame(['b-1', 'b-2'], $bBodies);
    }
}
