<?php

namespace Tests\Feature\Analytics;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `ProxyEventController::index`'s filter resolver (T21; AC10, AC21; plan-11
 * §§ Architecture E, Technical rulings 3 and 8, Validation) — the `window`,
 * `destination` and `outcome` query parameters, both outcome-subquery
 * shapes, `withQueryString()`, and the unresolved-filter/unfiltered
 * fallbacks.
 */
class ProxyEventDrillThroughTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function route(User $user, Proxy $proxy, array $query = []): string
    {
        return route('proxies.events.index', array_merge([
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ], $query));
    }

    private function makeProxyAndDestination(User $user): array
    {
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();

        return [$proxy, $destination];
    }

    private function makeEvent(User $user, Proxy $proxy, ?\DateTimeInterface $receivedAt = null): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $user->current_team_id,
            'received_at' => $receivedAt ?? now(),
        ]);
    }

    // --- Delivery-grain outcome filter -----------------------------------

    public function test_delivery_grain_outcome_filter_returns_exactly_the_matching_events(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        $matching = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $matching->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
        ])->createQuietly();

        $nonMatching = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $nonMatching->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['outcome' => 'delivery_failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $matching->id)
                ->where('filters.outcome.unit', 'delivery')
                ->where('filters.outcome.label', 'Terminal failure (deliveries)')
            );
    }

    // --- Attempt-grain outcome filter -------------------------------------

    /**
     * Attempt-grain must include an event whose overall delivery succeeded
     * on a later attempt (a failed attempt still exists in the window) and a
     * pre-#6 attempt row (`delivery_id = NULL`) — never a `whereNotNull` to
     * forget (`Q-11-03(4)`).
     */
    public function test_attempt_grain_outcome_filter_includes_eventual_success_and_pre_six_rows(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        // Eventually succeeded: attempt 1 failed, attempt 2 succeeded — the
        // delivery's own status is `succeeded`, but a failed attempt exists.
        $eventualSuccess = $this->makeEvent($user, $proxy);
        $delivery = Delivery::factory()->state([
            'webhook_event_id' => $eventualSuccess->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();
        DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $eventualSuccess->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Failed,
        ])->createQuietly();
        DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $eventualSuccess->ingest_id,
            'attempt_number' => 2,
            'status' => AttemptStatus::Succeeded,
        ])->createQuietly();

        // Pre-#6: no `deliveries` row at all, only a `delivery_attempts` row
        // with `delivery_id = NULL`.
        $preSix = $this->makeEvent($user, $proxy);
        DeliveryAttempt::factory()->state([
            'delivery_id' => null,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $preSix->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Failed,
        ])->createQuietly();

        // Control: an event with only a succeeded attempt — never matches.
        $nonMatching = $this->makeEvent($user, $proxy);
        DeliveryAttempt::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $nonMatching->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Succeeded,
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['outcome' => 'attempt_failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 2)
                ->where('filters.outcome.unit', 'attempt')
                ->where('filters.outcome.label', 'Terminal failure (attempts)')
                ->where('events.data', fn ($events) => collect($events)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$eventualSuccess->id, $preSix->id])->sort()->values()->all())
            );
    }

    // --- Window travels on the figure's own anchor, not received_at -------

    /**
     * Plan Technical ruling 3: with an Outcome chip active, the window moves
     * onto `updated_at` inside the subquery — a delivery terminalized today
     * from an event received outside the window still matches.
     */
    public function test_outcome_filter_window_reads_updated_at_not_received_at(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        $event = $this->makeEvent($user, $proxy, now()->subDays(40));
        Delivery::factory()->state([
            'webhook_event_id' => $event->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['outcome' => 'delivery_failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $event->id)
            );
    }

    // --- Destination filter -------------------------------------------------

    public function test_destination_filter_narrows_to_events_with_a_delivery_to_that_destination(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destinationOne = Destination::factory()->state(['team_id' => $user->current_team_id, 'proxy_id' => $proxy->id])->createQuietly();
        $destinationTwo = Destination::factory()->state(['team_id' => $user->current_team_id, 'proxy_id' => $proxy->id])->createQuietly();

        $matching = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $matching->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destinationOne->id,
        ])->createQuietly();

        $nonMatching = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $nonMatching->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destinationTwo->id,
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['destination' => $destinationOne->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $matching->id)
                ->where('filters.destination.id', $destinationOne->id)
                ->where('filters.destination.isDeleted', false)
            );
    }

    // --- Unresolved filters drop silently, never a 422 ---------------------

    public function test_an_unknown_destination_id_drops_the_filter_and_renders_no_chip(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = $this->makeEvent($user, $proxy);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['destination' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $event->id)
                ->where('filters.destination', null)
            );
    }

    public function test_an_unknown_outcome_token_drops_the_filter_and_renders_no_chip(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = $this->makeEvent($user, $proxy);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['outcome' => 'bogus']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $event->id)
                ->where('filters.outcome', null)
            );
    }

    // --- Unfiltered arrival is byte-identical to the pre-#11 surface -------

    public function test_no_filter_parameter_renders_every_event_unfiltered(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $recent = $this->makeEvent($user, $proxy, now());
        $old = $this->makeEvent($user, $proxy, now()->subDays(90));

        $this->actingAs($user)
            ->get($this->route($user, $proxy))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/events/Index')
                ->has('events.data', 2)
                ->where('filters.destination', null)
                ->where('filters.outcome', null)
                ->where('events.data', fn ($events) => collect($events)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$recent->id, $old->id])->sort()->values()->all())
            );
    }

    // --- Pagination carries the active filters forward ----------------------

    public function test_filters_survive_pagination_via_with_query_string(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        for ($i = 0; $i < 20; $i++) {
            $event = $this->makeEvent($user, $proxy);
            Delivery::factory()->state([
                'webhook_event_id' => $event->id,
                'team_id' => $user->current_team_id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'status' => DeliveryStatus::Failed,
            ])->createQuietly();
        }

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['outcome' => 'delivery_failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 15)
                ->where('events.last_page', 2)
                ->where('events.links', fn ($links) => collect($links)->contains(
                    fn ($link) => str_contains((string) ($link['url'] ?? ''), 'outcome=delivery_failed'),
                ))
            );
    }
}
