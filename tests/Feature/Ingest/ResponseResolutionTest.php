<?php

namespace Tests\Feature\Ingest;

use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end proof of the response-config half over the real POST/PUT ingest route
 * (AC1–AC4, ADR-004). Complements the unit-level ResponseResolverTest (T3): the
 * ingest response is resolved from proxy config, before and independently of
 * delivery. Delivery outcomes are faked; the response must never move with them.
 */
class ResponseResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Event::fake() suppresses delivery listeners only — the pipeline still runs
        // DeliverToDestination (an action call, not an event), so delivery is exercised.
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

    public function test_configured_proxy_returns_exact_status_body_and_content_type(): void
    {
        [, $token] = $this->proxyWithToken([
            'response_status' => 200,
            'response_body' => '{"ok":true}',
        ]);

        $this->post($this->ingestUrl($token), ['hello' => 'world'])
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertContent('{"ok":true}');
    }

    public function test_204_configured_proxy_returns_no_body(): void
    {
        // 204 = No Content: the response carries no body, distinct from 200/202
        // which return the configured body (AC12).
        [, $token] = $this->proxyWithToken([
            'response_status' => 204,
            'response_body' => null,
        ]);

        $response = $this->post($this->ingestUrl($token), ['hello' => 'world']);

        $response->assertStatus(204);
        $this->assertSame('', $response->getContent());
        $this->assertStringNotContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_unconfigured_proxy_inherits_202_accepted_empty_body(): void
    {
        // A factory proxy has no response config — simulates a pre-#3, #1-created row (AC3).
        [, $token] = $this->proxyWithToken();

        $response = $this->post($this->ingestUrl($token), ['hello' => 'world']);

        $response->assertStatus(202);
        $this->assertSame('', $response->getContent());
        // No configured body → no forced text/plain content-type.
        $this->assertStringNotContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_response_is_identical_regardless_of_delivery_outcome(): void
    {
        // Three delivery outcomes: success, upstream 500, and a thrown transport
        // exception. DeliverToDestination catches Throwable internally (ADR-003), and
        // the response is resolved before dispatch (ADR-004) — so all three yield the
        // exact same configured 2xx response (AC2).
        $outcomes = [
            'success' => Http::response('ok', 200),
            'destination-500' => Http::response('boom', 500),
            'throws' => fn () => throw new \RuntimeException('connection failed'),
        ];

        foreach ($outcomes as $label => $fake) {
            Http::fake(['*' => $fake]);

            [, $token] = $this->proxyWithToken([
                'response_status' => 200,
                'response_body' => 'ACK',
            ]);

            $this->post($this->ingestUrl($token), ['hello' => 'world'])
                ->assertStatus(200)
                ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
                ->assertContent('ACK', "Response must be identical for the '{$label}' delivery outcome.");
        }
    }

    public function test_configured_response_holds_even_when_delivery_fails(): void
    {
        // The resolver reads only proxy columns — never DeliveryAttempt/delivery
        // outcome (ADR-004, AC2; guaranteed at the code level in T3/ResponseResolver).
        // Behavioral proof: a failing destination does not alter the configured 2xx.
        Http::fake(['*' => Http::response('nope', 503)]);

        [, $token] = $this->proxyWithToken([
            'response_status' => 202,
            'response_body' => 'received',
        ]);

        $this->put($this->ingestUrl($token), ['hello' => 'world'])
            ->assertStatus(202)
            ->assertContent('received');
    }
}
