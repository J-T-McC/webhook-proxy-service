<?php

namespace Tests\Feature\Ingest;

use App\Enums\AttemptStatus;
use App\Enums\HttpMethod;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestFanOutTest extends TestCase
{
    /**
     * @param  array<string, string>  $headers
     */
    private function ingest(string $token, string $rawBody, array $headers = []): TestResponse
    {
        // Raw call() does not apply withHeaders() defaults, so transform inbound
        // headers into server vars explicitly (and preserve the exact raw body).
        $server = array_merge(
            $this->transformHeadersToServerVars($headers),
            ['CONTENT_TYPE' => 'application/json'],
        );

        return $this->call('POST', 'https://localhost/ingest/'.$token, [], [], [], $server, $rawBody);
    }

    public function test_it_fans_out_one_request_per_live_destination_with_method_and_body_unchanged(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://a.test/hook', 'http_method' => HttpMethod::Post]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://b.test/hook', 'http_method' => HttpMethod::Put]);
        $trashed = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://trashed.test/hook']);
        $trashed->delete();

        $rawBody = '{"event":"invoice.paid","amount":42}';

        $this->ingest($proxy->ingest_token, $rawBody)->assertStatus(202);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://a.test/hook' && $r->method() === 'POST' && $r->body() === $rawBody);
        Http::assertSent(fn ($r) => $r->url() === 'https://b.test/hook' && $r->method() === 'PUT' && $r->body() === $rawBody);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://trashed.test/hook');
    }

    public function test_header_forwarding_end_to_end(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://dest.test/hook', 'http_method' => HttpMethod::Post]);

        $this->ingest($proxy->ingest_token, '{}', [
            'X-Custom-Event' => 'invoice.paid',
            'Cookie' => 'session=secret',
            'Authorization' => 'Bearer inbound-secret',
            'Connection' => 'keep-alive',
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertStatus(202);

        // ADR-026 Decision A (T55): the strip list is transport-scoped only —
        // `Cookie`, `Authorization` and every provider signature header now
        // forward unchanged; only the RFC 7230 §6.1 hop-by-hop set (here,
        // `Connection`) remains stripped.
        Http::assertSent(function ($r) {
            return $r->url() === 'https://dest.test/hook'
                // Forwarded:
                && $r->hasHeader('Content-Type')
                && $r->hasHeader('X-Custom-Event')
                && $r->hasHeader('Cookie', 'session=secret')
                && $r->hasHeader('Authorization', 'Bearer inbound-secret')
                && $r->hasHeader('Stripe-Signature', 't=1,v1=abc')
                // Stripped:
                && ! $r->hasHeader('Connection');
        });
    }

    public function test_one_destination_failing_does_not_prevent_others_and_still_returns_202(): void
    {
        Http::fake([
            'fail.test/*' => fn () => throw new ConnectionException('down'),
            '*' => Http::response('ok', 200),
        ]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://fail.test/hook']);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://ok.test/hook']);

        $this->ingest($proxy->ingest_token, '{}')->assertStatus(202);

        Http::assertSent(fn ($r) => $r->url() === 'https://ok.test/hook');
        // T14: still un-faked, so the failing destination's scheduled `RetryDelivery`
        // also drains inline under sync, cascading through the system-default
        // attempt limit (5, config/retry.php) before terminalizing — 5 for the
        // failing destination + 1 for the healthy one.
        $this->assertSame(6, DeliveryAttempt::count());
    }

    public function test_exactly_one_attempt_per_destination_with_outcome_metadata_and_no_payload(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 201)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $this->ingest($proxy->ingest_token, '{"a":1}')->assertStatus(202);

        $this->assertSame(2, DeliveryAttempt::count());

        $attempts = DeliveryAttempt::all();
        foreach ($attempts as $attempt) {
            $this->assertSame(AttemptStatus::Succeeded, $attempt->status);
            $this->assertSame(201, $attempt->http_status);
            $this->assertSame($proxy->id, $attempt->proxy_id);
            $this->assertNotNull($attempt->destination_id);
            $this->assertNotNull($attempt->ingest_id);
        }

        // Both attempts correlate under one ingest_id (the fan-out set).
        $this->assertCount(1, $attempts->pluck('ingest_id')->unique());

        // Payload-free by construction: no payload column, no payload table.
        $this->assertNotContains('payload', Schema::getColumnListing('delivery_attempts'));
        $this->assertFalse(Schema::hasTable('webhook_payloads'));

        Event::assertDispatched(DeliveryAttempted::class, 2);
        Event::assertDispatched(DeliverySucceeded::class, 2);
        Event::assertNotDispatched(DeliveryFailed::class);
    }

    public function test_failure_outcome_records_failed_with_error_summary(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('boom', 500)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        $this->ingest($proxy->ingest_token, '{}')->assertStatus(202);

        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Failed, $attempt->status);
        $this->assertSame(500, $attempt->http_status);

        Event::assertDispatched(DeliveryFailed::class);
    }

    public function test_response_is_202_even_when_all_deliveries_fail(): void
    {
        Http::fake(['*' => Http::response('no', 503)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $this->ingest($proxy->ingest_token, '{}')->assertStatus(202);
    }

    public function test_simple_mode_delivers_body_unchanged_and_stores_no_payload(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(); // simple mode by default
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://dest.test/hook']);

        $rawBody = '{"unchanged":true,"n":7}';
        $this->ingest($proxy->ingest_token, $rawBody)->assertStatus(202);

        Http::assertSent(fn ($r) => $r->body() === $rawBody);
        $this->assertFalse(Schema::hasTable('webhook_payloads'));
    }
}
