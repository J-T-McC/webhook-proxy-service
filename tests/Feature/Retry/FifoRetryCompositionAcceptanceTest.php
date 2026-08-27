<?php

namespace Tests\Feature\Retry;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Actions\RetryDelivery;
use App\Actions\SweepStalledFifoDispatches;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

/**
 * End-to-end proof of `awaiting_retry` line-holding, settlement, and the
 * stuck-hold release (PRD-06 AC6), over the real `AdvanceProxyFifoQueue` /
 * `RetryDelivery` / `SweepStalledFifoDispatches` chain — complementing
 * T16–T18's unit-level cases (`AdvanceProxyFifoQueueTest`,
 * `FifoRetrySettlementTest`) and proving the existing #4 correctness
 * primitives (claim atomicity, lease reap, idempotent settle — see
 * `FifoLivenessAcceptanceTest`, exercised unmodified in the full suite) are
 * undisturbed.
 */
class FifoRetryCompositionAcceptanceTest extends TestCase
{
    use DrainsQueuedDeliveries;

    /**
     * A FIFO proxy with `$destinations` live destinations and `$pendingCount`
     * pending `fifo_dispatches` rows, ordered evt-1..N (mirroring
     * `FifoLivenessAcceptanceTest`'s fixture shape).
     *
     * @return array{0: Proxy, 1: Collection<int, Destination>, 2: Collection<int, FifoDispatch>}
     */
    private function fifoProxyWithPending(int $pendingCount, int $destinations = 1, array $proxyAttributes = []): array
    {
        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            ...$proxyAttributes,
        ]);
        $destinationModels = Destination::factory()->for($proxy)->count($destinations)->createQuietly();

        $dispatches = collect(range(1, $pendingCount))->map(function (int $i) use ($proxy): FifoDispatch {
            $event = WebhookEvent::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
                'ingest_id' => "evt-{$i}",
            ]);

            return FifoDispatch::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
                'webhook_event_id' => $event->id,
                'dispatch_uuid' => $event->ingest_id,
            ]);
        });

        return [$proxy, $destinationModels, $dispatches];
    }

    public function test_the_heads_first_attempt_failing_holds_the_line_and_the_sweeper_leaves_it_alone(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        [$proxy, , $dispatches] = $this->fifoProxyWithPending(2, proxyAttributes: [
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 3,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        $head = $dispatches[0]->fresh();
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $head->status);
        $this->assertNull($head->claimed_at);
        $this->assertNull($head->lease_expires_at);
        $this->assertSame(
            DeliveryStatus::Retrying,
            Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)->firstOrFail()->status,
        );

        // The next pending event is not claimed — the line is held, and no
        // delivery work has ever been created for it.
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $dispatches[1]->webhook_event_id)->count());

        // The sweeper's reaper only reaps `claimed` rows past their lease — an
        // `awaiting_retry` row has no lease and is structurally invisible to it.
        // Its nudge excludes any proxy with a held row. Both leave the line alone.
        SweepStalledFifoDispatches::run();

        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $head->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_a_successful_retry_settles_the_row_and_the_next_event_is_claimed_in_order(): void
    {
        Queue::fake();
        // A single sequence, not two separate Http::fake() calls: Http::fake()
        // with the array/URL-pattern form APPENDS a new stub rather than
        // replacing the previous one (Factory::stubUrl() -> fake()'s array
        // branch never clears $stubCallbacks), so a later Http::fake(['*' =>
        // ...]) would never actually override an earlier '*' stub within the
        // same test — the first-registered match always wins.
        Http::fakeSequence()->pushStatus(500)->whenEmpty(Http::response('ok', 200));

        [$proxy, , $dispatches] = $this->fifoProxyWithPending(2);

        AdvanceProxyFifoQueue::run($proxy->id); // head claimed, delivery dispatched by reference
        $this->drainQueuedDeliveries(); // attempt 1 drained -> fails -> awaiting_retry

        $head = $dispatches[0]->fresh();
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $head->status);
        $delivery = Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);

        // The retry succeeds this time.
        app(RetryDelivery::class)->handle($delivery->id, 2);

        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Settled, $head->fresh()->status);
        $this->assertNotNull($head->fresh()->settled_at);
        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $params) => $params[0] === $proxy->id);

        // The nudged advancer claims and delivers the second (still pending)
        // event only now — never before the head settled.
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[1]->fresh()->status);
        $secondDelivery = Delivery::query()->where('dispatch_uuid', $dispatches[1]->fresh()->dispatch_uuid)->firstOrFail();
        $this->assertSame(DeliveryStatus::Succeeded, $secondDelivery->status);
    }

    public function test_an_exhausted_retry_settles_the_row_never_dead_letters_and_the_line_advances_past_the_poison_head(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        [$proxy, , $dispatches] = $this->fifoProxyWithPending(2, proxyAttributes: [
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 1,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);

        AdvanceProxyFifoQueue::run($proxy->id); // head claimed, delivery dispatched by reference
        $this->drainQueuedDeliveries(); // its only permitted attempt fails -> terminal

        $head = $dispatches[0]->fresh();
        $delivery = Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)->firstOrFail();
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status, 'The terminal fact lives on `deliveries`.');
        $this->assertSame(FifoDispatchStatus::Settled, $head->fresh()->status);

        // FifoDispatchStatus has no "dead_lettered" (or any poison-state) case at all.
        $this->assertSame(
            ['pending', 'claimed', 'settled', 'awaiting_retry'],
            array_map(fn (FifoDispatchStatus $s) => $s->value, FifoDispatchStatus::cases()),
        );

        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $params) => $params[0] === $proxy->id);

        // The line advances past the poison head to the next pending event.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[1]->fresh()->status);
    }

    public function test_a_multi_destination_head_holds_until_the_last_delivery_is_terminal_then_settles_exactly_once(): void
    {
        Queue::fake();
        // One sequence for the whole test (see the note in the successful-retry
        // test above on why two separate Http::fake() calls would not work):
        // both destinations' attempt 1 fail, then both attempt 2s succeed.
        Http::fakeSequence()->pushStatus(500)->pushStatus(500)->whenEmpty(Http::response('ok', 200));

        [$proxy, $destinations, $dispatches] = $this->fifoProxyWithPending(1, destinations: 2, proxyAttributes: [
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);

        AdvanceProxyFifoQueue::run($proxy->id); // head claimed, both deliveries dispatched by reference
        $this->drainQueuedDeliveries(); // both destinations' attempt 1 fail -> both retrying

        $head = $dispatches[0]->fresh();
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $head->status);
        $deliveryA = Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)
            ->where('destination_id', $destinations[0]->id)->firstOrFail();
        $deliveryB = Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)
            ->where('destination_id', $destinations[1]->id)->firstOrFail();

        // A settles first — B is still retrying, so the line stays held.
        app(RetryDelivery::class)->handle($deliveryA->id, 2);
        $this->assertSame(DeliveryStatus::Succeeded, $deliveryA->fresh()->status);
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $head->fresh()->status);
        AdvanceProxyFifoQueue::assertNotPushed();

        // B settles — now the dispatch has no open deliveries: exactly one
        // settle, exactly one nudge (not one per delivery).
        app(RetryDelivery::class)->handle($deliveryB->id, 2);
        $this->assertSame(DeliveryStatus::Succeeded, $deliveryB->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Settled, $head->fresh()->status);
        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $params) => $params[0] === $proxy->id);
    }

    public function test_a_racing_alternate_settler_leaves_a_later_real_completion_as_a_no_op_cas(): void
    {
        Queue::fake();
        // One sequence for the whole test (see the note in the successful-retry
        // test above): both destinations' attempt 1 fail, then both attempt 2s
        // succeed.
        Http::fakeSequence()->pushStatus(500)->pushStatus(500)->whenEmpty(Http::response('ok', 200));

        [$proxy, $destinations, $dispatches] = $this->fifoProxyWithPending(1, destinations: 2, proxyAttributes: [
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);

        AdvanceProxyFifoQueue::run($proxy->id); // head claimed, both deliveries dispatched by reference
        $this->drainQueuedDeliveries(); // both destinations' attempt 1 fail -> both retrying

        $head = $dispatches[0]->fresh();
        $deliveryA = Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)
            ->where('destination_id', $destinations[0]->id)->firstOrFail();
        $deliveryB = Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)
            ->where('destination_id', $destinations[1]->id)->firstOrFail();

        // A settles for real first.
        app(RetryDelivery::class)->handle($deliveryA->id, 2);
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $head->fresh()->status);

        // An alternate settler (e.g. the sweeper's stuck-hold pass, T18) wins
        // the row's CAS first — simulated directly, mirroring
        // FifoRetrySettlementTest's "another settler already won" fixture shape,
        // but here racing against a REAL in-flight completion (B is still
        // genuinely `retrying`, not yet processed) rather than a synthetic setup.
        FifoDispatch::query()->whereKey($head->id)->update([
            'status' => FifoDispatchStatus::Settled,
            'settled_at' => now(),
        ]);

        // B's own real completion now runs through the actual RetryDelivery/
        // DeliverToDestination path — its own delivery-level settle is
        // unaffected, but the FIFO-row CAS (keyed on `awaiting_retry`) affects
        // zero rows: no double-transition, no double-nudge.
        app(RetryDelivery::class)->handle($deliveryB->id, 2);

        $this->assertSame(DeliveryStatus::Succeeded, $deliveryB->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Settled, $head->fresh()->status);
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_a_stuck_hold_whose_deliveries_are_all_terminal_is_released_and_nudged_by_the_sweep(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $destinations, $dispatches] = $this->fifoProxyWithPending(2);
        $head = $dispatches[0]->fresh();

        // Simulate the crash window: a delivery settled (all terminal) but the
        // fifo row transition it would normally trigger never ran.
        $head->update(['status' => FifoDispatchStatus::AwaitingRetry]);
        Delivery::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destinations[0]->id,
            'webhook_event_id' => $head->webhook_event_id,
            'dispatch_uuid' => $head->dispatch_uuid,
            'kind' => DispatchKind::Original,
            'status' => DeliveryStatus::Succeeded,
        ]);

        SweepStalledFifoDispatches::run();

        $this->assertSame(FifoDispatchStatus::Settled, $head->fresh()->status);
        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $params) => $params[0] === $proxy->id);

        // The nudge advances the line to the next pending event.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[1]->fresh()->status);
    }

    public function test_an_async_proxys_two_events_retries_interleave_freely_and_never_delay_each_other(): void
    {
        // No Queue::fake(): an Async proxy's dispatch()->afterCommit() drains
        // fully inline under the sync queue driver (mirroring
        // RetryEngineAcceptanceTest's documented Async/sync-queue behaviour),
        // so each event's WHOLE retry cascade — attempt 1 through its terminal
        // state — runs to completion within its own ProcessIngestedWebhook::run()
        // call, with no shared line to pace against.
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Async,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $eventA = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $eventB = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        // A runs to its own terminal state first, entirely on its own.
        ProcessIngestedWebhook::run($eventA->ingest_id);

        $this->assertSame(0, FifoDispatch::query()->count(), 'An Async proxy owns no shared ordering line at all.');
        $deliveryA = Delivery::query()->where('webhook_event_id', $eventA->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Failed, $deliveryA->status);
        $attemptCountAfterA = DeliveryAttempt::where('delivery_id', $deliveryA->id)->count();
        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $eventB->id)->count(), "A's cascade never touches B.");

        // B then runs its own full cascade to its own terminal state — never
        // delayed, blocked, or paced by A having gone first.
        ProcessIngestedWebhook::run($eventB->ingest_id);

        $deliveryB = Delivery::query()->where('webhook_event_id', $eventB->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Failed, $deliveryB->status);

        // A is completely unchanged by B's cascade.
        $this->assertSame(DeliveryStatus::Failed, $deliveryA->fresh()->status);
        $this->assertSame($attemptCountAfterA, DeliveryAttempt::where('delivery_id', $deliveryA->id)->count());
    }

    public function test_order_key_capture_order_is_preserved_and_a_replay_row_joins_after_all_pending_events(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $destinations, $dispatches] = $this->fifoProxyWithPending(2);

        // A replay row for a THIRD, already-delivered event, created after the
        // two still-pending captures above (AC11 join-at-back).
        $replayedEvent = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $replayUuid = (string) Str::uuid();
        Delivery::query()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destinations[0]->id,
            'webhook_event_id' => $replayedEvent->id,
            'dispatch_uuid' => $replayUuid,
            'kind' => DispatchKind::Replay,
            'status' => DeliveryStatus::Pending,
        ]);
        $replayRow = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $replayedEvent->id,
            'dispatch_uuid' => $replayUuid,
        ]);

        $this->assertGreaterThan($dispatches[1]->id, $replayRow->id, 'The replay row must be created after both captures.');

        // Drive the whole line and record delivery order via each dispatch's
        // settle: capture-created rows process in received order (evt-1, then
        // evt-2), and the replay row processes only after both.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $replayRow->fresh()->status);

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[1]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $replayRow->fresh()->status, 'The replay row has not been claimed while captures remain.');

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::Settled, $replayRow->fresh()->status);
    }
}
