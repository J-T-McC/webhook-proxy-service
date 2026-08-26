<?php

namespace Tests\Feature\ProxyEvents;

use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T26 — `ProxyEventController@index` (AC15, AC16; ADR-017 Decision 5): the
 * paginated events list, the per-event payload state, and `fifoHeldByRetry`.
 */
class ProxyEventIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Backend-first: assert Inertia props without the Vue page (built in M9).
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function route(User $user, Proxy $proxy): string
    {
        return route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]);
    }

    // --- Pagination -----------------------------------------------------

    public function test_the_list_paginates_newest_first(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $events = WebhookEvent::factory()->count(17)->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $newest = $events->last();

        $this->actingAs($user)
            ->get($this->route($user, $proxy))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/events/Index')
                ->has('events.data', 15)
                ->where('events.data.0.id', $newest->id)
                ->where('events.last_page', 2)
            );
    }

    // --- Payload states ---------------------------------------------------

    public function test_a_retained_events_payload_state_is_retained(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.payload_state', 'retained'));
    }

    public function test_a_cleaned_events_payload_state_is_cleaned(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        WebhookEvent::factory()->cleaned()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.payload_state', 'cleaned'));
    }

    // --- fifoHeldByRetry ----------------------------------------------------

    public function test_fifo_held_by_retry_is_true_for_a_fifo_proxy_with_an_awaiting_retry_row(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'processing_mode' => ProcessingMode::Fifo]);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'status' => FifoDispatchStatus::AwaitingRetry,
        ]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy))
            ->assertInertia(fn (Assert $page) => $page->where('fifoHeldByRetry', true));
    }

    public function test_fifo_held_by_retry_is_false_for_a_fifo_proxy_without_an_awaiting_retry_row(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'processing_mode' => ProcessingMode::Fifo]);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'status' => FifoDispatchStatus::Pending,
        ]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy))
            ->assertInertia(fn (Assert $page) => $page->where('fifoHeldByRetry', false));
    }

    public function test_fifo_held_by_retry_is_always_false_for_an_async_proxy(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'processing_mode' => ProcessingMode::Async]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy))
            ->assertInertia(fn (Assert $page) => $page->where('fifoHeldByRetry', false));
    }

    // --- Auth / scoping -------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->get($this->route($user, $proxy))->assertRedirect(route('login'));
    }

    public function test_a_non_member_is_denied(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $stranger = User::factory()->createQuietly();
        $stranger->switchTeam($stranger->currentTeam);

        $this->actingAs($stranger)
            ->get(route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertStatus(404);
    }

    public function test_a_cross_team_proxy_id_returns_404(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->createQuietly();
        $foreignProxy = Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $foreignProxy->id]))
            ->assertNotFound();
    }
}
