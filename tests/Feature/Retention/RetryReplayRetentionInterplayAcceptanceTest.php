<?php

namespace Tests\Feature\Retention;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Actions\PurgeExpiredPayloads;
use App\Actions\RetryDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Enums\StoredPayloadState;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\StoredPayloadLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T42 — end-to-end proof that #6's new dispatch forms (retry, replay) honor
 * the #5 retention contract (PRD-06 AC15-AC18), complementing T19's unit-level
 * `PurgeExpiredPayloads` H5 cases and #5's existing retention suites
 * (`RetentionInFlightHoldsAcceptanceTest`, `CleanedStateReaderGuardAcceptanceTest`).
 * Where T19 constructs `deliveries` rows directly, this suite drives the real
 * `ProcessIngestedWebhook`/`RetryDelivery`/replay-endpoint chain so the hold
 * composes correctly with the live retry engine over time, not just a static
 * row shape.
 */
class RetryReplayRetentionInterplayAcceptanceTest extends TestCase
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

    private function expiredEventFor(Proxy $proxy, array $attributes = []): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays(31),
            ...$attributes,
        ]);
    }

    private function isCleaned(WebhookEvent $event): bool
    {
        return DB::table('webhook_events')->where('id', $event->id)->value('payload_cleaned_at') !== null;
    }

    private function replayRoute(User $user, Proxy $proxy, WebhookEvent $event): string
    {
        return route('proxies.events.replay', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'event' => $event->id,
        ]);
    }

    // --- AC15/AC17: a cleaned event is not replayable, dispatches nothing ---

    public function test_replay_of_a_cleaned_event_is_a_validation_error_with_zero_delivery_rows_zero_attempts_zero_http_sends(): void
    {
        Http::fake();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        $this->actingAs($user)
            ->postJson($this->replayRoute($user, $proxy, $event), ['destinations' => [$destination->id]])
            ->assertStatus(422);

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
        $this->assertSame(0, DeliveryAttempt::query()->count());
        Http::assertNothingSent();
    }

    // --- AC17/AC18: the select->act race, both directions -------------------

    public function test_a_race_where_gc_erases_between_page_load_and_the_replay_post_is_rejected_by_the_lock_for_update_recheck(): void
    {
        Http::fake();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        // Simulate GC racing in and cleaning the event between the page load
        // (the route-bound event lookup) and the endpoint's own re-check: the
        // very first plain (non-locking) read of webhook_events on this
        // request is the route-model-binding lookup, which lands before the
        // transaction's lockForUpdate re-check (mirrors
        // ProxyEventReplayControllerTest's own race test).
        $mutated = false;
        DB::listen(function ($query) use ($event, &$mutated): void {
            if ($mutated || ! str_contains($query->sql, 'from `webhook_events`') || str_contains($query->sql, 'for update')) {
                return;
            }

            $mutated = true;
            DB::table('webhook_events')->where('id', $event->id)->update(['payload_cleaned_at' => now(), 'body' => null, 'headers' => null]);
        });

        $this->actingAs($user)
            ->postJson($this->replayRoute($user, $proxy, $event), ['destinations' => [$destination->id]])
            ->assertStatus(422);

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
        Http::assertNothingSent();
    }

    public function test_replay_rows_committed_first_hold_the_erase_so_the_compare_and_set_affects_zero_rows(): void
    {
        // Queue::fake() freezes the replay's own dispatch (a FIFO proxy
        // dispatches `AdvanceProxyFifoQueue`) so it can be run manually, once,
        // for a controlled single real attempt — leaving the delivery's
        // subsequent scheduled retry (attempt 2) captured/frozen, never run.
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Fifo,
        ]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        // Already past the 30-day retention window, but GC has not run yet —
        // the payload is still retained at replay time.
        $event = $this->expiredEventFor($proxy);
        $this->assertFalse($this->isCleaned($event), 'Precondition: GC has not run — the payload is still retained.');

        $this->actingAs($user)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => [$destination->id]])
            ->assertRedirect();

        // Run the replay's (captured) FIFO advance once, for real — the
        // single inline attempt 1.
        AdvanceProxyFifoQueue::run($proxy->id);

        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status, 'Precondition: the replay left an outstanding retry.');

        // The event is well past the retention window — without the H5 hold
        // this pass would erase it despite the outstanding replay retry.
        PurgeExpiredPayloads::run();

        $this->assertFalse($this->isCleaned($event), 'The outstanding replay delivery holds the erase — the compare-and-set affects zero rows.');
    }

    // --- AC18: H5 end to end, over the live retry engine --------------------

    public function test_h5_an_expired_event_with_a_retrying_delivery_is_not_erased_and_is_erased_once_the_delivery_terminalizes(): void
    {
        // Queue::fake() freezes the scheduled attempt 2 (a real delayed
        // RetryDelivery job) so it can be inspected/invoked manually instead
        // of the sync queue driver's zero-delay inline cascade running the
        // whole schedule to a terminal state within the first call.
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 1,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->expiredEventFor($proxy);

        // Attempt 1 (inline, real, Fifo's DeliverStep branch): fails. With
        // retry_attempt_limit = 1 the delivery terminalizes on its OWN first
        // attempt — so instead re-open the schedule by raising the limit
        // AFTER the first failure, to genuinely observe the `retrying` hold.
        $proxy->update(['retry_attempt_limit' => 2]);
        ProcessIngestedWebhook::run($event->ingest_id);
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status, 'Precondition: an outstanding retry exists.');

        PurgeExpiredPayloads::run();
        $this->assertFalse($this->isCleaned($event), 'An expired event with a retrying delivery must not be erased.');

        // Let the retry run for real and exhaust at the (now lowered) limit —
        // reaching the explicit terminal `failed` state.
        $proxy->update(['retry_attempt_limit' => 1]);
        app(RetryDelivery::class)->handle($delivery->id, 2);
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status, 'Precondition: the delivery is now terminal.');

        PurgeExpiredPayloads::run();
        $this->assertTrue($this->isCleaned($event), 'Once every delivery is terminal, the next GC pass erases the event.');
    }

    public function test_h5_a_pending_delivery_holds_only_within_the_dispatch_horizon(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $withinHorizon = $this->expiredEventFor($proxy, ['ingest_id' => 'evt-within-horizon']);
        $pastHorizon = $this->expiredEventFor($proxy, ['ingest_id' => 'evt-past-horizon']);

        // A `pending` deliveries row for each — simulating a first-attempt job
        // that was dispatched but has not yet run (the same "lost job" shape
        // H4 guards for delivery_attempts, mirrored here for deliveries/H5).
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $withinHorizon->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Pending,
            'created_at' => now(),
        ]);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $pastHorizon->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Pending,
            'created_at' => now()->subMinutes((int) config('retention.dispatch_horizon_minutes') + 5),
        ]);

        PurgeExpiredPayloads::run();

        $this->assertFalse($this->isCleaned($withinHorizon), 'A pending delivery younger than the dispatch horizon must hold the event.');
        $this->assertTrue($this->isCleaned($pastHorizon), 'A pending delivery older than the dispatch horizon must not hold the event.');
    }

    // --- AC17: RetryDelivery meeting a cleaned parent (H4-residual race) ----

    public function test_retry_delivery_meeting_a_cleaned_parent_mid_schedule_terminalizes_sends_nothing_and_logs_identifiers_only(): void
    {
        // Queue::fake() freezes the scheduled attempt 2 so it can be invoked
        // manually, once, instead of the sync queue driver's zero-delay
        // inline cascade running the whole schedule to a terminal state
        // before the parent can be forced cleaned below.
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);
        Log::spy();

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 3,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        // Attempt 1 (inline, real): fails, schedules attempt 2 for real — a
        // genuine `retrying` delivery, not a synthetic factory row.
        ProcessIngestedWebhook::run($event->ingest_id);
        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);

        // The H4-residual race T14's Description names: H5 (T19) closes this
        // window under normal GC operation (a retrying delivery always holds
        // the event), so the only way to observe RetryDelivery's own defensive
        // guard for real is to force the parent cleaned directly, simulating
        // whatever narrow race window predates H5 — never through a normal
        // PurgeExpiredPayloads pass, which H5 would correctly refuse.
        $event->forceFill(['payload_cleaned_at' => now(), 'body' => null, 'headers' => null])->saveQuietly();

        app(RetryDelivery::class)->handle($delivery->id, 2);

        // Attempt 1's own real send already happened above (inline, before
        // the parent was forced cleaned) — the guard under test sends
        // NOTHING further: exactly that one recorded request total, no
        // second one for attempt 2.
        Http::assertSentCount(1);
        $this->assertSame(1, DeliveryAttempt::where('delivery_id', $delivery->id)->count(), 'Only attempt 1 was ever written — no attempt 2 row.');

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertNull($fresh->next_attempt_at);

        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => $message === 'payload.expired'
                && $context === ['ingest_id' => $event->ingest_id],
        );
    }

    // --- AC16: the three payload states, distinct on every #6 read path -----

    public function test_the_three_payload_states_render_distinctly_and_are_never_inferred_from_body(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $retained = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $cleaned = WebhookEvent::factory()->cleaned()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        // List route (T26): both real captured rows render distinctly.
        $this->actingAs($user)
            ->get(route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.payload_state', 'cleaned') // newest-first: $cleaned created after $retained
                ->where('events.data.1.payload_state', 'retained'));

        // Detail route (T27): same mapping, same resolver.
        $this->actingAs($user)
            ->get(route('proxies.events.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $retained->id]))
            ->assertInertia(fn (Assert $page) => $page->where('event.payload_state', 'retained'));
        $this->actingAs($user)
            ->get(route('proxies.events.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $cleaned->id]))
            ->assertInertia(fn (Assert $page) => $page->where('event.payload_state', 'cleaned'));

        // Payload endpoint (T28): retained -> 200 with bytes; cleaned -> 410,
        // no content — the same signal, never inferred from `body`.
        $this->actingAs($user)
            ->get(route('proxies.events.payload', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $retained->id]))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('proxies.events.payload', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $cleaned->id]))
            ->assertStatus(410);

        // `never_captured` (AC21/AC16): the shared resolver's third state,
        // signalled only by the absence of a `webhook_events` row for the
        // given `ingest_id`. `WebhookEventResource` — the only #6 consumer of
        // this resolver — always passes its OWN row's `ingest_id`, so
        // `never_captured` is structurally unreachable through the list/
        // detail routes above; asserted directly against the shared resolver
        // instead, which is what every #6 read path composes through.
        $this->assertSame(
            StoredPayloadState::NeverCaptured,
            app(StoredPayloadLookup::class)->for('genuinely-unknown-ingest-id-'.uniqid()),
        );
    }
}
