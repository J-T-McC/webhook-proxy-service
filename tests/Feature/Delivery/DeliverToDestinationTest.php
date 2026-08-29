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
use App\Models\WebhookEvent;
use App\Pipeline\DeliveryUnit;
use App\Services\RetryPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        // T14: a failure now schedules a real `RetryDelivery`. Fake the queue so
        // only attempt 1's own redelivery idempotency is exercised here — retry
        // cascading is covered by RetryDeliveryTest/DeliverToDestinationTest's
        // settle-CAS tests below.
        Queue::fake();
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

    public function test_a_failed_attempt_settles_instead_of_throwing_when_the_proxy_has_been_soft_deleted(): void
    {
        // Regression: `$delivery->proxy` (the default, trashed-exclusive relation)
        // resolves to null once the proxy is soft-deleted, and RetryPolicy::
        // attemptLimitFor() takes a non-nullable Proxy — settleDelivery() threw a
        // TypeError from inside the queue worker before the fix.
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->enhanced()->createQuietly(['retry_attempt_limit' => 2]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $delivery = $this->deliveryFor($destination);
        $proxy->delete();

        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id, attemptNumber: 1));

        // Below the soft-deleted proxy's own limit of 2 — settles to retrying,
        // per that proxy's original policy, not thrown/lost.
        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Retrying, $fresh->status);
        RetryDelivery::assertPushed(1);
        Event::assertNotDispatched(DeliveryExhausted::class);
    }

    public function test_a_failed_attempt_at_the_limit_exhausts_instead_of_throwing_when_the_proxy_has_been_soft_deleted(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->enhanced()->createQuietly(['retry_attempt_limit' => 2]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $delivery = $this->deliveryFor($destination);
        $proxy->delete();

        DeliverToDestination::run($this->unit($destination, deliveryId: $delivery->id, attemptNumber: 2));

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertNull($fresh->next_attempt_at);
        RetryDelivery::assertNotPushed();
        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
    }

    // --- ADR-020 Decision 7: the by-reference `asJob()` entry point ---------

    public function test_as_job_resolves_by_reference_and_delivers_exactly_like_run(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly(['http_method' => HttpMethod::Post]);
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
            'body' => '{"a":1}',
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);

        app(DeliverToDestination::class)->asJob($delivery->id, 1);

        $this->assertSame(1, DeliveryAttempt::count());
        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Succeeded, $attempt->status);
        Http::assertSent(fn ($r) => $r->body() === '{"a":1}');
        Event::assertDispatched(DeliverySucceeded::class);
    }

    /**
     * The cleaned-parent branch, newly reachable on attempt 1 (ADR-020 Decision
     * 7) — terminalizes per `RetryDelivery::terminalizeCleaned()`'s semantics:
     * compare-and-set the delivery to `failed` (keyed on `pending`, correct for
     * attempt 1), emit `DeliveryExhausted` iff the CAS affected a row, log
     * `payload.expired` with identifiers only, and make no attempt at all —
     * zero HTTP sends, zero `delivery_attempts` rows (PRD-06 AC17's posture).
     */
    public function test_as_job_on_a_cleaned_parent_terminalizes_without_sending_or_writing_an_attempt(): void
    {
        Event::fake();
        Http::fake();
        Log::spy();

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);

        app(DeliverToDestination::class)->asJob($delivery->id, 1);

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

    // --- T28: send() composes the outbound header set through OutboundHeaders ---

    /**
     * AC17, AC30, AC32 — the credential is present on attempt 1, on a retry
     * (same delivery, attempt 2), and on a replay (a fresh delivery, attempt
     * 1 again), and absent on another destination of the same proxy that has
     * no credential of its own. `credential_secret`/`credential_header_name`
     * are set by direct attribute assignment rather than mass assignment —
     * `Destination`'s `#[Fillable]` list gains `credential_secret` at T29,
     * one task after this one; a direct property set + `save()` bypasses the
     * mass-assignment guard entirely and proves nothing about T29's own
     * persistence path, which is exercised separately by its own tests.
     */
    public function test_the_credential_is_present_on_attempt_1_a_retry_and_a_replay_and_absent_on_an_uncredentialed_destination(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $credentialed = Destination::factory()->createQuietly();
        $credentialed->credential_header_name = 'X-Api-Key';
        $credentialed->credential_secret = 'secret-value';
        $credentialed->credential_set_at = now();
        $credentialed->save();

        $uncredentialed = Destination::factory()->createQuietly([
            'proxy_id' => $credentialed->proxy_id,
            'team_id' => $credentialed->team_id,
        ]);

        $delivery = $this->deliveryFor($credentialed);
        DeliverToDestination::run($this->unit($credentialed, deliveryId: $delivery->id, attemptNumber: 1));
        DeliverToDestination::run($this->unit($credentialed, deliveryId: $delivery->id, attemptNumber: 2));

        $replayDelivery = $this->deliveryFor($credentialed);
        DeliverToDestination::run($this->unit($credentialed, deliveryId: $replayDelivery->id, attemptNumber: 1));

        $uncredentialedDelivery = $this->deliveryFor($uncredentialed);
        DeliverToDestination::run($this->unit($uncredentialed, deliveryId: $uncredentialedDelivery->id, attemptNumber: 1));

        $recorded = Http::recorded();
        $this->assertCount(4, $recorded);

        [$attempt1] = $recorded[0];
        [$retry] = $recorded[1];
        [$replay] = $recorded[2];
        [$noCredential] = $recorded[3];

        $this->assertTrue($attempt1->hasHeader('X-Api-Key', 'secret-value'));
        $this->assertTrue($retry->hasHeader('X-Api-Key', 'secret-value'));
        $this->assertTrue($replay->hasHeader('X-Api-Key', 'secret-value'));
        $this->assertFalse($noCredential->hasHeader('X-Api-Key'));
    }

    /**
     * The request body is unchanged by this task — composes with T26's AC37
     * regression: an uncredentialed destination's dispatched bytes are
     * identical to before this task.
     */
    public function test_the_request_body_is_unchanged_for_an_uncredentialed_destination(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();

        DeliverToDestination::run($this->unit($destination, payload: '{"exact":"bytes"}'));

        Http::assertSent(fn ($request): bool => $request->body() === '{"exact":"bytes"}');
    }

    /**
     * T55 (ADR-026 Decision A) — the credential collision is now the
     * ordinary case. A destination carrying its own credential under the
     * default `Authorization` header name still receives that credential,
     * never the sender's — ADR-023 Decision 2's existing precedence rule
     * (added headers always win, matched case-insensitively) resolves it
     * unmodified. `Cookie` and the provider-signature header are no longer
     * in `DeliveryUnit::STRIPPED_HEADERS` and forward unchanged alongside it.
     */
    public function test_the_destinations_own_credential_wins_over_a_same_named_forwarded_header_while_cookie_and_provider_signature_forward_unchanged(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $destination->credential_header_name = 'Authorization';
        $destination->credential_secret = 'destination-secret';
        $destination->credential_set_at = now();
        $destination->save();

        $delivery = $this->deliveryFor($destination);
        $unit = new DeliveryUnit(
            ingestId: (string) Str::uuid(),
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: [
                'Content-Type' => ['application/json'],
                'Authorization' => ['Bearer sender-token'],
                'Cookie' => ['session=abc'],
                'Stripe-Signature' => ['t=1,v1=abc'],
            ],
            payload: '{"a":1}',
            deliveryId: $delivery->id,
            attemptNumber: 1,
        );

        DeliverToDestination::run($unit);

        [$request] = Http::recorded()[0];

        $this->assertTrue($request->hasHeader('Authorization', 'destination-secret'));
        $this->assertCount(1, $request->header('Authorization'));
        $this->assertTrue($request->hasHeader('Cookie', 'session=abc'));
        $this->assertTrue($request->hasHeader('Stripe-Signature', 't=1,v1=abc'));
    }

    // --- Delivery-loop guard (docs/briefs/delivery-loop-guard.md) ----------

    /**
     * The send-time backstop: a row saved before
     * `NotSelfReferencingDestinationUrl` existed, or whose host became
     * self-referential after an `INGEST_URL` change since save, is caught
     * here instead of ever reaching the network. `createQuietly()`
     * deliberately bypasses `StoreProxyRequest`'s save-time rule to set up
     * exactly that "already saved, now self-referential" state.
     */
    public function test_send_time_backstop_fails_the_attempt_when_the_destination_host_matches_the_ingest_host(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake();

        $ingestHost = parse_url((string) config('ingest.url'), PHP_URL_HOST);
        $destination = Destination::factory()->createQuietly([
            'url' => "https://{$ingestHost}/ingest/some-token",
        ]);

        DeliverToDestination::run($this->unit($destination));

        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Failed, $attempt->status);
        $this->assertNull($attempt->http_status);
        $this->assertNotNull($attempt->error_summary);
        $this->assertStringContainsString('ingest host', (string) $attempt->error_summary);

        Http::assertNothingSent();
        Event::assertDispatched(DeliveryFailed::class);
        Event::assertNotDispatched(DeliverySucceeded::class);
    }

    /**
     * `Http::withoutRedirecting()` stops a real destination's 3xx from being
     * chased by Guzzle's default redirect-following — the option a real
     * client honors. `Http::fake()` returns exactly the faked response and
     * never invokes Guzzle's redirect middleware regardless of that option,
     * so this proves the outcome that matters at this layer: a 3xx is
     * handled by the existing `$response->successful()` path like any other
     * non-2xx — settled as an ordinary failed attempt, never specially
     * followed or swallowed.
     */
    public function test_a_3xx_response_settles_as_a_failed_attempt_rather_than_being_treated_as_success(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://example.com/elsewhere'])]);

        $destination = Destination::factory()->createQuietly();

        DeliverToDestination::run($this->unit($destination));

        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Failed, $attempt->status);
        $this->assertSame(302, $attempt->http_status);

        Http::assertSentCount(1);
        Event::assertDispatched(DeliveryFailed::class);
        Event::assertNotDispatched(DeliverySucceeded::class);
    }
}
