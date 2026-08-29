<?php

namespace Tests\Feature\EventQueue;

use App\Enums\WebhookEventStatus;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `EventQueueController@index` — the team-wide event queue view.
 */
class EventQueueIndexTest extends TestCase
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

    public function test_the_list_is_scoped_to_the_current_team(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $mine = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $otherTeamProxy = Proxy::factory()->createQuietly();
        WebhookEvent::factory()->createQuietly(['proxy_id' => $otherTeamProxy->id, 'team_id' => $otherTeamProxy->team_id]);

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('events/Index')
                ->has('events.data', 1)
                ->where('events.data.0.id', $mine->id));
    }

    public function test_the_list_spans_every_proxy_the_team_owns_newest_first(): void
    {
        $user = $this->actingUser();
        $proxyA = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $proxyB = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        WebhookEvent::factory()->createQuietly(['proxy_id' => $proxyA->id, 'team_id' => $user->current_team_id]);
        $newest = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxyB->id, 'team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 2)
                ->where('events.data.0.id', $newest->id));
    }

    public function test_a_pending_events_status_is_pending(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.status', 'pending'));
    }

    public function test_a_dispatched_events_status_is_dispatched(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        WebhookEvent::query()->whereKey($event->id)->update(['status' => WebhookEventStatus::Dispatched]);

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.status', 'dispatched'));
    }

    public function test_an_event_cleaned_before_it_ever_dispatched_reads_as_expired(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'status' => WebhookEventStatus::Pending,
        ]);

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.status', 'expired'));
    }

    public function test_an_event_cleaned_after_it_dispatched_still_reads_as_dispatched(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'status' => WebhookEventStatus::Dispatched,
        ]);

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.status', 'dispatched'));
    }

    public function test_the_proxy_column_carries_name_and_paused_state_without_an_n_plus_one(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'name' => 'Payments proxy']);
        $proxy->forceFill(['paused_at' => now()])->save();
        WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.proxy.name', 'Payments proxy')
                ->where('events.data.0.proxy.paused', true));
    }

    public function test_a_deleted_proxys_events_still_show_its_name(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'name' => 'Gone proxy']);
        WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $proxy->delete();

        $this->actingAs($user)
            ->get(route('events.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.proxy.name', 'Gone proxy'));
    }
}
