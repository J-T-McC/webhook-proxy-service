<?php

namespace Tests\Feature\Retry;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Actions\RetryDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Events\DeliveryExhausted;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Services\RetryPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

/**
 * T3 — end-to-end proof that `DeliverToDestination::settleDelivery()` and
 * `DeliveryResource.attempt_limit` inherit the ADR-018 Decision 2 mode gate
 * from `RetryPolicy` with NO code of their own (plan-07 §Architecture A;
 * PRD-07 AC6(b), AC14(a)) — the test that would have caught the defect
 * ADR-018 exists to prevent, plus the mid-flight downgrade/upgrade switch-
 * safety cases the plan's Test Strategy names for this concern.
 */
class ModeGatedRetryInheritanceAcceptanceTest extends TestCase
{
    use DrainsQueuedDeliveries;

    /**
     * A proxy with a fast, deterministic exponential curve so tests can
     * `travel()` seconds rather than hours between attempts. Exponential is
     * the only strategy that can ever apply here — the two proxies under
     * test either hold NO configured strategy (Simple, dormant column) or
     * are proven independently of it — so only the exponential knobs need
     * overriding.
     */
    private function useFastExponentialCurve(): void
    {
        config([
            'retry.exponential_base_seconds' => 1,
            'retry.exponential_multiplier' => 1,
            'retry.exponential_max_delay_seconds' => 5,
            'retry.fixed_interval_seconds' => 1,
        ]);
    }

