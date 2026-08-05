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
use Illuminate\Database\QueryException;
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

    public function test_the_unique_index_rejects_a_raw_duplicate_insert(): void
    {
        $destination = Destination::factory()->createQuietly();
        $attempt = DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
            'destination_id' => $destination->id,
        ]);

        $this->expectException(QueryException::class);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $attempt->proxy_id,
            'team_id' => $attempt->team_id,
            'destination_id' => $attempt->destination_id,
            'ingest_id' => $attempt->ingest_id,
            'attempt_number' => $attempt->attempt_number,
        ]);
    }
}
