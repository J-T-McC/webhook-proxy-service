<?php

namespace Tests\Feature\Ingest;

use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Services\WebhookEventCapture;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class IngestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    private function proxyWithToken(): array
    {
        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        return [$proxy, $proxy->ingest_token];
    }

    private function ingestUrl(string $token, string $scheme = 'https'): string
    {
        return $scheme.'://localhost/ingest/'.$token;
    }

    public function test_valid_token_returns_202(): void
    {
        [, $token] = $this->proxyWithToken();

        $this->post($this->ingestUrl($token), ['hello' => 'world'])
            ->assertStatus(202);
    }

    public function test_put_is_accepted_and_needs_no_session_or_csrf_token(): void
    {
        [, $token] = $this->proxyWithToken();

        // No session, no CSRF token supplied — must still succeed (CSRF-exempt).
        $this->put($this->ingestUrl($token), ['hello' => 'world'])
            ->assertStatus(202);
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->post($this->ingestUrl('this-token-does-not-exist'))
            ->assertStatus(404);
    }

    public function test_soft_deleted_proxys_token_returns_404_and_no_longer_ingests(): void
    {
        [$proxy, $token] = $this->proxyWithToken();
        $proxy->delete();

        $this->post($this->ingestUrl($token))->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_spoofed_host_header_does_not_affect_resolution(): void
    {
        [, $token] = $this->proxyWithToken();

        $this->post($this->ingestUrl($token), [], ['Host' => 'attacker.example.com'])
            ->assertStatus(202);
    }

    public function test_non_https_request_is_rejected(): void
    {
        [, $token] = $this->proxyWithToken();

        $this->post($this->ingestUrl($token, 'http'))
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_body_size_and_rate_limit_config_defaults_are_high_placeholders(): void
    {
        $this->assertSame(52_428_800, config('ingest.max_body_bytes'));
        $this->assertSame(6_000, config('ingest.rate_limit_per_minute'));
    }

    public function test_successful_ingest_commits_a_capture_row_sharing_the_delivery_ingest_id(): void
    {
        [$proxy, $token] = $this->proxyWithToken();

        $this->post($this->ingestUrl($token), ['hello' => 'world'])
            ->assertStatus(202);

        // Exactly one capture row, committed synchronously with the response.
        $this->assertSame(1, WebhookEvent::count());
        $event = WebhookEvent::firstOrFail();
        $this->assertSame($proxy->id, $event->proxy_id);
        $this->assertSame('POST', $event->method);

        // Capture and the fan-out delivery_attempts share the one ingest_id (AC9).
        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame($event->ingest_id, $attempt->ingest_id);
    }

    public function test_capture_happens_in_enhanced_mode_too(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 'b'])
            ->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame($proxy->id, WebhookEvent::firstOrFail()->proxy_id);
    }

    public function test_capture_failure_returns_500_commits_no_row_and_dispatches_nothing(): void
    {
        [, $token] = $this->proxyWithToken();

        // Force the capture write to throw — the whole request must fail closed.
        $this->mock(
            WebhookEventCapture::class,
            fn (MockInterface $m) => $m->shouldReceive('capture')
                ->andThrow(new \RuntimeException('capture store unavailable')),
        );

        $this->post($this->ingestUrl($token), ['hello' => 'world'])
            ->assertStatus(500);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(0, DeliveryAttempt::count());
        Http::assertNothingSent();
    }
}
