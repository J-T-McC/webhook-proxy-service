<?php

namespace Tests\Feature\Analytics;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Deleted-parent drill-through, both halves (T22; AC6; plan § Architecture E,
 * `Q-11-03(9)`) — no new production code, this is the acceptance coverage
 * proving T8's `canDrillThrough` and T21's `Destination::withTrashed()`
 * resolution hold end to end. A deleted **destination**'s drill-through stays
 * live; a deleted **proxy** takes the pre-approved degradation (its route
 * still 404s, its Dashboard row's links are muted).
 */
class DeletedParentDrillThroughTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    /**
     * A deleted destination's `View events` link still resolves and filters
     * correctly, through `Destination::withTrashed()` + the `proxy_id`
     * predicate (`Q-11-03(9)`'s destination half; T21).
     */
    public function test_a_deleted_destinations_view_events_link_resolves_and_filters_correctly(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();

        $matching = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $user->current_team_id]);
        Delivery::factory()->state([
            'webhook_event_id' => $matching->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();

        $otherDestination = Destination::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();
        $nonMatching = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $user->current_team_id]);
        Delivery::factory()->state([
            'webhook_event_id' => $nonMatching->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $otherDestination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();

        $destination->delete();
        $this->assertTrue($destination->trashed());

        $this->actingAs($user)
            ->get(route('proxies.events.index', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'destination' => $destination->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $matching->id)
                ->where('filters.destination.id', $destination->id)
                ->where('filters.destination.isDeleted', true)
            );
    }

    /**
     * A soft-deleted proxy's Dashboard breakdown row carries
     * `canDrillThrough === false` end to end, through the real
     * `DashboardController` route (T8's `proxyBreakdown()`, T13's prop).
     */
    public function test_a_deleted_proxys_breakdown_row_carries_can_drill_through_false(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $user->current_team_id]);
        Delivery::factory()->state([
            'webhook_event_id' => $event->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();

        $livingProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $proxy->delete();
        $this->assertTrue($proxy->trashed());

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxies', 2)
                ->where('proxies', fn ($proxies) => collect($proxies)
                    ->firstWhere('id', $proxy->id)['canDrillThrough'] === false
                    && collect($proxies)->firstWhere('id', $proxy->id)['isDeleted'] === true
                    && collect($proxies)->firstWhere('id', $proxy->id)['delivery']['total'] === 1
                    && collect($proxies)->firstWhere('id', $livingProxy->id)['canDrillThrough'] === true)
            );
    }

    /**
     * The events route for a soft-deleted proxy id still 404s — making the
     * route resolve a trashed proxy would surface the shipped **Replay**
     * affordance against a deleted proxy, whose own `POST` route still binds
     * a live one (`Q-11-03(9)`'s proxy half — the degradation is real, not
     * cosmetic).
     */
    public function test_the_events_route_404s_for_a_soft_deleted_proxy_id(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $proxy->delete();
        $this->assertTrue($proxy->trashed());

        $this->actingAs($user)
            ->get(route('proxies.events.index', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
            ]))
            ->assertNotFound();
    }
}
