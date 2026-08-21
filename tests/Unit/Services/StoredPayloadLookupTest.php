<?php

namespace Tests\Unit\Services;

use App\Enums\StoredPayloadState;
use App\Models\DeliveryAttempt;
use App\Models\DispatchedPayload;
use App\Models\WebhookEvent;
use App\Services\StoredPayloadLookup;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoredPayloadLookupTest extends TestCase
{
    public function test_returns_retained_for_an_event_with_no_cleaned_at(): void
    {
        $event = WebhookEvent::factory()->createQuietly();

        $state = (new StoredPayloadLookup)->for($event->ingest_id);

        $this->assertSame(StoredPayloadState::Retained, $state);
    }

    public function test_returns_cleaned_for_an_event_with_cleaned_at_set(): void
    {
        $event = WebhookEvent::factory()->cleaned()->createQuietly();

        $state = (new StoredPayloadLookup)->for($event->ingest_id);

        $this->assertSame(StoredPayloadState::Cleaned, $state);
    }

    public function test_returns_never_captured_for_an_unknown_ingest_id(): void
    {
        $state = (new StoredPayloadLookup)->for((string) Str::uuid());

        $this->assertSame(StoredPayloadState::NeverCaptured, $state);
    }

    public function test_returns_never_captured_when_only_a_delivery_attempt_exists_for_the_ingest_id(): void
    {
        // Proves the state is read from the captured row, never inferred from
        // delivery history (ADR-014 Decision 7, binding).
        $ingestId = (string) Str::uuid();
        DeliveryAttempt::factory()->createQuietly(['ingest_id' => $ingestId]);

        $state = (new StoredPayloadLookup)->for($ingestId);

        $this->assertSame(StoredPayloadState::NeverCaptured, $state);
    }

    public function test_dispatched_bytes_for_returns_the_raw_body_when_no_dispatched_payload_row_exists(): void
    {
        $event = WebhookEvent::factory()->createQuietly(['body' => '{"raw":true}']);

        $bytes = (new StoredPayloadLookup)->dispatchedBytesFor($event);

        // Cast round-trip (encrypted at rest), not the raw column bytes.
        $this->assertSame('{"raw":true}', $bytes);
    }

    public function test_dispatched_bytes_for_returns_the_raw_body_when_the_dispatched_payload_body_is_null(): void
    {
        // body IS NULL is the identical-payload case (ADR-013 Decision 2) —
        // the raw capture IS the dispatched output.
        $event = WebhookEvent::factory()->createQuietly(['body' => '{"raw":true}']);
        DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $event->team_id,
            'proxy_id' => $event->proxy_id,
            'body' => null,
        ]);

        $bytes = (new StoredPayloadLookup)->dispatchedBytesFor($event);

        $this->assertSame('{"raw":true}', $bytes);
    }

    public function test_dispatched_bytes_for_returns_the_diverged_dispatched_payload_body(): void
    {
        $event = WebhookEvent::factory()->createQuietly(['body' => '{"raw":true}']);
        DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $event->team_id,
            'proxy_id' => $event->proxy_id,
            'body' => '{"mapped":true}',
        ]);

        $bytes = (new StoredPayloadLookup)->dispatchedBytesFor($event);

        // The diverged, decrypted dispatched body — not the raw capture.
        $this->assertSame('{"mapped":true}', $bytes);
    }
}
