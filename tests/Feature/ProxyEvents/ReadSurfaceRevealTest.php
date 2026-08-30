<?php

namespace Tests\Feature\ProxyEvents;

use App\Actions\AdvanceProxyFifoQueue;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T43 — end-to-end proof of the three read routes (T26/T27) and the payload
 * endpoint (T28) — PRD-06 AC22, AC25; PRD-05 AC16 — complementing T25-T28's
 * own per-task tests by composing the real controllers/resources together
 * rather than one route/case at a time.
 */
class ReadSurfaceRevealTest extends TestCase
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

    private function indexRoute(User $user, Proxy $proxy): string
    {
        return route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]);
    }

    private function showRoute(User $user, Proxy $proxy, WebhookEvent $event): string
    {
        return route('proxies.events.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $event->id]);
    }

    private function payloadRoute(User $user, Proxy $proxy, WebhookEvent $event): string
    {
        return route('proxies.events.payload', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $event->id]);
    }

    // --- AC22/AC25: no content on the list/detail props ---------------------

    public function test_list_and_detail_never_emit_body_or_headers_under_any_state(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $retained = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id, 'body' => 'secret-body-marker']);
        $cleaned = WebhookEvent::factory()->cleaned()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $retained->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
        ]);

        $indexResponse = $this->actingAs($user)->get($this->indexRoute($user, $proxy));
        $indexResponse->assertInertia(fn (Assert $page) => $page
            ->missing('events.data.0.body')
            ->missing('events.data.0.headers')
            ->missing('events.data.1.body')
            ->missing('events.data.1.headers'));
        $this->assertStringNotContainsString('secret-body-marker', $indexResponse->getContent() ?: '');

        $showResponse = $this->actingAs($user)->get($this->showRoute($user, $proxy, $retained));
        $showResponse->assertInertia(fn (Assert $page) => $page->missing('event.body')->missing('event.headers'));
        $this->assertStringNotContainsString('secret-body-marker', $showResponse->getContent() ?: '');

        // review-06 Minor 5 (rider 2): DeliveryResource now carries
        // `created_at` — the events-detail replay-group label/ordering
        // derives from it directly (T12). The prior "gap" this pinned is
        // closed; assert presence, not absence.
        $showResponse->assertInertia(fn (Assert $page) => $page->has('event.deliveries.0.created_at'));
    }

    // --- AC22/AC25: the payload (reveal) endpoint, the full matrix ----------

    public function test_payload_endpoint_retained_cleaned_unknown_cross_team_and_unauthenticated(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $retained = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id, 'body' => 'the-exact-bytes']);
        $cleaned = WebhookEvent::factory()->cleaned()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $crossProxyEvent = WebhookEvent::factory()->createQuietly(['proxy_id' => $otherProxy->id, 'team_id' => $otherProxy->team_id]);

        $otherTeamProxy = Proxy::factory()->createQuietly();
        $crossTeamEvent = WebhookEvent::factory()->createQuietly(['proxy_id' => $otherTeamProxy->id, 'team_id' => $otherTeamProxy->team_id]);

        // Unauthenticated: redirected to login, never served. Asserted first,
        // before any `actingAs()` call below — the test client's
        // authentication persists across requests within a test once set.
        $this->get($this->payloadRoute($user, $proxy, $retained))->assertRedirect(route('login'));

        // Retained: exact bytes, the three documented headers.
        $this->actingAs($user)
            ->get($this->payloadRoute($user, $proxy, $retained))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertContent('the-exact-bytes');

        // Cleaned: 410, lifecycle not an error.
        $this->actingAs($user)
            ->get($this->payloadRoute($user, $proxy, $cleaned))
            ->assertStatus(410);

        // Unknown event id for this proxy: 404 (route-model-binding scoped to
        // this proxy's own events).
        $this->actingAs($user)
            ->get(route('proxies.events.payload', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => 999999]))
            ->assertStatus(404);

        // Another proxy's event id (same team) — scoped binding 404s.
        $this->actingAs($user)
            ->get($this->payloadRoute($user, $proxy, $crossProxyEvent))
            ->assertStatus(404);

        // Another team's event id entirely — 404.
        $this->actingAs($user)
            ->get($this->payloadRoute($user, $proxy, $crossTeamEvent))
            ->assertStatus(404);
    }

    public function test_a_member_who_did_not_create_the_proxy_can_reveal_its_payload_no_distinct_reveal_permission(): void
    {
        $team = Team::factory()->createQuietly();
        $creator = User::factory()->createQuietly();
        $team->members()->attach($creator, ['role' => TeamRole::Owner->value]);
        $member = User::factory()->createQuietly();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $creator->id]);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $team->id, 'body' => 'member-can-read-this']);

        $this->actingAs($member)
            ->get($this->payloadRoute($member, $proxy, $event))
            ->assertOk()
            ->assertContent('member-can-read-this');
    }

    // --- AC15/AC16: events list, newest-first, descriptor fields, empty -----

    public function test_events_list_paginates_newest_first_with_descriptor_fields_and_the_empty_state_renders(): void
    {
        $user = $this->actingUser();
        $emptyProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get($this->indexRoute($user, $emptyProxy))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 0)
                ->where('events.total', 0));

        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $older = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'method' => 'POST',
            'content_type' => 'application/json',
            'byte_size' => 42,
        ]);
        $newer = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        $this->actingAs($user)
            ->get($this->indexRoute($user, $proxy))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.id', $newer->id)
                ->where('events.data.1.id', $older->id)
                ->where('events.data.1.method', 'POST')
                ->where('events.data.1.content_type', 'application/json')
                ->where('events.data.1.byte_size', 42)
                ->has('events.data.1.received_at'));
    }

    // --- AC15/AC16: fifoHeldByRetry, true iff FIFO + a live awaiting_retry row --

    public function test_fifo_held_by_retry_is_true_only_when_a_real_retry_cascade_leaves_an_awaiting_retry_row(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Fifo,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 3,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $dispatch = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $event->ingest_id,
        ]);

        $this->actingAs($user)
            ->get($this->indexRoute($user, $proxy))
            ->assertInertia(fn (Assert $page) => $page->where('fifoHeldByRetry', false));

        // Head's first attempt fails inline, for real — the line holds.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $dispatch->fresh()->status);

        $this->actingAs($user)
            ->get($this->indexRoute($user, $proxy))
            ->assertInertia(fn (Assert $page) => $page->where('fifoHeldByRetry', true));
    }

    // --- Legacy fallback (ruling 3): multiple destinations, no exception ----

    public function test_a_pre_6_event_with_multiple_destination_outcomes_renders_the_derived_state_on_both_list_and_detail(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $delivered = Destination::factory()->for($proxy)->createQuietly();
        $failed = Destination::factory()->for($proxy)->createQuietly();
        $inFlight = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $delivered->id,
            'ingest_id' => $event->ingest_id,
            'status' => AttemptStatus::Succeeded,
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $failed->id,
            'ingest_id' => $event->ingest_id,
            'status' => AttemptStatus::Failed,
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $inFlight->id,
            'ingest_id' => $event->ingest_id,
            'status' => AttemptStatus::Dispatched,
        ]);

        // No exception, no synthetic Delivery row, on the list route.
        $indexResponse = $this->actingAs($user)->get($this->indexRoute($user, $proxy));
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn (Assert $page) => $page
            ->where('events.data.0.deliveries', fn ($deliveries) => collect($deliveries)->pluck('status')->sort()->values()->all()
                === collect([DeliveryStatus::Succeeded->value, DeliveryStatus::Failed->value, DeliveryStatus::Retrying->value])->sort()->values()->all()));
        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());

        // Same derivation, same no-exception guarantee, on the detail route.
        $showResponse = $this->actingAs($user)->get($this->showRoute($user, $proxy, $event));
        $showResponse->assertOk();
        $showResponse->assertInertia(fn (Assert $page) => $page
            ->where('event.deliveries', fn ($deliveries) => collect($deliveries)->pluck('status')->sort()->values()->all()
                === collect([DeliveryStatus::Succeeded->value, DeliveryStatus::Failed->value, DeliveryStatus::Retrying->value])->sort()->values()->all()));
        $this->assertSame(0, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }
}
