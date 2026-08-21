<?php

namespace Tests\Feature\Retry;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\DeliverToDestination;
use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\DeliveryUnit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The FIFO `awaiting_retry → settled` completion check T17 adds to
 * `DeliverToDestination` (ADR-016 Decision 1) — proven against a delivery
 * settling OUTSIDE the advancer's own post-run check (e.g. a `RetryDelivery`
 * execution), complementing `AdvanceProxyFifoQueueTest`'s advancer-side
 * settle-or-hold coverage.
 */
class FifoRetrySettlementTest extends TestCase
{
    /**
     * A held (`awaiting_retry`) `fifo_dispatches` row for `$proxy`, identifying
     * the dispatch `$dispatchUuid` — the state a `RetryDelivery` execution would
     * find mid-schedule.
     */
    private function heldFifoDispatch(Proxy $proxy, string $dispatchUuid): FifoDispatch
    {
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        return FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $dispatchUuid,
            'status' => FifoDispatchStatus::AwaitingRetry,
        ]);
    }

    /**
     * A `deliveries` row for `$destination`, under the given dispatch.
     */
    private function deliveryFor(
        Destination $destination,
        string $dispatchUuid,
        DeliveryStatus $status,
    ): Delivery {
        return Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'dispatch_uuid' => $dispatchUuid,
            'status' => $status,
            'next_attempt_at' => $status === DeliveryStatus::Retrying ? now()->subMinute() : null,
        ]);
    }

    private function unitFor(Delivery $delivery, Destination $destination, int $attemptNumber): DeliveryUnit
    {
        return new DeliveryUnit(
            ingestId: (string) Str::uuid(),
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: ['Content-Type' => ['application/json']],
            payload: '{"a":1}',
            deliveryId: $delivery->id,
            attemptNumber: $attemptNumber,
        );
    }

    public function test_settling_the_last_open_delivery_settles_the_line_and_nudges_the_advancer(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destinationA = Destination::factory()->for($proxy)->createQuietly();
        $destinationB = Destination::factory()->for($proxy)->createQuietly();
        $dispatchUuid = (string) Str::uuid();

        $fifoRow = $this->heldFifoDispatch($proxy, $dispatchUuid);

        // A already settled; B is the last open delivery of this dispatch.
        $this->deliveryFor($destinationA, $dispatchUuid, DeliveryStatus::Succeeded);
        $deliveryB = $this->deliveryFor($destinationB, $dispatchUuid, DeliveryStatus::Retrying);

        DeliverToDestination::run($this->unitFor($deliveryB, $destinationB, 2));

        $this->assertSame(DeliveryStatus::Succeeded, $deliveryB->fresh()->status);

        $freshRow = $fifoRow->fresh();
        $this->assertSame(FifoDispatchStatus::Settled, $freshRow->status);
        $this->assertNotNull($freshRow->settled_at);
        AdvanceProxyFifoQueue::assertPushed(fn ($job, array $params) => $params[0] === $proxy->id);
    }

    public function test_no_transition_while_a_sibling_delivery_remains_non_terminal(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destinationA = Destination::factory()->for($proxy)->createQuietly();
        $destinationB = Destination::factory()->for($proxy)->createQuietly();
        $dispatchUuid = (string) Str::uuid();

        $fifoRow = $this->heldFifoDispatch($proxy, $dispatchUuid);

        // A settles now; B is still mid-schedule (retrying) — the dispatch is NOT
        // yet fully terminal, so the fifo row must stay held.
        $deliveryA = $this->deliveryFor($destinationA, $dispatchUuid, DeliveryStatus::Retrying);
        $this->deliveryFor($destinationB, $dispatchUuid, DeliveryStatus::Retrying);

        DeliverToDestination::run($this->unitFor($deliveryA, $destinationA, 2));

        $this->assertSame(DeliveryStatus::Succeeded, $deliveryA->fresh()->status);
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $fifoRow->fresh()->status);
        $this->assertNull($fifoRow->fresh()->settled_at);
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_an_async_proxy_has_no_fifo_dispatches_row_to_transition(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        // An Async proxy never owns a fifo_dispatches row for its dispatches at
        // all — the completion check is a structural no-op (ADR-016 Decision 1).
        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $dispatchUuid = (string) Str::uuid();

        $delivery = $this->deliveryFor($destination, $dispatchUuid, DeliveryStatus::Retrying);

        DeliverToDestination::run($this->unitFor($delivery, $destination, 2));

        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
        $this->assertSame(0, FifoDispatch::query()->count());
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_a_racing_duplicate_settle_cases_the_fifo_row_at_most_once(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $dispatchUuid = (string) Str::uuid();

        // Another settler already won the fifo-row CAS for this dispatch (e.g. a
        // sibling delivery's own completion check, or the sweeper's stuck-hold
        // pass) before this delivery's own settle runs.
        $fifoRow = $this->heldFifoDispatch($proxy, $dispatchUuid);
        $fifoRow->update(['status' => FifoDispatchStatus::Settled, 'settled_at' => now()->subSecond()]);

        $delivery = $this->deliveryFor($destination, $dispatchUuid, DeliveryStatus::Retrying);

        DeliverToDestination::run($this->unitFor($delivery, $destination, 2));

        // The delivery itself settles normally...
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
        // ...but the fifo row's CAS is keyed on `awaiting_retry` — already
        // `settled`, so it affects zero rows: no double-transition, no
        // double-nudge.
        $this->assertSame(FifoDispatchStatus::Settled, $fifoRow->fresh()->status);
        AdvanceProxyFifoQueue::assertNotPushed();
    }
}
