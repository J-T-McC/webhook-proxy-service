<?php

namespace Tests\Feature\Delivery;

use App\Actions\DeliverToDestination;
use App\Actions\RetryDelivery;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\HttpMethod;
use App\Enums\RetryBackoffStrategy;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryExhausted;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Pipeline\DeliveryUnit;
use App\Services\RetryPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Tests\TestCase;

class DeliverToDestinationTest extends TestCase
{
    /**
     * One `deliveries` row per unit, mirroring T8's shape — `delivery_id` is a
     * restrict FK to `deliveries` (T5), so every unit under test needs a real row.
     */
    private function deliveryFor(Destination $destination): Delivery
    {
        return Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
        ]);
    }

    private function unit(
        Destination $destination,
        string $payload = '{"a":1}',
        ?int $deliveryId = null,
        int $attemptNumber = 1,
    ): DeliveryUnit {
        return new DeliveryUnit(
            ingestId: (string) Str::uuid(),
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: ['Content-Type' => ['application/json']],
            payload: $payload,
            deliveryId: $deliveryId ?? $this->deliveryFor($destination)->id,
            attemptNumber: $attemptNumber,
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
            'delivery_id' => $unit->deliveryId,
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

    public function test_two_different_deliveries_can_legitimately_share_attempt_number_one_with_no_collision(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        // Same destination, two distinct deliveries (e.g. original + a later
        // replay dispatch) — the reason the old (ingest_id, destination_id,
        // attempt_number) key could not survive replay (ADR-015 Decision 2).
        $destination = Destination::factory()->createQuietly();
        $first = $this->unit($destination);
        $second = $this->unit($destination);

        DeliverToDestination::run($first);
        DeliverToDestination::run($second);

        $this->assertSame(2, DeliveryAttempt::count());
        $this->assertSame(1, DeliveryAttempt::where('delivery_id', $first->deliveryId)->count());
        $this->assertSame(1, DeliveryAttempt::where('delivery_id', $second->deliveryId)->count());
        Http::assertSentCount(2);
    }

    public function test_unique_index_rejects_a_raw_duplicate_insert(): void
    {
        // T10 restores the race-safety-net probe against the NEW key
        // (delivery_id, attempt_number) — the equivalent of the pre-T5 probe
        // this file carried against the retired (ingest_id, destination_id,
        // attempt_number) key.
        $destination = Destination::factory()->createQuietly();
        $unit = $this->unit($destination);

        DeliveryAttempt::create([
            'team_id' => $unit->teamId,
            'proxy_id' => $unit->proxyId,
            'destination_id' => $destination->id,
            'ingest_id' => $unit->ingestId,
            'delivery_id' => $unit->deliveryId,
            'status' => AttemptStatus::Dispatched,
            'attempt_number' => $unit->attemptNumber,
            'started_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DeliveryAttempt::create([
            'team_id' => $unit->teamId,
            'proxy_id' => $unit->proxyId,
            'destination_id' => $destination->id,
            'ingest_id' => (string) Str::uuid(),
            'delivery_id' => $unit->deliveryId,
            'status' => AttemptStatus::Dispatched,
            'attempt_number' => $unit->attemptNumber,
            'started_at' => now(),
        ]);
    }

    public function test_a_successful_attempt_cases_the_delivery_to_succeeded_and_schedules_nothing(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $delivery = $this->deliveryFor($destination);

        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id));

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Succeeded, $fresh->status);
        $this->assertNull($fresh->next_attempt_at);

        RetryDelivery::assertNotPushed();
        Event::assertNotDispatched(DeliveryExhausted::class);
    }

    public function test_a_failed_attempt_below_the_limit_on_a_simple_mode_proxy_cases_to_retrying_and_schedules_a_delayed_retry(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->createQuietly();
        $proxy = $destination->proxy;
        $delivery = $this->deliveryFor($destination);

        $expectedDelay = app(RetryPolicy::class)->delayBefore($proxy, 2);

        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id));

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Retrying, $fresh->status);
        $this->assertNotNull($fresh->next_attempt_at);

        RetryDelivery::assertPushed(function ($action, array $params, JobDecorator $job, $queue) use ($delivery, $expectedDelay) {
            return $params === [$delivery->id, 2]
                && $queue === config('ingest.webhooks_queue')
                && $job->delay !== null
                && (int) $job->delay->totalSeconds === (int) $expectedDelay->totalSeconds;
        });
    }

    public function test_a_failed_attempt_at_the_limit_cases_to_failed_and_emits_delivery_exhausted_exactly_once(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->createQuietly();
        $delivery = $this->deliveryFor($destination);

        // Default system limit is 5 (config/retry.php) — attempt 5 is terminal.
        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id, attemptNumber: 5));

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertNull($fresh->next_attempt_at);

        RetryDelivery::assertNotPushed();
        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
        Event::assertDispatched(DeliveryExhausted::class, fn (DeliveryExhausted $event) => $event->delivery->id === $delivery->id);
    }

    public function test_a_racing_duplicate_terminal_settle_fires_no_duplicate_event_and_schedules_nothing(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->createQuietly();
        $delivery = $this->deliveryFor($destination);

        // Simulate a concurrent settler that already won the terminal CAS for
        // this delivery before this attempt's own settle runs.
        Delivery::query()->whereKey($delivery->id)->update([
            'status' => DeliveryStatus::Failed,
            'next_attempt_at' => null,
        ]);

        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id, attemptNumber: 5));

        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        RetryDelivery::assertNotPushed();
        Event::assertNotDispatched(DeliveryExhausted::class);
    }

    public function test_an_enhanced_proxy_with_a_lower_limit_and_fixed_strategy_stops_after_its_limit_with_constant_delays(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->enhanced()->createQuietly([
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $delivery = $this->deliveryFor($destination);

        // Attempt 1 fails, below the limit of 2 — retrying, constant fixed delay.
        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id, attemptNumber: 1));

        $afterFirst = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Retrying, $afterFirst->status);
        RetryDelivery::assertPushed(1);
        RetryDelivery::assertPushed(function ($action, array $params, JobDecorator $job, $queue) use ($delivery) {
            return $params === [$delivery->id, 2]
                && $job->delay !== null
                && (int) $job->delay->totalSeconds === (int) config('retry.fixed_interval_seconds');
        });

        // Attempt 2 fails, at the limit of 2 — terminal, exhausted, no further schedule.
        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id, attemptNumber: 2));

        $afterSecond = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $afterSecond->status);
        $this->assertNull($afterSecond->next_attempt_at);
        RetryDelivery::assertPushed(1);
        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
    }
}
