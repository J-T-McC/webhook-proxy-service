<?php

namespace Tests\Feature\Retention;

use App\Actions\PurgeExpiredPayloads;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end proof that header encryption at rest is transparent to every
 * existing consumer (AC15, AC22a) — complementing T4's unit-level cast test.
 * ADR-008 forwarding transparency (the cast change reaching every destination
 * unchanged, `STRIPPED_HEADERS` still filtered) is already proven end to end
 * by the existing `IngestFanOutTest::test_header_forwarding_end_to_end` and
 * `IngestEventCaptureTest` — both keep passing unmodified, so that
 * coverage is not duplicated here.
 */
class HeaderEncryptionTest extends TestCase
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
    private function proxyWithToken(): array
    {
        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        return [$proxy, $proxy->ingest_token];
    }

    /**
     * @param  array<string, string>  $server
     */
    private function ingest(string $token, string $raw, array $server = []): TestResponse
    {
        return $this->call('POST', 'https://localhost/ingest/'.$token, [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
        ], $server), $raw);
    }

    public function test_captured_headers_are_encrypted_at_rest_over_the_real_capture_path(): void
    {
        [, $token] = $this->proxyWithToken();

        $this->ingest($token, '{"id":"evt_1"}', ['HTTP_X_CUSTOM' => 'sig-value'])
            ->assertStatus(202);

        $event = WebhookEvent::firstOrFail();

        // Ciphertext at rest: the raw column value is not the plaintext header JSON.
        $stored = DB::table('webhook_events')->where('id', $event->id)->value('headers');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('x-custom', $stored);
        $this->assertStringNotContainsString('sig-value', $stored);
        $this->assertStringNotContainsString('content-type', $stored);

        // The model attribute round-trips to the exact original captured headers.
        $this->assertSame(['sig-value'], $event->fresh()->headers['x-custom'] ?? null);
        $this->assertSame(['application/json'], $event->fresh()->headers['content-type'] ?? null);
    }

    public function test_content_type_survives_erasure_while_the_header_collection_does_not(): void
    {
        [, $token] = $this->proxyWithToken();

        $this->ingest($token, '{"id":"evt_1"}', ['HTTP_X_CUSTOM' => 'sig-value'])
            ->assertStatus(202);

        $event = WebhookEvent::firstOrFail();
        $this->assertSame('application/json', $event->content_type);

        // Age the captured row past the retention window and run a real GC pass.
        DB::table('webhook_events')->where('id', $event->id)->update([
            'created_at' => now()->subDays(31),
        ]);
        PurgeExpiredPayloads::run();

        $raw = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNull($raw->headers);
        $this->assertNotNull($raw->payload_cleaned_at);
        // content_type is a retained descriptor, not part of the erased header
        // collection (AC6, ADR-014 Decision 6) — it survives the erase untouched.
        $this->assertSame('application/json', $raw->content_type);
    }
}
