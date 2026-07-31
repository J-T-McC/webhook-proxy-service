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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverToDestinationTest extends TestCase
{
    use RefreshDatabase;

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

        $destination = Destination::factory()->create(['http_method' => HttpMethod::Post]);

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

        $destination = Destination::factory()->create();

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

        $destination = Destination::factory()->create();
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

        $destination = Destination::factory()->create();

        DeliverToDestination::run($this->unit($destination));

        // Still exactly one attempt row (updated in place, not a second insert).
        $this->assertSame(1, DeliveryAttempt::count());
    }
}
