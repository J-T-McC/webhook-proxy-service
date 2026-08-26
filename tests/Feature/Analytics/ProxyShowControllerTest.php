<?php

namespace Tests\Feature\Analytics;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\TeamPermission;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Policies\ProxyPolicy;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `ProxyController::show`'s analytics props (T18; AC7, AC15, AC17, AC23;
 * plan-11 §§ API, Validation, R7; flagged design call 3's carried-window).
 * `tests/Feature/Proxies/ProxyIndexShowTest.php`'s existing `proxy`/
 * `permissions` coverage is untouched by this task and is not duplicated
 * here.
 */
class ProxyShowControllerTest extends TestCase
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
     * One destination, one delivery, two attempts (one failed, one
     * succeeded) against the given proxy — enough traffic for `statistics`/
     * `destinations` to carry real figures.
     */
    private function makeTraffic(User $user, Proxy $proxy): Destination
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

        return $destination;
    }

    public function test_absent_window_resolves_to_the_thirty_day_default_with_a_200(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Show')
                ->where('statistics.window', '30d')
            );
    }

    public function test_malformed_window_resolves_to_the_thirty_day_default_with_a_200(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', [
                'current_team' => $this->teamSlug($user),
                'proxy' => $proxy->id,
                'window' => 'garbage',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.window', '30d')
            );
    }

    public function test_a_valid_window_is_honoured(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', [
                'current_team' => $this->teamSlug($user),
                'proxy' => $proxy->id,
                'window' => '7d',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.window', '7d')
            );
    }

    public function test_statistics_and_destinations_are_scoped_to_this_proxy(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = $this->makeTraffic($user, $proxy);

        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $this->makeTraffic($user, $otherProxy);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.delivery.total', 1)
                ->has('destinations', 1)
                ->where('destinations.0.id', $destination->id)
            );
    }

    /**
     * Every real role holds `ViewProxy` today, so a role-based denial is
     * unreachable — this proves `authorize('view', $proxy)` is genuinely
     * wired (defense-in-depth) rather than merely present in the diff, the
     * same technique `ProxyAuthorizationTest::
     * test_store_is_denied_when_the_create_policy_denies` already
     * establishes for `store()`. No new permission exists for this task:
     * `TeamPermission::cases()` is asserted unchanged below, independently.
     */
    public function test_denied_when_the_view_policy_denies(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->partialMock(ProxyPolicy::class, function (MockInterface $mock): void {
            $mock->shouldReceive('view')->andReturn(false);
        });

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertForbidden();
    }

    /**
     * AC24/D-11-2: this task adds no new permission. `TeamPermission::cases()`
     * is the exhaustive, unchanged set — a diff introducing a new case would
     * fail this assertion.
     */
    public function test_team_permission_cases_are_unchanged_by_this_task(): void
    {
        $this->assertSame([
            'team:update',
            'team:delete',
            'member:add',
            'member:update',
            'member:remove',
            'invitation:create',
            'invitation:cancel',
            'proxy:view',
            'proxy:create',
            'proxy:update',
            'proxy:delete',
            'proxy:update-any',
            'proxy:delete-any',
            'proxy:replay',
        ], array_map(fn (TeamPermission $permission) => $permission->value, TeamPermission::cases()));
    }

    public function test_query_count_does_not_grow_with_the_number_of_destinations(): void
    {
        $userOne = $this->actingUser();
        $proxyOne = Proxy::factory()->createQuietly(['team_id' => $userOne->current_team_id]);
        $this->makeTraffic($userOne, $proxyOne);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($userOne)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($userOne), 'proxy' => $proxyOne->id]))
            ->assertOk();

        $countAtOne = count($queries);

        $userTen = $this->actingUser();
        $proxyTen = Proxy::factory()->createQuietly(['team_id' => $userTen->current_team_id]);
        for ($i = 0; $i < 10; $i++) {
            $destination = Destination::factory()->state([
                'team_id' => $userTen->current_team_id,
                'proxy_id' => $proxyTen->id,
            ])->createQuietly();

            $delivery = Delivery::factory()->state([
                'team_id' => $userTen->current_team_id,
                'proxy_id' => $proxyTen->id,
                'destination_id' => $destination->id,
                'status' => DeliveryStatus::Succeeded,
            ])->createQuietly();

            DeliveryAttempt::factory()->state([
                'delivery_id' => $delivery->id,
                'team_id' => $userTen->current_team_id,
                'proxy_id' => $proxyTen->id,
                'destination_id' => $destination->id,
                'attempt_number' => 1,
                'status' => AttemptStatus::Succeeded,
            ])->createQuietly();
        }

        $queries = [];
        $this->actingAs($userTen)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($userTen), 'proxy' => $proxyTen->id]))
            ->assertOk();

        $countAtTen = count($queries);

        $this->assertSame($countAtOne, $countAtTen);
    }

    public function test_existing_show_props_are_unaffected(): void
    {
        // Sanity check that this task's props don't disturb the unrelated
        // `proxy`/`permissions` prop shapes — the full existing
        // ProxyIndexShowTest suite is run separately and is untouched by
        // this task.
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxy.id')
                ->has('permissions')
            );
    }
}
