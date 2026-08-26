<?php

namespace Tests\Feature\Retry;

use App\Actions\ProcessIngestedWebhook;
use App\Actions\RetryDelivery;
use App\Actions\SweepDueRetries;
use App\Enums\DeliveryStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * End-to-end proof of the explicit terminal state and its event (PRD-06 AC4,
 * AC5), complementing T13's unit-level cases (`DeliverToDestinationTest`).
 */
class TerminalStateAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function eventFor(Proxy $proxy): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
    }

    public function test_after_the_limit_the_delivery_is_terminal_and_no_further_attempt_is_ever_created(): void
    {
        // Queue::fake() freezes the scheduled attempt 2 for controlled,
        // travel()-paced manual invocation instead of the sync-queue driver's
        // zero-delay inline cascade collapsing the whole schedule into one call
        // (mirroring RetryEngineAcceptanceTest's established pattern).
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        ProcessIngestedWebhook::run($event->ingest_id); // attempt 1 — inline, real, retrying
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);

        $fixedInterval = (int) config('retry.fixed_interval_seconds');
        $this->travel($fixedInterval + 5)->seconds();
        app(RetryDelivery::class)->handle($delivery->id, 2); // attempt 2 — at the limit, terminalizes

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());

        // Travel well past any conceivable further schedule and sweep: the
        // terminal delivery is never picked up again — zero new attempts, ever.
        $this->travel($fixedInterval * 10)->seconds();
        SweepDueRetries::run();

        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->next_attempt_at);
        // SweepDueRetries only re-drives `retrying` deliveries — the terminal
        // delivery is structurally excluded, not merely unlucky to be skipped.
        // (RetryDelivery WAS pushed once, for attempt 2's own original
        // schedule off attempt 1 — that push is expected and never executes
        // under Queue::fake(); no attempt-3 push ever happens.)
        RetryDelivery::assertNotPushed(fn ($action, array $params) => $params[0] === $delivery->id && $params[1] === 3);
    }

    public function test_delivery_exhausted_fires_exactly_once_under_a_racing_duplicate_settle_and_carries_reachable_state(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->createQuietly([
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 2,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);
        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subMinute(),
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'delivery_id' => $delivery->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
        ]);

        // The sweeper and this delivery's still-live delayed job both fire for
        // attempt 2, the terminal attempt (limit 2): the first execution
        // terminalizes and fires DeliveryExhausted; the second reloads a
        // no-longer-`retrying` delivery and is a structural no-op (RetryDelivery's
        // own early-return guard) — a realistic race at the actual job entry point,
        // not a synthetic double-call into DeliverToDestination.
        app(RetryDelivery::class)->handle($delivery->id, 2);
        app(RetryDelivery::class)->handle($delivery->id, 2);

        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());

        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
        Event::assertDispatched(DeliveryExhausted::class, function (DeliveryExhausted $exhausted) use ($proxy, $destination, $event, $delivery): bool {
            return $exhausted->delivery->id === $delivery->id
                && $exhausted->delivery->team_id === $proxy->team_id
                && $exhausted->delivery->proxy->id === $proxy->id
                && $exhausted->delivery->destination->id === $destination->id
                && $exhausted->delivery->webhookEvent->id === $event->id;
        });
    }

    public function test_a_terminal_delivery_remains_visible_on_the_read_surface_and_the_event_stays_replayable(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 1,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);
        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
            // Overdue well beyond the sweep grace period, so the sweeper picks it up.
            'next_attempt_at' => now()->subSeconds((int) config('retry.sweep_grace_seconds') + 60),
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'delivery_id' => $delivery->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
        ]);

        // Terminalize (limit 1 — the pre-existing attempt 1 is already the last
        // permitted attempt): a stale sweeper fire finds it retrying and overdue.
        SweepDueRetries::run();
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);

        $this->actingAs($user)
            ->get(route('proxies.events.index', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.id', $event->id)
                ->where('events.data.0.deliveries.0.status', DeliveryStatus::Failed->value));

        $this->actingAs($user)
            ->get(route('proxies.events.show', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.id', $event->id)
                ->where('event.deliveries.0.status', DeliveryStatus::Failed->value));

        // The event's payload is still retained (not cleaned) — a terminal
        // delivery does not itself make the event unreplayable (AC4/AC15).
        $this->assertNull($event->fresh()->payload_cleaned_at);
        $this->actingAs($user)
            ->post(route('proxies.events.replay', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]), ['destinations' => [$destination->id]])
            ->assertRedirect();
    }
}
