<?php

namespace Tests\Unit\Actions;

use App\Actions\DeliverToDestination;
use App\Actions\RetryDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\DestinationValidationState;
use App\Enums\ProxyMode;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\DeliveryUnit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T45 — a dedicated CAS transition-matrix test exercising every `(from, to)`
 * `DeliveryStatus` pair `DeliverToDestination::transition()` and
 * `RetryDelivery::terminalizeCleaned()` can attempt (plan §Test strategy
 * "Unit"), including the invalid/no-op ones — gap-filling beyond the
 * scattered single-transition cases already embedded in
 * `DeliverToDestinationTest`/`RetryDeliveryTest`/`RetryEngineTest`.
 *
 * `DeliverToDestination::transition()` is a compare-and-set keyed on the
 * PRIOR status being `pending` or `retrying` (ADR-015 Decisions 5/6): from
 * either of those two, every outcome (success, fail-below-limit,
 * fail-at-limit) is honored. From an already-terminal status (`succeeded` or
 * `failed`) — simulating a settler that already won a race — every attempted
 * outcome is a structural no-op: the CAS affects zero rows, the delivery's
 * status is left exactly as it was, and no event/schedule fires.
 */
class DeliveryStatusTransitionTest extends TestCase
{
    private function deliveryWithStatus(DeliveryStatus $status): Delivery
    {
        $destination = Destination::factory()->createQuietly();

        return Delivery::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'status' => $status,
        ]);
    }

    private function unitFor(Delivery $delivery, int $attemptNumber): DeliveryUnit
    {
        $destination = $delivery->destination;

        return new DeliveryUnit(
            ingestId: (string) Str::uuid(),
            teamId: $delivery->team_id,
            proxyId: $delivery->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: ['Content-Type' => ['application/json']],
            payload: '{"a":1}',
            deliveryId: $delivery->id,
            attemptNumber: $attemptNumber,
        );
    }

    /**
     * Every `(from, outcome)` pair `DeliverToDestination::transition()` can
     * be asked to attempt. `fromHonored` marks the two non-terminal starting
     * statuses (`pending`/`retrying`), for which every outcome is honored;
     * the two terminal starting statuses (`succeeded`/`failed`) are always
     * rejected (no-op), regardless of outcome — the CAS's `whereIn`
     * `[pending, retrying]` guard structurally excludes them.
     *
     * @return array<string, array{0: DeliveryStatus, 1: int, 2: int, 3: bool, 4: DeliveryStatus, 5: bool}>
     */
    public static function transitions(): array
    {
        return [
            // --- honored: from pending -----------------------------------
            'pending + success -> succeeded' => [DeliveryStatus::Pending, 1, 5, true, DeliveryStatus::Succeeded, true],
            'pending + fail below limit -> retrying' => [DeliveryStatus::Pending, 1, 3, false, DeliveryStatus::Retrying, true],
            'pending + fail at limit -> failed' => [DeliveryStatus::Pending, 1, 1, false, DeliveryStatus::Failed, true],

            // --- honored: from retrying -----------------------------------
            'retrying + success -> succeeded' => [DeliveryStatus::Retrying, 2, 5, true, DeliveryStatus::Succeeded, true],
            'retrying + fail below limit -> retrying' => [DeliveryStatus::Retrying, 2, 3, false, DeliveryStatus::Retrying, true],
            'retrying + fail at limit -> failed' => [DeliveryStatus::Retrying, 3, 3, false, DeliveryStatus::Failed, true],

            // --- rejected (no-op): from succeeded --------------------------
            'succeeded + success attempt is a no-op' => [DeliveryStatus::Succeeded, 2, 5, true, DeliveryStatus::Succeeded, false],
            'succeeded + fail-below-limit attempt is a no-op' => [DeliveryStatus::Succeeded, 2, 3, false, DeliveryStatus::Succeeded, false],
            'succeeded + fail-at-limit attempt is a no-op' => [DeliveryStatus::Succeeded, 3, 3, false, DeliveryStatus::Succeeded, false],

            // --- rejected (no-op): from failed ------------------------------
            'failed + success attempt is a no-op' => [DeliveryStatus::Failed, 2, 5, true, DeliveryStatus::Failed, false],
            'failed + fail-below-limit attempt is a no-op' => [DeliveryStatus::Failed, 2, 3, false, DeliveryStatus::Failed, false],
            'failed + fail-at-limit attempt is a no-op' => [DeliveryStatus::Failed, 3, 3, false, DeliveryStatus::Failed, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_deliver_to_destination_transition_matrix(
        DeliveryStatus $from,
        int $attemptNumber,
        int $limit,
        bool $httpSucceeds,
        DeliveryStatus $expected,
        bool $honored,
    ): void {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => $httpSucceeds ? Http::response('ok', 200) : Http::response('nope', 500)]);

        $delivery = $this->deliveryWithStatus($from);
        // Enhanced (ADR-018 Decision 2): the configured limit is only
        // consulted for an Enhanced proxy — the default fixture is Simple.
        Proxy::query()->whereKey($delivery->proxy_id)->update([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => $limit,
        ]);

        DeliverToDestination::run($this->unitFor($delivery, $attemptNumber));

        $this->assertSame($expected, $delivery->fresh()->status, $honored ? 'The CAS must honor this transition.' : 'The CAS must reject this transition as a no-op.');

        if (! $honored) {
            // No side effect of ANY outcome ever fires against an already-
            // terminal delivery — not just the matching one.
            RetryDelivery::assertNotPushed();
            Event::assertNotDispatched(DeliveryExhausted::class);
        }
    }

    /**
     * `RetryDelivery::terminalizeCleaned()`'s own CAS: the only reachable
     * `from` status is `retrying` (guarded earlier in `handle()` — a stale/
     * non-retrying delivery returns before ever reaching this CAS, exercised
     * by `RetryDeliveryTest::test_a_stale_delivery_no_longer_retrying_...`
     * and `test_a_racing_duplicate_settle_...`, not duplicated here). The one
     * live transition it can attempt is `retrying -> failed`, already proven
     * by `RetryDeliveryTest::test_a_cleaned_parent_terminalizes_...` — this
     * test exists only to name it explicitly in the matrix, per this task's
     * remit, without re-asserting what that test already covers in full.
     */
    public function test_retry_delivery_terminalize_cleaned_transitions_retrying_to_failed(): void
    {
        Event::fake();

        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
        ]);

        app(RetryDelivery::class)->handle($delivery->id, 2);

        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
    }

    public function test_a_delivery_whose_destination_lost_validation_is_skipped_not_failed(): void
    {
        // #18 AC8's dispatch-gate, AC11, ADR-028. The row-creation gate cannot
        // see a state change that happens after the row exists.
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->deliveryWithStatus(DeliveryStatus::Pending);
        $delivery->destination->forceFill([
            'validation_state' => DestinationValidationState::Unvalidated,
            'validated_at' => null,
        ])->save();

        DeliverToDestination::run($this->unitFor($delivery->fresh(), 1));

        $this->assertSame(DeliveryStatus::Skipped, $delivery->fresh()->status);
        $this->assertTrue(DeliveryStatus::Skipped->isTerminal());

        Http::assertNothingSent();
        $this->assertSame(0, DeliveryAttempt::query()->where('delivery_id', $delivery->id)->count());
        Event::assertNotDispatched(DeliveryExhausted::class);
    }

    public function test_a_skipped_delivery_is_reached_from_retrying_too(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->deliveryWithStatus(DeliveryStatus::Retrying);
        $delivery->destination->forceFill([
            'validation_state' => DestinationValidationState::Unvalidated,
            'validated_at' => null,
        ])->save();

        DeliverToDestination::run($this->unitFor($delivery->fresh(), 2));

        $this->assertSame(DeliveryStatus::Skipped, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->next_attempt_at);
    }

    public function test_an_expired_challenge_skips_at_send_time(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->deliveryWithStatus(DeliveryStatus::Pending);
        $delivery->destination->forceFill([
            'validation_state' => DestinationValidationState::Pending,
            'validated_at' => null,
            'validation_challenge_expires_at' => now()->subDay(),
        ])->save();

        DeliverToDestination::run($this->unitFor($delivery->fresh(), 1));

        $this->assertSame(DeliveryStatus::Skipped, $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_already_terminal_delivery_is_not_reopened_as_skipped(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->deliveryWithStatus(DeliveryStatus::Succeeded);
        $delivery->destination->forceFill([
            'validation_state' => DestinationValidationState::Unvalidated,
            'validated_at' => null,
        ])->save();

        DeliverToDestination::run($this->unitFor($delivery->fresh(), 1));

        $this->assertSame(
            DeliveryStatus::Succeeded,
            $delivery->fresh()->status,
            'The CAS is keyed on pending/retrying, so a settled delivery stays settled.',
        );
    }
}
