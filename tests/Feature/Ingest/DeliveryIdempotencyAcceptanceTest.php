<?php

namespace Tests\Feature\Ingest;

use App\Actions\DeliverToDestination;
use App\Enums\AttemptStatus;
use App\Enums\ProcessingMode;
use App\Events\DeliverySucceeded;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\DeliveryUnit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end proof of exactly-once settlement under simulated at-least-once queue
 * redelivery (T17, AC9), complementing the DeliverToDestination unit test.
 */
class DeliveryIdempotencyAcceptanceTest extends TestCase
{
    public function test_redelivering_a_settled_unit_produces_no_second_send_row_or_event(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        $destination = Destination::factory()->for($proxy)->createQuietly();

        // Real ingest drains inline (sync): one successful delivery.
        $this->post('https://localhost/ingest/'.$proxy->ingest_token, ['a' => 'b'])
            ->assertStatus(202);

        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(DeliverySucceeded::class, 1);

        // Simulate the queue redelivering the SAME delivery job (same idempotency key).
        $event = WebhookEvent::firstOrFail();
        $redelivery = new DeliveryUnit(
            ingestId: $event->ingest_id,
            teamId: $proxy->team_id,
            proxyId: $proxy->id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: $event->headers,
            payload: $event->body,
            attemptNumber: 1,
        );

        DeliverToDestination::run($redelivery);

        // Nothing changed: still one row, one send, one event.
        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(DeliverySucceeded::class, 1);
        $this->assertSame(AttemptStatus::Succeeded, DeliveryAttempt::firstOrFail()->status);
    }

    // The raw-duplicate-insert DB-enforcement probe formerly here proved
    // UNIQUE(ingest_id, destination_id, attempt_number) — retired by T5
    // (ADR-015 Decision 2 / ADR-016 P3, the idempotency-key swap to
    // (delivery_id, attempt_number)). The schema-level fact (old key no
    // longer collides, new key does) is covered by
    // tests/Unit/Models/DeliveryAttemptTest.php. T10 restores the equivalent
    // race-safety-net probe here once DeliverToDestination reads
    // `delivery_id` (its own AC names this file explicitly).
}
