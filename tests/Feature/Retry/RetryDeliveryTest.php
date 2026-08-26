<?php

namespace Tests\Feature\Retry;

use App\Actions\RetryDelivery;
use App\Enums\DeliveryStatus;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RetryDeliveryTest extends TestCase
{
    /**
     * A `retrying` delivery targeting `$destination`, dispatching `$event` —
     * exactly the state a real delayed `RetryDelivery` job would find.
     */
    private function retryingDelivery(Destination $destination, WebhookEvent $event): Delivery
    {
        return Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subMinute(),
        ]);
    }

    /**
     * Invoke the job body directly — `RetryDelivery` only carries `AsJob`
     * (T14's Description; no `::run()` static helper), so a container-resolved
     * `handle()` call is the direct-invocation seam, mirroring the parity
     * `DeliverToDestination` already proved between `::run` and `::dispatch`
     * container resolution.
     */
    private function invoke(int $deliveryId, int $attemptNumber): void
    {
        app(RetryDelivery::class)->handle($deliveryId, $attemptNumber);
    }

    public function test_resends_the_raw_capture_when_the_dispatched_output_has_not_diverged(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
            'body' => '{"raw":true}',
        ]);
        $delivery = $this->retryingDelivery($destination, $event);

        $this->invoke($delivery->id, 2);

        Http::assertSent(fn ($r) => $r->url() === $destination->url && $r->body() === '{"raw":true}');
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
    }

    public function test_resends_the_recorded_dispatched_output_when_it_diverged(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
            'body' => '{"raw":true}',
        ]);
        DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $event->team_id,
            'proxy_id' => $event->proxy_id,
            'body' => '{"mapped":true}',
        ]);
        $delivery = $this->retryingDelivery($destination, $event);

        $this->invoke($delivery->id, 2);

        Http::assertSent(fn ($r) => $r->body() === '{"mapped":true}');
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
    }

    public function test_a_stale_delivery_no_longer_retrying_sends_nothing_and_creates_no_attempt(): void
    {
        Http::fake();

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = $this->retryingDelivery($destination, $event);
        // Simulate another settler (e.g. the same delivery's live delayed job)
        // having already won before this (superseded/duplicate) job ran — the
        // sweeper/delayed-job race T14's Description names.
        Delivery::query()->whereKey($delivery->id)->update(['status' => DeliveryStatus::Succeeded]);

        $this->invoke($delivery->id, 2);

        Http::assertNothingSent();
        $this->assertSame(0, DeliveryAttempt::count());
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
    }

    public function test_a_cleaned_parent_terminalizes_the_delivery_and_exhausts_without_sending(): void
    {
        Event::fake();
        Http::fake();
        Log::spy();

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = $this->retryingDelivery($destination, $event);

        $this->invoke($delivery->id, 2);

        Http::assertNothingSent();
        $this->assertSame(0, DeliveryAttempt::count());

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertNull($fresh->next_attempt_at);

        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
        Event::assertDispatched(
            DeliveryExhausted::class,
            fn (DeliveryExhausted $e) => $e->delivery->id === $delivery->id,
        );

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => $message === 'payload.expired'
                && $context === ['ingest_id' => $event->ingest_id],
        );
    }

    public function test_a_successful_retry_writes_a_new_attempt_row_with_the_correct_delivery_id_and_incremented_attempt_number(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = $this->retryingDelivery($destination, $event);
        // A prior attempt-1 row already exists (the original, non-retry attempt).
        DeliveryAttempt::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'delivery_id' => $delivery->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
        ]);

        $this->invoke($delivery->id, 3);

        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
        $this->assertNotNull(
            DeliveryAttempt::where('delivery_id', $delivery->id)->where('attempt_number', 3)->first(),
        );
    }
}
