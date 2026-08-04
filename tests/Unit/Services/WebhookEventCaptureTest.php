<?php

namespace Tests\Unit\Services;

use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\WebhookEventCapture;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookEventCaptureTest extends TestCase
{
    public function test_capture_persists_one_row_with_the_passed_fields(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $ingestId = (string) Str::uuid();
        $rawBody = '{"event":"charge.succeeded"}';
        $headers = ['Content-Type' => ['application/json'], 'X-Sig' => ['abc']];

        $event = (new WebhookEventCapture)->capture($proxy, $ingestId, 'POST', $headers, $rawBody);

        $this->assertSame(1, WebhookEvent::count());
        $fresh = $event->fresh();
        $this->assertSame($ingestId, $fresh->ingest_id);
        $this->assertSame('POST', $fresh->method);
        $this->assertSame($rawBody, $fresh->body); // decrypts back to the original bytes
        $this->assertEquals($headers, $fresh->headers);
        $this->assertSame('application/json', $fresh->content_type);
        $this->assertSame(strlen($rawBody), $fresh->byte_size);
        $this->assertNotNull($fresh->received_at);
    }

    public function test_team_id_and_proxy_id_come_from_the_proxy_not_the_authenticated_user(): void
    {
        $proxy = Proxy::factory()->createQuietly();

        // An unrelated user on a different team is authenticated — capture must ignore it.
        $otherUser = User::factory()->createQuietly();
        $otherUser->switchTeam($otherUser->currentTeam);
        $this->actingAs($otherUser);

        $this->assertNotSame($proxy->team_id, $otherUser->current_team_id);

        $event = (new WebhookEventCapture)->capture($proxy, (string) Str::uuid(), 'POST', [], 'body');

        $this->assertSame($proxy->team_id, $event->fresh()->team_id);
        $this->assertSame($proxy->id, $event->fresh()->proxy_id);
    }

    public function test_content_type_is_null_when_the_header_is_absent(): void
    {
        $proxy = Proxy::factory()->createQuietly();

        $event = (new WebhookEventCapture)->capture(
            $proxy,
            (string) Str::uuid(),
            'PUT',
            ['X-Only' => ['no-content-type-here']],
            'payload',
        );

        $this->assertNull($event->fresh()->content_type);
    }

    public function test_content_type_is_derived_from_a_mixed_case_header_name(): void
    {
        $proxy = Proxy::factory()->createQuietly();

        $event = (new WebhookEventCapture)->capture(
            $proxy,
            (string) Str::uuid(),
            'POST',
            ['CoNtEnT-TyPe' => ['text/xml; charset=utf-8']],
            '<xml/>',
        );

        $this->assertSame('text/xml; charset=utf-8', $event->fresh()->content_type);
    }

    public function test_byte_size_records_the_plaintext_size(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $rawBody = str_repeat('x', 5000);

        $event = (new WebhookEventCapture)->capture($proxy, (string) Str::uuid(), 'POST', [], $rawBody);

        // Plaintext size, not the (larger) encrypted envelope stored at rest.
        $this->assertSame(5000, $event->fresh()->byte_size);
    }
}
