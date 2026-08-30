<?php

namespace Tests\Feature\Retry;

use App\Actions\ProcessIngestedWebhook;
use App\Actions\RetryDelivery;
use App\Actions\SweepDueRetries;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryExhausted;
use App\Events\DeliveryFailed;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

/**
 * End-to-end proof of the automatic-retry engine (PRD-06 AC1–AC3, AC7), over
 * the real wired seams (T10–T15): `ProcessIngestedWebhook` → `DeliverStep` →
 * `DeliverToDestination` → `RetryDelivery`/`SweepDueRetries`. Complements the
 * unit-level cases already embedded in T11–T15.
 *
 * `processing_mode` (ADR-011) and `mode` (ADR-002, simple/enhanced retry
 * gating) are orthogonal axes; using Fifo here (a holdover from before
 * ADR-020) says nothing about #4 FIFO ordering, which is T40's concern. Since
 * ADR-020, every delivery — Async and FIFO alike — is dispatched by reference
 * onto the webhooks queue (ADR-020 Decision 1), so `Queue::fake()` also
 * captures attempt 1 rather than letting it run inline; `drainQueuedDeliveries()`
 * (`Tests\Concerns\DrainsQueuedDeliveries`) runs it in place immediately after
 * the triggering call, before freezing the *next* scheduled attempt for
 * inspection — mirroring `RetryDeliveryTest`'s direct-invocation pattern one
 * layer up.
 */
class RetryEngineTest extends TestCase
{
    use DrainsQueuedDeliveries;

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

