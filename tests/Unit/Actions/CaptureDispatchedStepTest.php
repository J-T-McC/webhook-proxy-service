<?php

namespace Tests\Unit\Actions;

use App\Actions\CaptureDispatchedStep;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\PipelineContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CaptureDispatchedStepTest extends TestCase
{
    private function eventFor(Proxy $proxy, string $ingestId, string $rawBody): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => $ingestId,
            'body' => $rawBody,
            'byte_size' => strlen($rawBody),
        ]);
    }

    private function contextFor(Proxy $proxy, string $ingestId, string $rawBody, ?string $payload = null): PipelineContext
    {
        return new PipelineContext(
            ingestId: $ingestId,
            proxy: $proxy->fresh(),
            method: 'POST',
            headers: ['content-type' => ['application/json']],
            rawBody: $rawBody,
            payload: $payload,
        );
    }

    public function test_identical_payload_stores_a_null_body_with_the_raw_byte_size(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        $rawBody = '{"hello":"world"}';
        $ingestId = 'ingest-'.$proxy->id;
        $this->eventFor($proxy, $ingestId, $rawBody);
        $ctx = $this->contextFor($proxy, $ingestId, $rawBody);

        CaptureDispatchedStep::make()->handle($ctx, fn (PipelineContext $c) => $c);

        $row = DispatchedPayload::query()->sole();
        $this->assertNull($row->body);
        $this->assertSame(strlen($rawBody), $row->byte_size);
    }

    public function test_diverged_payload_stores_the_dispatched_bytes_encrypted_at_rest(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        $rawBody = '{"hello":"world"}';
        $diverged = '{"hello":"mapped","extra":true}';
        $ingestId = 'ingest-'.$proxy->id;
        $this->eventFor($proxy, $ingestId, $rawBody);
        $ctx = $this->contextFor($proxy, $ingestId, $rawBody, $diverged);

        CaptureDispatchedStep::make()->handle($ctx, fn (PipelineContext $c) => $c);

        $row = DispatchedPayload::query()->sole();
        $this->assertSame($diverged, $row->fresh()->body);
        $this->assertSame(strlen($diverged), $row->byte_size);

        // Ciphertext at rest is not the plaintext.
        $stored = DB::table('dispatched_payloads')->where('id', $row->id)->value('body');
        $this->assertNotSame($diverged, $stored);
    }

    public function test_reinvoking_for_the_same_event_updates_rather_than_duplicates(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        $rawBody = '{"hello":"world"}';
        $ingestId = 'ingest-'.$proxy->id;
        $this->eventFor($proxy, $ingestId, $rawBody);

        CaptureDispatchedStep::make()->handle(
            $this->contextFor($proxy, $ingestId, $rawBody),
            fn (PipelineContext $c) => $c,
        );

        $diverged = '{"hello":"mapped"}';
        CaptureDispatchedStep::make()->handle(
            $this->contextFor($proxy, $ingestId, $rawBody, $diverged),
            fn (PipelineContext $c) => $c,
        );

        $this->assertSame(1, DispatchedPayload::count());
        $this->assertSame($diverged, DispatchedPayload::query()->sole()->body);
    }

    public function test_context_payload_and_raw_body_are_untouched(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        $rawBody = '{"hello":"world"}';
        $diverged = '{"hello":"mapped"}';
        $ingestId = 'ingest-'.$proxy->id;
        $this->eventFor($proxy, $ingestId, $rawBody);
        $ctx = $this->contextFor($proxy, $ingestId, $rawBody, $diverged);

        CaptureDispatchedStep::make()->handle($ctx, fn (PipelineContext $c) => $c);

        $this->assertSame($rawBody, $ctx->rawBody);
        $this->assertSame($diverged, $ctx->payload);
    }

    public function test_next_is_invoked_with_the_same_context(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        $rawBody = '{"hello":"world"}';
        $ingestId = 'ingest-'.$proxy->id;
        $this->eventFor($proxy, $ingestId, $rawBody);
        $ctx = $this->contextFor($proxy, $ingestId, $rawBody);

        $nextCalled = false;
        $result = CaptureDispatchedStep::make()->handle($ctx, function (PipelineContext $c) use (&$nextCalled) {
            $nextCalled = true;

            return $c;
        });

        $this->assertTrue($nextCalled);
        $this->assertSame($ctx, $result);
    }

    public function test_a_cleaned_parent_prevents_the_write_and_the_next_call(): void
    {
        Log::spy();

        $proxy = Proxy::factory()->enhanced()->createQuietly();
        $rawBody = '{"hello":"world"}';
        $ingestId = 'ingest-'.$proxy->id;
        WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => $ingestId,
        ]);
        $ctx = $this->contextFor($proxy, $ingestId, $rawBody);

        $nextCalled = false;
        CaptureDispatchedStep::make()->handle($ctx, function (PipelineContext $c) use (&$nextCalled) {
            $nextCalled = true;

            return $c;
        });

        $this->assertFalse($nextCalled);
        $this->assertSame(0, DispatchedPayload::count());

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'payload.expired'
                && $context === ['ingest_id' => $ingestId]);
    }

    public function test_an_uncleaned_parent_behaves_as_in_t7(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        $rawBody = '{"hello":"world"}';
        $ingestId = 'ingest-'.$proxy->id;
        $this->eventFor($proxy, $ingestId, $rawBody);
        $ctx = $this->contextFor($proxy, $ingestId, $rawBody);

        $nextCalled = false;
        CaptureDispatchedStep::make()->handle($ctx, function (PipelineContext $c) use (&$nextCalled) {
            $nextCalled = true;

            return $c;
        });

        $this->assertTrue($nextCalled);
        $this->assertSame(1, DispatchedPayload::count());
    }
}
