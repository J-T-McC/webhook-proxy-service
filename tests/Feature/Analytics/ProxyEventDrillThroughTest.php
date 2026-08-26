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
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `ProxyEventController::index`'s filter resolver (T21; T23/T24 Revision A,
 * `Q-11-04`; AC10, AC21; plan-11 §§ Architecture E, Technical rulings 3, 8
 * and 10, Validation) — the `window`, `destination`, `outcome` and `date`
 * query parameters, both outcome-subquery shapes, `withQueryString()`, and
 * the unresolved-filter/unfiltered fallbacks.
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

    // --- Day narrowing (`date`, Revision A / plan Technical ruling 10) ------

    /**
     * A `date` inside the resolved window narrows to exactly that day's
     * failing records at the delivery grain — the day cell's figure and its
     * drill-through describe the same record set (AC10 at the day grain).
     * Records the day before and the day after the target day, both also
     * delivery-failed, must not appear.
     */
    public function test_date_narrows_to_exactly_that_days_failing_records_at_delivery_grain(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        $targetDay = CarbonImmutable::now()->subDays(5)->startOfDay();

        $onTargetDay = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $onTargetDay->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->addHours(3),
        ])->createQuietly();

        $dayBefore = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $dayBefore->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->subDay()->addHours(12),
        ])->createQuietly();

        $dayAfter = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $dayAfter->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->addDay()->addHours(1),
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, [
                'outcome' => 'delivery_failed',
                'date' => $targetDay->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $onTargetDay->id)
                ->where('filters.day', $targetDay->format('Y-m-d'))
                ->where('filters.window', '30d')
            );
    }

    /**
     * The same narrowing at the attempt grain, matching on `updated_at`
     * inside `delivery_attempts` rather than `deliveries`.
     */
    public function test_date_narrows_to_exactly_that_days_failing_records_at_attempt_grain(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        $targetDay = CarbonImmutable::now()->subDays(5)->startOfDay();

        $onTargetDay = $this->makeEvent($user, $proxy);
        DeliveryAttempt::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $onTargetDay->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Failed,
            'updated_at' => $targetDay->addHours(3),
        ])->createQuietly();

        $dayBefore = $this->makeEvent($user, $proxy);
        DeliveryAttempt::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $dayBefore->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Failed,
            'updated_at' => $targetDay->subDay()->addHours(12),
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, [
                'outcome' => 'attempt_failed',
                'date' => $targetDay->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $onTargetDay->id)
            );
    }

    /**
     * The day bound is half-open (`>= start`, `< end`), never an inclusive
     * `whereBetween` — a record at the target day's exact midnight and one a
     * second before the next day's midnight both fall inside; one a second
     * before the target day's midnight and one exactly at the next day's
     * midnight both fall outside.
     */
    public function test_date_boundary_is_half_open(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        $targetDay = CarbonImmutable::now()->subDays(5)->startOfDay();

        $atStart = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $atStart->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay,
        ])->createQuietly();

        $justBeforeEnd = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $justBeforeEnd->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->addDay()->subSecond(),
        ])->createQuietly();

        $justBeforeStart = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $justBeforeStart->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->subSecond(),
        ])->createQuietly();

        $atNextMidnight = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $atNextMidnight->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->addDay(),
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, [
                'outcome' => 'delivery_failed',
                'date' => $targetDay->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 2)
                ->where('events.data', fn ($events) => collect($events)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$atStart->id, $justBeforeEnd->id])->sort()->values()->all())
            );
    }

    /**
     * An absent, empty or malformed `date` means no day-narrowing — the
     * request resolves exactly as it does without one, never a 422.
     * `createFromFormat('Y-m-d', ...)` is lenient (accepts `2026-8-4`,
     * silently rolls `2026-13-45` over into a different date), so each of
     * these specifically exercises the round-trip check that catches what
     * lenient parsing alone would not.
     */
    public function test_a_malformed_date_drops_the_day_narrowing_and_never_422(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = $this->makeEvent($user, $proxy, now()->subDays(90));

        foreach (['2026-8-4', 'yesterday', '2026-13-45', now()->toIso8601String(), ''] as $malformed) {
            $this->actingAs($user)
                ->get($this->route($user, $proxy, ['date' => $malformed]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('events.data', 1)
                    ->where('events.data.0.id', $event->id)
                    ->where('filters.day', null)
                );
        }
    }

    /**
     * A well-formed `date` outside the resolved window narrows to that day
     * rather than being dropped or silently widening back to the window —
     * "narrowed to that single day" holds even for a hand-edited or stale
     * URL, and an empty result is visible ("No events match these filters")
     * rather than silently wrong.
     */
    public function test_a_well_formed_date_outside_the_window_narrows_to_that_day(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        // 40 days ago — outside the default 30-day window.
        $targetDay = CarbonImmutable::now()->subDays(40)->startOfDay();

        $outsideWindowButOnTargetDay = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $outsideWindowButOnTargetDay->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->addHours(3),
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, [
                'outcome' => 'delivery_failed',
                'date' => $targetDay->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $outsideWindowButOnTargetDay->id)
            );

        // A day with nothing on it, also outside the window: narrows to a
        // visibly empty result rather than an error or a silent widening.
        $emptyDay = CarbonImmutable::now()->subDays(41)->startOfDay();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, [
                'outcome' => 'delivery_failed',
                'date' => $emptyDay->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 0));
    }

    /**
     * `date` composes conjunctively with `destination` and with each
     * `outcome` unit, exactly as `destination` and `outcome` compose with
     * each other — all resolved independently, all applied together.
     */
    public function test_date_composes_conjunctively_with_destination_and_outcome(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destinationOne = Destination::factory()->state(['team_id' => $user->current_team_id, 'proxy_id' => $proxy->id])->createQuietly();
        $destinationTwo = Destination::factory()->state(['team_id' => $user->current_team_id, 'proxy_id' => $proxy->id])->createQuietly();

        $targetDay = CarbonImmutable::now()->subDays(5)->startOfDay();

        $matching = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $matching->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destinationOne->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->addHours(3),
        ])->createQuietly();

        // Right day, right outcome, wrong destination.
        $wrongDestination = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $wrongDestination->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destinationTwo->id,
            'status' => DeliveryStatus::Failed,
            'updated_at' => $targetDay->addHours(3),
        ])->createQuietly();

        // Right day, right destination, wrong outcome (succeeded).
        $wrongOutcome = $this->makeEvent($user, $proxy);
        Delivery::factory()->state([
            'webhook_event_id' => $wrongOutcome->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destinationOne->id,
            'status' => DeliveryStatus::Succeeded,
            'updated_at' => $targetDay->addHours(3),
        ])->createQuietly();

        $this->actingAs($user)
            ->get($this->route($user, $proxy, [
                'outcome' => 'delivery_failed',
                'destination' => $destinationOne->id,
                'date' => $targetDay->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $matching->id)
                ->where('filters.destination.id', $destinationOne->id)
                ->where('filters.day', $targetDay->format('Y-m-d'))
            );
    }

    /**
     * `?date=` alone, with no `destination` and no `outcome`, still narrows
     * — the "arrived directly" short-circuit widens to require all three of
     * `destination`, `outcome` and `date` to be unresolved before it
     * short-circuits (ruling 10), so a `date` on its own is not swallowed.
     * With no outcome active the bound applies to `received_at` (ruling 3).
     */
    public function test_date_alone_narrows_without_destination_or_outcome(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $targetDay = CarbonImmutable::now()->subDays(5)->startOfDay();

        $onTargetDay = $this->makeEvent($user, $proxy, $targetDay->addHours(6));
        $dayBefore = $this->makeEvent($user, $proxy, $targetDay->subHours(6));

        $this->actingAs($user)
            ->get($this->route($user, $proxy, ['date' => $targetDay->format('Y-m-d')]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $onTargetDay->id)
                ->where('filters.day', $targetDay->format('Y-m-d'))
                ->where('filters.destination', null)
                ->where('filters.outcome', null)
            );

        $this->assertNotSame($onTargetDay->id, $dayBefore->id);
    }

    /**
     * `date` survives pagination via the same `withQueryString()` mechanism
     * already covered for `outcome` alone.
     */
    public function test_date_survives_pagination(): void
    {
        $user = $this->actingUser();
        [$proxy, $destination] = $this->makeProxyAndDestination($user);

        $targetDay = CarbonImmutable::now()->subDays(5)->startOfDay();

        for ($i = 0; $i < 20; $i++) {
            $event = $this->makeEvent($user, $proxy);
            Delivery::factory()->state([
                'webhook_event_id' => $event->id,
                'team_id' => $user->current_team_id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'status' => DeliveryStatus::Failed,
                'updated_at' => $targetDay->addHours($i % 24),
            ])->createQuietly();
        }

        $this->actingAs($user)
            ->get($this->route($user, $proxy, [
                'outcome' => 'delivery_failed',
                'date' => $targetDay->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 15)
                ->where('events.last_page', 2)
                ->where('events.links', fn ($links) => collect($links)->contains(
                    fn ($link) => str_contains((string) ($link['url'] ?? ''), 'date='.$targetDay->format('Y-m-d')),
                ))
            );
    }
}