    private function fifoProxy(array $attributes = []): Proxy
    {
        return Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            ...$attributes,
        ]);
    }

    private function eventFor(Proxy $proxy): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
    }

    /**
     * The headline proof (AC14(a), AC21): a Simple proxy holding a dormant
     * `retry_attempt_limit = 2` is governed by the system default (5), not
     * the column — `DeliverToDestination` resolved through the gate, not the
     * raw column. Also proves `DeliveryResource.attempt_limit` renders 5 for
     * the SAME proxy, with no code of its own.
     */
    public function test_a_simple_proxy_with_a_dormant_limit_column_is_governed_by_the_system_default_everywhere(): void
    {
        $this->useFastExponentialCurve();
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy([
            'mode' => ProxyMode::Simple,
            'retry_attempt_limit' => 2,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        // Attempt 1 (inline, real): fails. If the raw column (2) governed,
        // attempt 2 (>= 2) would already be at the limit; under the gate the
        // system default (5) governs instead, so this schedules attempt 2.
        ProcessIngestedWebhook::run($event->ingest_id);
        $this->drainQueuedDeliveries();
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);

        // Attempt 2 fails too — still under the default limit (5), not the
        // dormant column (2). Drive it to attempt 5, where the SYSTEM DEFAULT
        // terminalizes it.
        foreach ([2, 3, 4] as $attemptNumber) {
            $this->travel(2)->seconds();
            app(RetryDelivery::class)->handle($delivery->id, $attemptNumber);
            $this->assertSame(DeliveryStatus::Retrying, $delivery->fresh()->status, "attempt {$attemptNumber} must still be retrying under the system default.");
        }

        $this->travel(2)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 5);
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status, 'Attempt 5 must terminalize under the system default of 5.');

        // The same proxy's DeliveryResource.attempt_limit renders 5, not the
        // dormant column 2 — the resource inherits the gate with no code of
        // its own.
        $array = (new DeliveryResource($delivery->fresh()->load('destination', 'proxy')))->resolve(request());
        $this->assertSame(5, $array['attempt_limit']);
    }

    /**
     * Mid-flight downgrade (AC10, AC13, AC17; plan §Architecture E): an
     * Enhanced FIFO proxy holding limit 8, three attempts already made,
     * downgraded to Simple mid-schedule. The next failure terminalizes
     * IMMEDIATELY under the (now lower) system default — proving the `>=`
     * comparison, not `==` — emits `DeliveryExhausted` exactly once, and
     * releases the held FIFO line.
     */
    public function test_a_mid_flight_downgrade_terminalizes_immediately_and_releases_the_fifo_line(): void
    {
        $this->useFastExponentialCurve();
        // Lowered only so the test does not need to drive 8 real attempts to
        // prove the point — the mechanism (>= the CURRENTLY resolved limit,
        // re-evaluated live every attempt) is what is under test, not the
        // specific numbers.
        config(['retry.default_attempt_limit' => 3]);
        Event::fake();
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 8,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $dispatch = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $event->ingest_id,
        ]);

        // Attempt 1 (via the advancer, inline, real): fails, well under limit
        // 8 — the line holds (awaiting_retry).
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $delivery = Delivery::query()->where('dispatch_uuid', $dispatch->dispatch_uuid)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $dispatch->fresh()->status);

        // Attempts 2 and 3 fail too — still well under limit 8.
        foreach ([2, 3] as $attemptNumber) {
            $this->travel(2)->seconds();
            app(RetryDelivery::class)->handle($delivery->id, $attemptNumber);
            $this->assertSame(DeliveryStatus::Retrying, $delivery->fresh()->status);
        }

        // Downgrade mid-schedule. Nothing about the delivery/FIFO row is
        // touched by the switch itself (ADR-018 Decision 5 — live-read only).
        $proxy->update(['mode' => ProxyMode::Simple]);

        // Attempt 4: under the OLD Enhanced limit (8) this would still
        // retry; under the mode-gated resolver the proxy is now Simple, so
        // the resolved limit is the (lowered, for this test) system default
        // of 3 — attempt 4 (>= 3) terminalizes immediately.
        $this->travel(2)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 4);

        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);

        // The held FIFO line is released: settled (never a second eraser/
        // dead-letter state) and the advancer nudged to resume.
        $this->assertSame(FifoDispatchStatus::Settled, $dispatch->fresh()->status);
        $this->assertNotNull($dispatch->fresh()->settled_at);
        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $params) => $params[0] === $proxy->id);
    }

    /**
     * Mid-flight upgrade (AC9, AC18): a Simple proxy at attempt 4 (governed
     * by a lowered system default) is upgraded to Enhanced with a higher
     * limit before the next failure — the schedule EXTENDS rather than
     * terminalizing at the old (Simple) boundary, continuing to the new,
     * higher configured limit. `RetryPolicy::worstCaseSpan()`'s clamp bound
     * is unaffected by any number of switches (it is proxy-free by
     * construction — ADR-015 Decision 4/AC18).
     */
    public function test_a_mid_flight_upgrade_extends_the_schedule_to_the_new_configured_limit(): void
    {
        $this->useFastExponentialCurve();
        // Lowered only so the test does not need to drive many real attempts
        // — see the downgrade test's identical note.
        config(['retry.default_attempt_limit' => 2]);
        Event::fake();
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy(['mode' => ProxyMode::Simple]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        // Attempt 1 (inline, real): fails, under the (lowered) system
        // default of 2.
        ProcessIngestedWebhook::run($event->ingest_id);
        $this->drainQueuedDeliveries();
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);

        // Upgrade BEFORE attempt 2 would have terminalized it under the old
        // Simple/default-2 boundary.
        $proxy->update([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 4,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);

        // Attempt 2: under the OLD resolved limit (2) this would terminalize
        // (2 >= 2); under the new Enhanced limit (4) it continues.
        $this->travel(2)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 2);
        $this->assertSame(DeliveryStatus::Retrying, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->next_attempt_at);

        // Attempt 3: still under 4.
        $this->travel(2)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 3);
        $this->assertSame(DeliveryStatus::Retrying, $delivery->fresh()->status);

        // Attempt 4: now at the NEW configured limit — terminalizes, exactly
        // once.
        $this->travel(2)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 4);
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
        $this->assertSame(4, DeliveryAttempt::where('delivery_id', $delivery->id)->count());

        // worstCaseSpan() is proxy-free (ADR-015 Decision 4) — unaffected by
        // any number of mode/limit switches, including the ones just driven.
        $before = (new RetryPolicy)->worstCaseSpan()->totalSeconds;
        $proxy->update(['mode' => ProxyMode::Simple]);
        $proxy->update(['mode' => ProxyMode::Enhanced, 'retry_attempt_limit' => 10]);
        $after = (new RetryPolicy)->worstCaseSpan()->totalSeconds;
        $this->assertSame($before, $after);
    }
}
