<?php

namespace Tests\Feature\Ingest;

use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Services\WebhookEventCapture;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end proof of the always-on raw-capture contract over the wired ingest path
 * (AC5–AC9, ADR-003/ADR-010). Complements T11's wiring tests with the AC-level
 * assertions from plan §Test strategy. No new production code — gaps are fixed in
 * the owning task (T9/T10/T11).
 */
class IngestEventCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    /**
     * @return array{0: Proxy, 1: string}
     */
    private function proxyWithToken(array $attributes = []): array
    {
        $proxy = Proxy::factory()->createQuietly($attributes);
        Destination::factory()->for($proxy)->createQuietly();

        return [$proxy, $proxy->ingest_token];
    }

    private function ingestUrl(string $token): string
    {
        return 'https://localhost/ingest/'.$token;
    }

    /**
     * @param  array<string, string>  $server
     */
    private function ingest(string $token, string $raw, array $server = [], string $method = 'POST'): TestResponse
    {
        return $this->call($method, $this->ingestUrl($token), [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
        ], $server), $raw);
    }

    public function test_simple_mode_captures_one_row_with_full_fidelity_sharing_the_ingest_id(): void
    {
        [$proxy, $token] = $this->proxyWithToken();
        $raw = '{"id":"evt_123","type":"charge.succeeded"}';

        $this->ingest($token, $raw, ['HTTP_X_CUSTOM' => 'sig-value'])
            ->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
        $event = WebhookEvent::firstOrFail();

        $this->assertSame($proxy->id, $event->proxy_id);
        $this->assertSame($proxy->team_id, $event->team_id);
        $this->assertSame('POST', $event->method);
        $this->assertSame($raw, $event->body);
        $this->assertSame(strlen($raw), $event->byte_size);
        $this->assertSame('application/json', $event->content_type);
        // Faithful header capture (for #6 replay): content-type + the custom header.
        $this->assertSame(['application/json'], $event->headers['content-type'] ?? null);
        $this->assertSame(['sig-value'], $event->headers['x-custom'] ?? null);

        // One received event, one capture row, one shared correlator with the fan-out.
        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame($event->ingest_id, $attempt->ingest_id);
    }

    public function test_enhanced_mode_also_captures_the_raw_event(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();
        $raw = '{"mode":"enhanced"}';

        $this->ingest($proxy->ingest_token, $raw)->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
        $event = WebhookEvent::firstOrFail();
        $this->assertSame($raw, $event->body);
        $this->assertSame($proxy->id, $event->proxy_id);
    }

    public function test_capture_failure_returns_500_not_the_configured_2xx_and_persists_nothing(): void
    {
        // A configured 2xx must NOT be returned when capture fails — success is never
        // acknowledged for an uncaptured event (AC6).
        [, $token] = $this->proxyWithToken([
            'response_status' => 201,
            'response_body' => 'ACK',
        ]);

        $this->mock(
            WebhookEventCapture::class,
            fn (MockInterface $m) => $m->shouldReceive('capture')
                ->andThrow(new \RuntimeException('capture store unavailable')),
        );

        $response = $this->ingest($token, '{"x":1}');

        $response->assertStatus(500);
        $this->assertNotSame(201, $response->getStatusCode());
        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(0, DeliveryAttempt::count());
        Http::assertNothingSent();
    }

    public function test_no_parallel_path_delivery_attempts_stay_payload_free(): void
    {
        [, $token] = $this->proxyWithToken();

        $this->ingest($token, '{"id":"evt_1"}')->assertStatus(202);

        // The payload lives only on webhook_events; delivery_attempts is payload-free
        // (ADR-003) and joined to capture solely by the shared ingest_id (AC9).
        $columns = array_map('strtolower', Schema::getColumnListing('delivery_attempts'));
        foreach (['body', 'payload', 'request_body', 'response_body'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $event = WebhookEvent::firstOrFail();
        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame($event->ingest_id, $attempt->ingest_id);
    }

    public function test_captured_body_is_immutable_after_delivery_completes(): void
    {
        [, $token] = $this->proxyWithToken();
        $raw = '{"immutable":"exact-bytes"}';

        // Delivery runs synchronously (::run) during the request, so by the time the
        // response returns delivery is complete. Re-read the row and confirm the raw
        // body is byte-for-byte unchanged (AC8).
        $this->ingest($token, $raw)->assertStatus(202);

        $this->assertSame($raw, WebhookEvent::firstOrFail()->fresh()->body);
    }
}
