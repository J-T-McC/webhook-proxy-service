<?php

namespace Tests\Feature\Analytics;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `DashboardController`'s analytics props (T13; AC7, AC8, AC17, AC23; plan-11
 * §§ API, Validation, R7). `tests/Feature/DashboardTest.php`'s existing
 * `pendingInvitations` coverage is untouched by this task and is not
 * duplicated here.
 */
class DashboardControllerTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function teamSlug(User $user): string
    {
        return $user->currentTeam->slug;
    }

    /**
     * Delivery + two attempts (one failed, one succeeded) against the given
     * proxy — enough traffic for `statistics`/`proxies` to carry real figures.
     */
    private function makeTraffic(User $user, Proxy $proxy): void
    {
        $destination = Destination::factory()->state([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();

        $delivery = Delivery::factory()->state([
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
            'attempt_number' => 1,
            'status' => AttemptStatus::Failed,
        ])->createQuietly();

        DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery->id,
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'attempt_number' => 2,
            'status' => AttemptStatus::Succeeded,
        ])->createQuietly();
    }

    public function test_absent_window_resolves_to_the_thirty_day_default_with_a_200(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($user)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('statistics.window', '30d')
            );
    }

    public function test_malformed_window_resolves_to_the_thirty_day_default_with_a_200(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($user), 'window' => 'garbage']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('statistics.window', '30d')
            );
    }

    public function test_a_valid_window_is_honoured(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($user), 'window' => '7d']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.window', '7d')
            );
    }

    public function test_a_member_of_another_team_sees_none_of_this_teams_records(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $this->makeTraffic($user, $proxy);

        $otherUser = $this->actingUser();
        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $otherUser->current_team_id]);
        $this->makeTraffic($otherUser, $otherProxy);

        $this->actingAs($otherUser)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($otherUser)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxies', 1)
                ->where('proxies.0.id', $otherProxy->id)
                ->where('statistics.delivery.total', 1)
            );
    }

    public function test_query_count_does_not_grow_with_the_number_of_proxies(): void
    {
        $userOne = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $userOne->current_team_id]);
        $this->makeTraffic($userOne, $proxy);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($userOne)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($userOne)]))
            ->assertOk();

        $countAtOne = count($queries);

        $userTen = $this->actingUser();
        for ($i = 0; $i < 10; $i++) {
            $tenProxy = Proxy::factory()->createQuietly(['team_id' => $userTen->current_team_id]);
            $this->makeTraffic($userTen, $tenProxy);
        }

        $queries = [];
        $this->actingAs($userTen)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($userTen)]))
            ->assertOk();

        $countAtTen = count($queries);

        $this->assertSame($countAtOne, $countAtTen);
    }

    public function test_a_team_with_no_proxies_renders_with_empty_proxies_at_the_same_fixed_query_cost(): void
    {
        // `DeliveryStatistics::proxyBreakdown()` (T8) always issues its two
        // grouped aggregates regardless of proxy count — that's what makes it
        // O(1) rather than N+1 (R7); zero proxies is simply N=0 of that same
        // fixed cost, not a special "no query" branch. This asserts the
        // "no proxies at all" state's backing data (`proxies` empty) and reuses
        // the same fixed-query-cost proof as the N=1-vs-N=10 case above.
        $userZero = $this->actingUser();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($userZero)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($userZero)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxies', 0)
            );

        $countAtZero = count($queries);

        $userOne = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $userOne->current_team_id]);
        $this->makeTraffic($userOne, $proxy);

        $queries = [];
        $this->actingAs($userOne)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($userOne)]))
            ->assertOk();

        $countAtOne = count($queries);

        $this->assertSame($countAtZero, $countAtOne);
    }

    public function test_existing_dashboard_tests_are_unaffected(): void
    {
        // Sanity check that this task's props don't break the unrelated
        // pendingInvitations prop shape — the full existing suite is run
        // separately (tests/Feature/DashboardTest.php, untouched by this task).
        $user = $this->actingUser();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $this->teamSlug($user)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pendingInvitations')
            );
    }
}
