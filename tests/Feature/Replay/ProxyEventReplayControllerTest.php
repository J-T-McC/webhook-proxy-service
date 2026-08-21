<?php

namespace Tests\Feature\Replay;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * End-to-end proof of the replay endpoint (T24; AC9-AC15, ADR-017 Decisions 1,
 * 3). Also folds T22's `ReplayEventRequest` validation coverage, since its
 * rules are route-dependent (a route-free unit harness would assert nothing
 * meaningful — see T22's completion notes).
 */
class ProxyEventReplayControllerTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function proxyWithDestinations(User $user, ProcessingMode $mode = ProcessingMode::Async, ProxyMode $proxyMode = ProxyMode::Simple, int $count = 2): Proxy
    {
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => $mode,
            'mode' => $proxyMode,
        ]);
        Destination::factory()->for($proxy)->count($count)->createQuietly(['url' => 'https://replay.test/hook']);

        return $proxy;
    }

    private function eventFor(Proxy $proxy): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
    }

    private function route(User $user, Proxy $proxy, WebhookEvent $event): string
    {
        return route('proxies.events.replay', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'event' => $event->id,
        ]);
    }

    // --- Happy paths (AC9-AC13) --------------------------------------------

    public function test_replaying_a_subset_creates_matching_delivery_rows_sharing_one_dispatch_uuid(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, count: 3);
        $event = $this->eventFor($proxy);
        $chosen = $proxy->destinations()->take(2)->pluck('id');

        $this->actingAs($user)
            ->post($this->route($user, $proxy, $event), ['destinations' => $chosen->all()])
            ->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $deliveries = Delivery::query()->where('webhook_event_id', $event->id)->get();
        $this->assertCount(2, $deliveries);
        $this->assertTrue($deliveries->pluck('destination_id')->diff($chosen)->isEmpty());
        $this->assertTrue($deliveries->every(fn (Delivery $d) => $d->kind === DispatchKind::Replay));
        $this->assertTrue($deliveries->every(fn (Delivery $d) => $d->status === DeliveryStatus::Pending));
        $this->assertCount(1, $deliveries->pluck('dispatch_uuid')->unique());
    }

    public function test_select_all_replays_to_every_current_live_destination(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, count: 3);
        $event = $this->eventFor($proxy);
        $all = $proxy->destinations()->pluck('id');

        $this->actingAs($user)
            ->post($this->route($user, $proxy, $event), ['destinations' => $all->all()])
            ->assertRedirect();

        $this->assertCount(3, Delivery::query()->where('webhook_event_id', $event->id)->get());
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
    public function test_replay_works_on_simple_and_enhanced_mode_proxies(ProxyMode $proxyMode): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, proxyMode: $proxyMode, count: 1);
        $event = $this->eventFor($proxy);
        $destinationId = $proxy->destinations()->value('id');

        // No Queue::fake — sync driver drains the dispatched work inline, so the
        // actual send is observable via Http::fake.
        $this->actingAs($user)
            ->post($this->route($user, $proxy, $event), ['destinations' => [$destinationId]])
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->url() === 'https://replay.test/hook');
    }

    public function test_an_async_proxy_dispatches_immediately(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, mode: ProcessingMode::Async, count: 1);
        $event = $this->eventFor($proxy);
        $destinationId = $proxy->destinations()->value('id');

        $this->actingAs($user)
            ->post($this->route($user, $proxy, $event), ['destinations' => [$destinationId]])
            ->assertRedirect();

        $dispatchUuid = Delivery::query()->where('webhook_event_id', $event->id)->value('dispatch_uuid');

        ProcessIngestedWebhook::assertPushed(
            1,
            fn ($job, array $parameters) => $parameters[0] === $event->ingest_id && $parameters[1] === $dispatchUuid,
        );
        AdvanceProxyFifoQueue::assertNotPushed();
        $this->assertSame(0, FifoDispatch::count());
    }

    public function test_a_fifo_proxy_gains_a_new_pending_row_that_joins_the_line_at_the_back(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, mode: ProcessingMode::Fifo, count: 1);
        $event = $this->eventFor($proxy);
        $destinationId = $proxy->destinations()->value('id');

        // An already-pending row ahead of the replay (T16's id-order key defines
        // "the back" — a fresh row is correct by construction, no ordering value
        // needed).
        $existing = FifoDispatch::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'status' => FifoDispatchStatus::Pending,
        ]);

        $this->actingAs($user)
            ->post($this->route($user, $proxy, $event), ['destinations' => [$destinationId]])
            ->assertRedirect();

        $new = FifoDispatch::query()->where('id', '!=', $existing->id)->where('proxy_id', $proxy->id)->firstOrFail();
        $this->assertSame(FifoDispatchStatus::Pending, $new->status);
        $this->assertGreaterThan($existing->id, $new->id, 'The replay row must join at the back (id order).');
        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $parameters) => $parameters[0] === $proxy->id);
    }

    // --- Eligibility / lifecycle (AC15) ------------------------------------

    public function test_a_cleaned_events_replay_attempt_is_rejected_and_creates_nothing(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user);
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $destinationId = $proxy->destinations()->value('id');

        $this->actingAs($user)
            ->postJson($this->route($user, $proxy, $event), ['destinations' => [$destinationId]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
        ProcessIngestedWebhook::assertNotPushed();
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_a_race_where_gc_cleans_the_event_between_page_load_and_the_post_is_rejected(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user);
        $event = $this->eventFor($proxy);
        $destinationId = $proxy->destinations()->value('id');

        // Simulate GC racing in and cleaning the event between the page load (the
        // route-bound event lookup) and the endpoint's own re-check: the very
        // first plain (non-locking) read of webhook_events on this request is the
        // route-model-binding lookup, which lands before the transaction's
        // lockForUpdate re-check.
        $mutated = false;
        DB::listen(function ($query) use ($event, &$mutated): void {
            if ($mutated || ! str_contains($query->sql, 'from `webhook_events`') || str_contains($query->sql, 'for update')) {
                return;
            }

            $mutated = true;
            DB::table('webhook_events')->where('id', $event->id)->update(['payload_cleaned_at' => now()]);
        });

        $this->actingAs($user)
            ->postJson($this->route($user, $proxy, $event), ['destinations' => [$destinationId]])
            ->assertStatus(422);

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }

    // --- ReplayEventRequest validation (T22, folded here) -------------------

    public function test_an_empty_destinations_array_is_rejected(): void
    {
        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user);
        $event = $this->eventFor($proxy);

        $this->actingAs($user)
            ->postJson($this->route($user, $proxy, $event), ['destinations' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destinations');
    }

    public function test_a_trashed_destination_id_is_rejected(): void
    {
        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, count: 1);
        $event = $this->eventFor($proxy);
        $trashed = Destination::factory()->for($proxy)->trashed()->createQuietly();

        $this->actingAs($user)
            ->postJson($this->route($user, $proxy, $event), ['destinations' => [$trashed->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destinations.0');

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }

    public function test_another_proxys_destination_id_is_rejected(): void
    {
        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, count: 1);
        $event = $this->eventFor($proxy);
        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $foreign = Destination::factory()->for($otherProxy)->createQuietly();

        $this->actingAs($user)
            ->postJson($this->route($user, $proxy, $event), ['destinations' => [$foreign->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destinations.0');
    }

    public function test_a_non_existent_destination_id_is_rejected(): void
    {
        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, count: 1);
        $event = $this->eventFor($proxy);

        $this->actingAs($user)
            ->postJson($this->route($user, $proxy, $event), ['destinations' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destinations.0');
    }

    public function test_a_duplicate_destination_id_is_rejected(): void
    {
        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user, count: 1);
        $event = $this->eventFor($proxy);
        $destinationId = $proxy->destinations()->value('id');

        $this->actingAs($user)
            ->postJson($this->route($user, $proxy, $event), ['destinations' => [$destinationId, $destinationId]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destinations.0');
    }

    // --- Authorization (AC14) -----------------------------------------------

    public function test_a_member_replaying_a_proxy_they_did_not_create_succeeds(): void
    {
        Queue::fake();

        $team = Team::factory()->createQuietly();
        $creator = User::factory()->createQuietly();
        $team->members()->attach($creator, ['role' => TeamRole::Member->value]);
        $member = User::factory()->createQuietly();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $creator->id]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://replay.test/hook']);
        $event = $this->eventFor($proxy);
        $destinationId = $proxy->destinations()->value('id');

        $this->actingAs($member)
            ->post($this->route($member, $proxy, $event), ['destinations' => [$destinationId]])
            ->assertRedirect();

        $this->assertSame(1, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }

    public function test_a_non_member_is_denied(): void
    {
        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user);
        $event = $this->eventFor($proxy);
        $destinationId = $proxy->destinations()->value('id');

        $stranger = User::factory()->createQuietly();
        $stranger->switchTeam($stranger->currentTeam);

        // The stranger has no membership on the proxy's team. This 404s at the
        // scoped route-model-binding layer (ApplyTeamScope/SubstituteBindings run
        // ahead of EnsureTeamMembership's own check in this middleware pipeline) —
        // AC14's "a non-member 403/404s" names both outcomes as acceptable, and
        // this matches the same observable shape as the pre-existing cross-team
        // destination 404 (DestinationDestroyTest).
        $this->actingAs($stranger)
            ->post(route('proxies.events.replay', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]), ['destinations' => [$destinationId]])
            ->assertStatus(404);

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }

    // --- Route/scoped-binding correctness ------------------------------------

    public function test_another_proxys_event_id_returns_404(): void
    {
        $user = $this->actingUser();
        $proxy = $this->proxyWithDestinations($user);
        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $foreignEvent = $this->eventFor($otherProxy);
        $destinationId = $proxy->destinations()->value('id');

        $this->actingAs($user)
            ->post($this->route($user, $proxy, $foreignEvent), ['destinations' => [$destinationId]])
            ->assertNotFound();

        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $foreignEvent->id)->count());
    }
}
