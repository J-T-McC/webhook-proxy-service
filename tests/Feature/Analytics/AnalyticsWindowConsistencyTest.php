<?php

namespace Tests\Feature\Analytics;

use App\Enums\AnalyticsWindow;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\DeliveryStatistics;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `ProxyEventController`'s resolved window bound must be the same value
 * `AnalyticsWindow::start()` gives `DeliveryStatistics` for an identical
 * window and `now()` (plan-11 Technical ruling 12) — so a drill-through and
 * the figure it was reached from can never silently disagree. Exercised by
 * placing one record exactly at the window's inclusive start (must be
 * counted by both) and one one second earlier (must be excluded by both),
 * at all three windows.
 */
class AnalyticsWindowConsistencyTest extends TestCase
{
    private DeliveryStatistics $statistics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statistics = new DeliveryStatistics;
    }

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    /**
     * @return array{proxy: Proxy, destination: Destination}
     */
    private function makeProxyAndDestination(User $user): array
    {
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();

        return ['proxy' => $proxy, 'destination' => $destination];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsRoute(User $user, Proxy $proxy, AnalyticsWindow $window): string
    {
        return route('proxies.events.index', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'window' => $window->value,
            'outcome' => 'delivery_failed',
        ]);
    }

    public function test_the_events_list_and_the_service_agree_on_the_window_start_at_all_three_windows(): void
    {
        foreach ([AnalyticsWindow::TwentyFourHours, AnalyticsWindow::SevenDays, AnalyticsWindow::ThirtyDays] as $window) {
            $user = $this->actingUser();
            ['proxy' => $proxy, 'destination' => $destination] = $this->makeProxyAndDestination($user);

            $fixedNow = CarbonImmutable::now();
            $this->travelTo($fixedNow);

            $start = $window->start($fixedNow);

            $atStart = WebhookEvent::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $user->current_team_id,
                'received_at' => $start,
            ]);
            Delivery::factory()->state([
                'webhook_event_id' => $atStart->id,
                'team_id' => $user->current_team_id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'status' => DeliveryStatus::Failed,
                'updated_at' => $start,
            ])->createQuietly();

            $justBeforeStart = WebhookEvent::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $user->current_team_id,
                'received_at' => $start->subSecond(),
            ]);
            Delivery::factory()->state([
                'webhook_event_id' => $justBeforeStart->id,
                'team_id' => $user->current_team_id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'status' => DeliveryStatus::Failed,
                'updated_at' => $start->subSecond(),
            ])->createQuietly();

            // The service: exactly one failed delivery in the window.
            $panel = $this->statistics->forProxy($proxy, $window);
            $this->assertSame(1, $panel->delivery->failed, "service failed-delivery count wrong on {$window->value}");

            // The drill-through: the same single record, and no other.
            $this->actingAs($user)
                ->get($this->eventsRoute($user, $proxy, $window))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('events.data', 1)
                    ->where('events.data.0.id', $atStart->id)
                );

            $this->travelBack();
        }
    }
}
