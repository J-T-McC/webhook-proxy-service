<?php

namespace Tests\Feature\ProxyEvents;

use App\Enums\AttemptStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T27 — `ProxyEventController@show` (AC12, AC16; ADR-017 Decision 5): the
 * full-detail response, the never-content assertion, the scoped-binding 404
 * case, and the legacy-fallback case (Q-06-03 ruling 3).
 */
class ProxyEventShowTest extends TestCase
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

    private function route(User $user, Proxy $proxy, WebhookEvent $event): string
    {
        return route('proxies.events.show', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'event' => $event->id,
        ]);
    }

    public function test_the_detail_response_carries_original_and_replay_deliveries_with_attempts(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $original = Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'kind' => DispatchKind::Original,
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'delivery_id' => $original->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
        ]);

        $replay = Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'kind' => DispatchKind::Replay,
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'delivery_id' => $replay->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
        ]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/events/Show')
                ->where('event.id', $event->id)
                ->has('event.deliveries', 2)
                ->where('event.deliveries.0.attempts.0.attempt_number', 1)
                ->where('event.deliveries.1.attempts.0.attempt_number', 1)
                ->where('event.deliveries', fn ($deliveries) => collect($deliveries)->pluck('kind')->all() === ['original', 'replay']
                    || collect($deliveries)->pluck('kind')->all() === ['replay', 'original'])
            );
    }

    public function test_it_never_emits_body_or_headers(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('event.body')
                ->missing('event.headers')
            );
    }

    public function test_a_cross_proxy_event_id_returns_404(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $foreignEvent = WebhookEvent::factory()->createQuietly(['proxy_id' => $otherProxy->id, 'team_id' => $otherProxy->team_id]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, $foreignEvent))
            ->assertNotFound();
    }

    public function test_a_cross_team_proxy_id_returns_404(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->createQuietly();
        $foreignProxy = Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);
        $foreignEvent = WebhookEvent::factory()->createQuietly(['proxy_id' => $foreignProxy->id, 'team_id' => $foreignProxy->team_id]);

        $this->actingAs($user)
            ->get($this->route($user, $foreignProxy, $foreignEvent))
            ->assertNotFound();
    }

    public function test_a_pre_6_event_renders_via_the_legacy_fallback(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
            'status' => AttemptStatus::Succeeded,
        ]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('event.deliveries', 1)
                ->where('event.deliveries.0.id', null)
                ->where('event.deliveries.0.status', 'succeeded')
            );

        $this->assertSame(0, Delivery::query()->count());
    }
}
