<?php

namespace Tests\Feature\Retention;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Enums\StoredPayloadState;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Services\StoredPayloadLookup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

/**
 * End-to-end proof that the cleaned state is signalled correctly and every
 * reader guards on it (AC10, AC21) — including proof that
 * `AdvanceProxyFifoQueue`, left unmodified, remains correct under the entry
 * guard (T10). Complements the `StoredPayloadLookupTest` /
 * `ProcessIngestedWebhookTest` unit-level coverage by composing them over the
 * real services rather than duplicating their cases.
 */
class CleanedStateReaderGuardTest extends TestCase
{
    use DrainsQueuedDeliveries;

    public function test_stored_payload_lookup_signals_the_correct_state_for_each_case(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $retained = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $cleaned = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        // A delivery-attempts row exists for this ingest_id, but no captured row does —
        // the state must still resolve from the captured row, never delivery history.
        $orphanIngestId = 'no-captured-row-for-this-ingest-id';
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => $orphanIngestId,
        ]);

        $lookup = app(StoredPayloadLookup::class);

        $this->assertSame(StoredPayloadState::Retained, $lookup->for($retained->ingest_id));
        $this->assertSame(StoredPayloadState::Cleaned, $lookup->for($cleaned->ingest_id));
        $this->assertSame(StoredPayloadState::NeverCaptured, $lookup->for($orphanIngestId));
        $this->assertSame(StoredPayloadState::NeverCaptured, $lookup->for('genuinely-unknown-ingest-id'));
    }

    public function test_process_ingested_webhook_on_a_cleaned_event_returns_cleanly_and_dispatches_nothing(): void
    {
        Http::fake();

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(2)->createQuietly();
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(0, DeliveryAttempt::count());
        Http::assertNothingSent();
    }

    public function test_process_ingested_webhook_on_a_genuinely_missing_row_still_throws(): void
    {
        Http::fake();

        $this->expectException(ModelNotFoundException::class);

        ProcessIngestedWebhook::run('genuinely-missing-ingest-id');
    }

    public function test_advance_proxy_fifo_queue_unmodified_settles_and_advances_past_a_cleaned_claim(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://fifo.test/hook']);

        $cleaned = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'evt-cleaned',
        ]);
        $next = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'evt-next',
            'body' => 'evt-next-body',
            'byte_size' => strlen('evt-next-body'),
        ]);
        $firstDispatch = FifoDispatch::factory()->createQuietly([
            'webhook_event_id' => $cleaned->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $secondDispatch = FifoDispatch::factory()->createQuietly([
            'webhook_event_id' => $next->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        // The claimed event's parent is marked cleaned BEFORE the advancer processes
        // it — simulating a GC pass that ran between the claim and the delivery.
        $cleaned->forceFill(['payload_cleaned_at' => now(), 'body' => null, 'headers' => null])->saveQuietly();

        AdvanceProxyFifoQueue::run($proxy->id);

        // The claim settles and no delivery happens for the cleaned event — no stall,
        // no exception propagating out of the advancer.
        $this->assertSame(FifoDispatchStatus::Settled, $firstDispatch->fresh()->status);
        $this->assertSame(0, DeliveryAttempt::count());
        Http::assertNothingSent();

        // The line advances to the next pending row exactly as it would for a normal
        // delivery (self-dispatch is captured by Queue::fake; advance explicitly).
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        $this->assertSame(FifoDispatchStatus::Settled, $secondDispatch->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSent(fn ($r) => $r->body() === 'evt-next-body');
    }
}
