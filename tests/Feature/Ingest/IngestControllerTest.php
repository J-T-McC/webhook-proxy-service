<?php

namespace Tests\Feature\Ingest;

use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
        $proxy = Proxy::factory()->create();
        Destination::factory()->for($proxy)->create();

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
}
