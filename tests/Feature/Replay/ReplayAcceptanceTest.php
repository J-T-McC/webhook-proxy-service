<?php

namespace Tests\Feature\Replay;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Enums\TeamRole;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * End-to-end proof of manual replay (PRD-06 AC9–AC14) through the real
 * `POST .../events/{event}/replay` endpoint (T24) and the real pipeline —
 * complementing T24's own controller-level tests (`ProxyEventReplayControllerTest`).
 */
class ReplayAcceptanceTest extends TestCase
{
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

    private function replayRoute(User $user, Proxy $proxy, WebhookEvent $event): string
    {
        return route('proxies.events.replay', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'event' => $event->id,
        ]);
    }

    // --- AC10: destination targeting -----------------------------------

    public function test_replay_to_a_subset_dispatches_to_exactly_those_destinations_and_never_to_others(): void
    {
        Http::fake([
            'https://replay.test/one' => Http::response('ok', 200),
            'https://replay.test/two' => Http::response('ok', 200),
            'https://replay.test/three' => Http::response('ok', 200),
        ]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Async,
        ]);
        $chosenA = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://replay.test/one']);
        $chosenB = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://replay.test/two']);
        $notChosen = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://replay.test/three']);
        $event = $this->eventFor($proxy);

        // No Queue::fake — sync driver drains the dispatched replay work inline.
        $this->actingAs($user)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => [$chosenA->id, $chosenB->id]])
            ->assertRedirect();

        $deliveries = Delivery::query()->where('webhook_event_id', $event->id)->get();
        $this->assertCount(2, $deliveries, 'Exactly the two selected destinations get a delivery row — never the third.');
        $this->assertSame([$chosenA->id, $chosenB->id], $deliveries->pluck('destination_id')->sort()->values()->all());
        $this->assertTrue($deliveries->every(fn (Delivery $d) => $d->kind === DispatchKind::Replay));

        Http::assertSent(fn ($request) => $request->url() === 'https://replay.test/one');
        Http::assertSent(fn ($request) => $request->url() === 'https://replay.test/two');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://replay.test/three');
        $this->assertSame(0, DeliveryAttempt::where('destination_id', $notChosen->id)->count());
    }

    public function test_select_all_replays_to_every_current_live_destination_and_none_are_missed(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Async,
        ]);
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();
        $event = $this->eventFor($proxy);

        $this->actingAs($user)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => $destinations->pluck('id')->all()])
            ->assertRedirect();

        $deliveries = Delivery::query()->where('webhook_event_id', $event->id)->get();
        $this->assertCount(3, $deliveries);
        $this->assertSame($destinations->pluck('id')->sort()->values()->all(), $deliveries->pluck('destination_id')->sort()->values()->all());
        $this->assertSame(3, DeliveryAttempt::query()->count());
    }

    public function test_replay_never_targets_a_trashed_destination_or_another_proxys_destination(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->createQuietly();
        $trashed = Destination::factory()->for($proxy)->trashed()->createQuietly();
        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $foreign = Destination::factory()->for($otherProxy)->createQuietly();
        $event = $this->eventFor($proxy);

        $this->actingAs($user)
            ->postJson($this->replayRoute($user, $proxy, $event), ['destinations' => [$trashed->id]])
            ->assertStatus(422);
        $this->actingAs($user)
            ->postJson($this->replayRoute($user, $proxy, $event), ['destinations' => [$foreign->id]])
            ->assertStatus(422);

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }

    // --- AC11/AC12: real pipeline, replay traceability -------------------

    public function test_replay_runs_through_the_real_pipeline_and_produces_traceable_replay_deliveries(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Async,
            'mode' => ProxyMode::Enhanced,
        ]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        // A prior original delivery already ran CaptureDispatchedStep once
        // (enhanced mode) — `dispatched_payloads.webhook_event_id` is UNIQUE,
        // so a replay of the SAME event must update this row idempotently,
        // never create a second one.
        DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
        ]);
        $this->assertSame(1, DispatchedPayload::where('webhook_event_id', $event->id)->count());

        $this->actingAs($user)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => [$destination->id]])
            ->assertRedirect();

        // CaptureDispatchedStep ran idempotently — still exactly one row.
        $this->assertSame(1, DispatchedPayload::where('webhook_event_id', $event->id)->count());

        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DispatchKind::Replay, $delivery->kind);
        $this->assertNotSame($event->ingest_id, $delivery->dispatch_uuid, 'A replay mints its own dispatch identity, never the original ingest id.');

        // Attempts are chained via delivery_id — traceable back to this exact
        // replay delivery, and via webhook_event_id back to the original event.
        $attempt = DeliveryAttempt::where('delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame($delivery->id, $attempt->delivery_id);
        $this->assertSame($event->ingest_id, $attempt->ingest_id);
    }

    /** @return array<string, array{0: ProxyMode}> */
    public static function proxyModes(): array
    {
        return [
            'simple' => [ProxyMode::Simple],
            'enhanced' => [ProxyMode::Enhanced],
        ];
    }

    #[DataProvider('proxyModes')]
    public function test_replay_delivers_for_real_on_both_simple_and_enhanced_proxies(ProxyMode $mode): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'mode' => $mode]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        $this->actingAs($user)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => [$destination->id]])
            ->assertRedirect();

        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->status);
        Http::assertSent(fn ($request) => $request->url() === $destination->url);
    }

    // --- AC13: a failed replay retries and can terminalize ---------------

    public function test_a_failed_replay_retries_under_policy_and_can_terminalize_with_delivery_exhausted(): void
    {
        // No Queue::fake(): limit=1 means the whole "schedule" is just the
        // replay's own first attempt (no attempt 2 is ever scheduled), so the
        // sync queue driver's inline drain is safe to observe directly.
        Event::fake();
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

        $this->actingAs($user)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => [$destination->id]])
            ->assertRedirect();

        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DispatchKind::Replay, $delivery->kind);
        $this->assertSame(DeliveryStatus::Failed, $delivery->status, "limit=1 terminalizes on the replay's own first attempt.");
        $this->assertNull($delivery->next_attempt_at);

        Event::assertDispatchedTimes(DeliveryExhausted::class, 1);
        Event::assertDispatched(DeliveryExhausted::class, fn (DeliveryExhausted $e) => $e->delivery->id === $delivery->id
            && $e->delivery->kind === DispatchKind::Replay);
    }

    // --- AC8: upstream sender / ingest unaffected -------------------------

    public function test_replay_never_produces_an_ingest_response_and_ingest_stays_unaffected_by_retry_state(): void
    {
        // Queue::fake() freezes the replay's own dispatch (AdvanceProxyFifoQueue
        // for a FIFO proxy) so it can be run manually, once, for a controlled
        // single real attempt — limit 5 means letting the sync queue's
        // unfaked dispatch()->afterCommit() cascade would otherwise drain the
        // WHOLE retry schedule to Failed inline within the POST itself.
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Fifo,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 5,
        ]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        // The replay itself never produces an ingest-shaped response — it is a
        // session-authenticated redirect (PRG), never the token-authenticated
        // ingest endpoint's own response.
        // A successful replay redirects back (`back()`) to whichever page the
        // user replayed from — the events Index or the event's own Show page
        // (review-06 Major 1 fix; design-06 Flow D step 3 / Screen 4 Success).
        $refererUrl = route('proxies.events.show', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'event' => $event->id,
        ]);
        $replayResponse = $this->actingAs($user)
            ->from($refererUrl)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => [$destination->id]]);
        $replayResponse->assertRedirect($refererUrl);
        $this->assertNotSame(200, $replayResponse->getStatusCode());

        // Run the replay's (captured) FIFO advance once, for real — the
        // single inline attempt 1.
        AdvanceProxyFifoQueue::run($proxy->id);

        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status, 'Precondition: the replay delivery has an outstanding retry.');

        // A genuine new ingest against the SAME proxy, while the replay's
        // delivery is still mid-schedule, is entirely unaffected.
        $ingestResponse = $this->post('https://localhost/ingest/'.$proxy->ingest_token, ['hello' => 'world']);
        $ingestResponse->assertStatus(202);
        $this->assertSame(2, WebhookEvent::query()->where('proxy_id', $proxy->id)->count());
    }

    // --- AC14: permission-gated, not role-gated ---------------------------

    /** @return array<string, array{0: TeamRole}> */
    public static function teamRoles(): array
    {
        return [
            'owner' => [TeamRole::Owner],
            'admin' => [TeamRole::Admin],
            'member' => [TeamRole::Member],
        ];
    }

    #[DataProvider('teamRoles')]
    public function test_every_team_role_can_replay_a_proxy_they_did_not_create(TeamRole $role): void
    {
        Queue::fake();

        $team = Team::factory()->createQuietly();
        $creator = User::factory()->createQuietly();
        $team->members()->attach($creator, ['role' => TeamRole::Owner->value]);
        $actor = User::factory()->createQuietly();
        $team->members()->attach($actor, ['role' => $role->value]);
        $actor->switchTeam($team);

        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $creator->id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        $this->actingAs($actor)
            ->post($this->replayRoute($actor, $proxy, $event), ['destinations' => [$destination->id]])
            ->assertRedirect();

        $this->assertSame(1, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }

    public function test_a_non_member_is_denied_replay(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        $stranger = User::factory()->createQuietly();
        $stranger->switchTeam($stranger->currentTeam);

        $this->actingAs($stranger)
            ->post(route('proxies.events.replay', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]), ['destinations' => [$destination->id]])
            ->assertStatus(404);

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }

    // --- Queue redelivery of the replay's own processing job -------------

    public function test_a_redelivered_replay_processing_job_creates_no_duplicate_delivery_rows_or_attempts(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Async,
        ]);
        $destinations = Destination::factory()->for($proxy)->count(2)->createQuietly();
        $event = $this->eventFor($proxy);

        // No Queue::fake(): the sync driver drains the replay's own dispatch
        // for real, delivering both destinations once each.
        $this->actingAs($user)
            ->post($this->replayRoute($user, $proxy, $event), ['destinations' => $destinations->pluck('id')->all()])
            ->assertRedirect();

        $dispatchUuid = Delivery::query()->where('webhook_event_id', $event->id)->value('dispatch_uuid');
        $this->assertCount(2, Delivery::query()->where('webhook_event_id', $event->id)->get());
        $this->assertSame(2, DeliveryAttempt::query()->where('ingest_id', $event->ingest_id)->count());

        // Simulate the underlying queue's at-least-once redelivery of the SAME
        // replay-processing job (ADR-011 Decision 4 / #4 AC9 parity): running
        // ProcessIngestedWebhook::run() again for the identical dispatch_uuid
        // must never duplicate delivery rows or attempts.
        ProcessIngestedWebhook::run($event->ingest_id, $dispatchUuid);

        $this->assertCount(2, Delivery::query()->where('webhook_event_id', $event->id)->get());
        $this->assertSame(2, DeliveryAttempt::query()->where('ingest_id', $event->ingest_id)->count());
    }
}