    public function test_a_failed_attempt_on_a_simple_mode_proxy_schedules_attempt_2_under_the_system_default(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy(['mode' => ProxyMode::Simple]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        ProcessIngestedWebhook::run($event->ingest_id);
        $this->drainQueuedDeliveries();

        $this->assertSame(1, DeliveryAttempt::count());
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
        // System default: attempt limit 5, exponential first delay (config default 60s).
        $this->assertEqualsWithDelta(
            (int) config('retry.exponential_base_seconds'),
            now()->diffInSeconds($delivery->next_attempt_at),
            2,
        );

        RetryDelivery::assertPushed(function ($action, array $params, $job) use ($delivery): bool {
            return $params[0] === $delivery->id
                && $params[1] === 2
                && abs($job->delay->totalSeconds - (int) config('retry.exponential_base_seconds')) < 2;
        });

        $this->assertNotNull($destination->url);
    }

    public function test_an_enhanced_proxy_with_limit_2_and_fixed_stops_after_attempt_2_with_constant_delays(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        // Attempt 1 (inline, real): fails, schedules attempt 2 with the fixed delay.
        ProcessIngestedWebhook::run($event->ingest_id);
        $this->drainQueuedDeliveries();
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);

        $fixedInterval = (int) config('retry.fixed_interval_seconds');
        RetryDelivery::assertPushed(fn ($action, array $params, $job) => $params[0] === $delivery->id
            && $params[1] === 2
            && (int) round($job->delay->totalSeconds) === $fixedInterval);

        // Attempt 2 (direct invocation, real): fails at the limit — terminalizes,
        // no further attempt is ever scheduled.
        app(RetryDelivery::class)->handle($delivery->id, 2);

        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->next_attempt_at);
        // Only the one attempt-2 schedule was ever pushed — no attempt 3.
        RetryDelivery::assertPushed(1, fn ($action, array $params) => $params[0] === $delivery->id);
    }

    public function test_an_enhanced_proxy_with_unset_columns_falls_back_to_5_and_exponential(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy(['mode' => ProxyMode::Enhanced]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        ProcessIngestedWebhook::run($event->ingest_id);
        $this->drainQueuedDeliveries();

        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertEqualsWithDelta(
            (int) config('retry.exponential_base_seconds'),
            now()->diffInSeconds($delivery->next_attempt_at),
            2,
        );
    }

    public function test_two_destinations_one_fails_only_the_failed_one_is_retried(): void
    {
        Http::fake([
            'boom.test/*' => Http::response('nope', 500),
            '*' => Http::response('ok', 200),
        ]);

        $proxy = Proxy::factory()->createQuietly([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        $failing = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://boom.test/hook']);
        $succeeding = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        // Async proxy, sync queue: the cascade drains fully inline.
        ProcessIngestedWebhook::run($event->ingest_id);

        $failedDelivery = Delivery::query()->where('destination_id', $failing->id)->firstOrFail();
        $succeededDelivery = Delivery::query()->where('destination_id', $succeeding->id)->firstOrFail();

        $this->assertSame(DeliveryStatus::Failed, $failedDelivery->status);
        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $failedDelivery->id)->count());

        $this->assertSame(DeliveryStatus::Succeeded, $succeededDelivery->status);
        $this->assertSame(1, DeliveryAttempt::where('delivery_id', $succeededDelivery->id)->count());
    }

    public function test_each_retry_writes_a_new_payload_free_attempt_row_and_fires_the_existing_events(): void
    {
        // Queue::fake() freezes each scheduled next attempt for controlled,
        // travel()-paced manual invocation (mirroring a real queue's actual
        // wait) instead of the sync-queue driver's zero-delay inline cascade,
        // which would compute successive `next_attempt_at`s only milliseconds
        // apart under a fixed strategy — indistinguishable at column precision.
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 3,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        ProcessIngestedWebhook::run($event->ingest_id); // attempt 1 — inline, real
        $this->drainQueuedDeliveries();
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();

        $fixedInterval = (int) config('retry.fixed_interval_seconds');
        $this->travel($fixedInterval + 5)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 2); // attempt 2

        $this->travel($fixedInterval + 5)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 3); // attempt 3 — terminalizes at limit 3

        $attempts = DeliveryAttempt::where('delivery_id', $delivery->id)->orderBy('attempt_number')->get();

        $this->assertSame([1, 2, 3], $attempts->pluck('attempt_number')->all());
        $this->assertTrue($attempts->every(fn (DeliveryAttempt $a) => $a->delivery_id === $delivery->id
            && $a->status === AttemptStatus::Failed));
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);

        // Payload-free by construction (ADR-003): no body/payload column exists.
        $columns = array_map('strtolower', Schema::getColumnListing('delivery_attempts'));
        foreach (['payload', 'body', 'request_body'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        Event::assertDispatchedTimes(DeliveryAttempted::class, 3);
        Event::assertDispatchedTimes(DeliveryFailed::class, 3);
        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
    }

    public function test_a_duplicate_retry_delivery_execution_produces_exactly_one_attempt_row(): void
    {
        // Freezes the cascade after attempt 2 (system default limit 5 would
        // otherwise keep going inline under the sync queue driver) so the
        // dedupe assertion below isn't polluted by further attempts.
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subMinute(),
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'delivery_id' => $delivery->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
        ]);

        // The sweeper and this delivery's still-live delayed job both fire for
        // the same (delivery_id, attempt_number) — the unique-key dedupe (#4
        // AC9 parity) must leave exactly one attempt-2 row.
        app(RetryDelivery::class)->handle($delivery->id, 2);
        app(RetryDelivery::class)->handle($delivery->id, 2);

        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
        $this->assertSame(1, DeliveryAttempt::where('delivery_id', $delivery->id)->where('attempt_number', 2)->count());
    }

    public function test_sweep_due_retries_re_drives_an_overdue_delivery_whose_delayed_job_was_lost(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
            // Overdue well beyond the sweep grace period — the delayed job was lost.
            'next_attempt_at' => now()->subSeconds((int) config('retry.sweep_grace_seconds') + 60),
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'delivery_id' => $delivery->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
        ]);

        SweepDueRetries::run();

        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
    }

    public function test_lowering_the_attempt_limit_mid_flight_terminalizes_at_the_next_failure(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 5,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        $fixedInterval = (int) config('retry.fixed_interval_seconds');

        ProcessIngestedWebhook::run($event->ingest_id); // attempt 1 — retrying
        $this->drainQueuedDeliveries();
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->travel($fixedInterval + 5)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 2); // attempt 2 — still under limit 5, retrying
        $this->assertSame(DeliveryStatus::Retrying, $delivery->fresh()->status);

        // Owner lowers the limit below the attempts already executed.
        $proxy->update(['retry_attempt_limit' => 2]);

        $this->travel($fixedInterval + 5)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 3); // attempt 3 — now over the lowered limit

        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertSame(3, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
    }

    public function test_raising_the_attempt_limit_mid_flight_extends_the_schedule(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = $this->fifoProxy([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        ProcessIngestedWebhook::run($event->ingest_id); // attempt 1 — retrying (1 < 2)
        $this->drainQueuedDeliveries();
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $originalNextAttemptAt = $delivery->fresh()->next_attempt_at;

        // Owner raises the limit before attempt 2 would have terminalized it.
        $proxy->update(['retry_attempt_limit' => 5]);

        $this->travel((int) config('retry.fixed_interval_seconds') + 5)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 2); // attempt 2 — would have terminalized at the old limit

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Retrying, $fresh->status);
        $this->assertNotNull($fresh->next_attempt_at);
        $this->assertTrue($fresh->next_attempt_at->gt($originalNextAttemptAt));
        RetryDelivery::assertPushed(fn ($action, array $params) => $params[0] === $delivery->id && $params[1] === 3);
    }

    public function test_a_soft_deleted_destination_mid_schedule_still_executes_and_settles_its_retry(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subMinute(),
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'delivery_id' => $delivery->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
        ]);

        $destination->delete();

        app(RetryDelivery::class)->handle($delivery->id, 2);

        Http::assertSent(fn ($r) => $r->url() === $destination->url);
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
    }
}
