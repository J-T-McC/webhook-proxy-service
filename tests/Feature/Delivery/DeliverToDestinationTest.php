<?php

namespace Tests\Feature\Delivery;

use App\Actions\DeliverToDestination;
use App\Enums\AttemptStatus;
use App\Enums\HttpMethod;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverToDestinationTest extends TestCase
{
    private function unit(Destination $destination, string $payload = '{"a":1}'): DeliveryUnit
    {
        return new DeliveryUnit(
            ingestId: (string) Str::uuid(),
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: ['Content-Type' => ['application/json']],
            payload: $payload,
            attemptNumber: 1,
        );
    }

    public function test_2xx_response_records_a_single_succeeded_attempt_and_event(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly(['http_method' => HttpMethod::Post]);

        DeliverToDestination::run($this->unit($destination));

        $this->assertSame(1, DeliveryAttempt::count());
        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(200, $attempt->http_status);
        $this->assertNotNull($attempt->duration_ms);

        Event::assertDispatched(DeliveryAttempted::class);
        Event::assertDispatched(DeliverySucceeded::class);
        Event::assertNotDispatched(DeliveryFailed::class);
    }

    public function test_non_2xx_response_records_a_failed_attempt_with_status(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->createQuietly();

        DeliverToDestination::run($this->unit($destination));

        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Failed, $attempt->status);
        $this->assertSame(500, $attempt->http_status);

        Event::assertDispatched(DeliveryAttempted::class);
        Event::assertDispatched(DeliveryFailed::class);
        Event::assertNotDispatched(DeliverySucceeded::class);
    }

    public function test_thrown_transport_error_records_failed_with_truncated_summary_and_no_body(): void
    {
        Event::fake();
        $longMessage = str_repeat('E', 600);
        Http::fake(fn () => throw new ConnectionException($longMessage));

        $destination = Destination::factory()->createQuietly();
        $payload = str_repeat('P', 400);

        DeliverToDestination::run($this->unit($destination, $payload));

        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Failed, $attempt->status);
        $this->assertNull($attempt->http_status);
        $this->assertNotNull($attempt->error_summary);
        $this->assertLessThanOrEqual(250, strlen((string) $attempt->error_summary));
        // The summary is a message, never the payload body.
        $this->assertStringNotContainsString($payload, (string) $attempt->error_summary);

        Event::assertDispatched(DeliveryFailed::class);
    }

    public function test_dispatched_row_exists_before_the_outcome_is_written(): void
    {
        Http::fake(function () {
            // At the moment of the HTTP call, a 'dispatched' row must already exist.
            $this->assertDatabaseHas('delivery_attempts', ['status' => AttemptStatus::Dispatched->value]);

            return Http::response('ok', 200);
        });

        $destination = Destination::factory()->createQuietly();

        DeliverToDestination::run($this->unit($destination));

        // Still exactly one attempt row (updated in place, not a second insert).
        $this->assertSame(1, DeliveryAttempt::count());
    }

    public function test_redelivery_after_success_is_a_no_op(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $unit = $this->unit($destination);

        DeliverToDestination::run($unit);
        // Simulated at-least-once redelivery of the SAME unit.
        DeliverToDestination::run($unit);

        // Exactly one row, one send, one success event — the redelivery skipped.
        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(DeliverySucceeded::class, 1);
        Event::assertNotDispatched(DeliveryFailed::class);
    }

    public function test_redelivery_after_failure_is_a_no_op(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->createQuietly();
        $unit = $this->unit($destination);

        DeliverToDestination::run($unit);
        DeliverToDestination::run($unit);

        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(DeliveryFailed::class, 1);
        Event::assertNotDispatched(DeliverySucceeded::class);
    }

    public function test_a_row_left_dispatched_is_re_driven_on_the_same_row(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $unit = $this->unit($destination);

        // Simulate a worker that crashed after inserting the 'dispatched' row but
        // before settling it (matching the unit's idempotency key).
        $orphan = DeliveryAttempt::create([
            'team_id' => $unit->teamId,
            'proxy_id' => $unit->proxyId,
            'destination_id' => $destination->id,
            'ingest_id' => $unit->ingestId,
            'status' => AttemptStatus::Dispatched,
            'attempt_number' => $unit->attemptNumber,
            'started_at' => now(),
        ]);

        DeliverToDestination::run($unit);

        // No new row: the SAME row was re-driven to a terminal state.
        $this->assertSame(1, DeliveryAttempt::count());
        $settled = $orphan->fresh();
        $this->assertSame($orphan->id, $settled->id);
        $this->assertSame(AttemptStatus::Succeeded, $settled->status);
        Http::assertSentCount(1);
    }

    public function test_unique_index_rejects_a_raw_duplicate_insert(): void
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
