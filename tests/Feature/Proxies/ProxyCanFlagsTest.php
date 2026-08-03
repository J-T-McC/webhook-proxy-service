<?php

namespace Tests\Feature\Proxies;

use App\Enums\TeamRole;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Per-record can.update/can.delete flags on ProxyResource (ADR-009 Amendment A5).
 * The flags are computed by the policy, so they mirror the server gate exactly:
 * a Member sees them on their own proxy only; Admin/Owner on every proxy.
 */
class ProxyCanFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function member(Team $team, TeamRole $role): User
    {
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_member_can_flags_are_true_only_on_their_own_proxy_in_the_index_list(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $teammate = $this->member($team, TeamRole::Member);

        $own = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $member->id]);
        $foreign = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $teammate->id]);

        $this->actingAs($member)
            ->get(route('proxies.index', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxies.data', 2)
                ->where('proxies.data', function (Collection $rows) use ($own, $foreign) {
                    $byId = $rows->keyBy('id');

                    return $byId[$own->id]['can']['update'] === true
                        && $byId[$own->id]['can']['delete'] === true
                        && $byId[$foreign->id]['can']['update'] === false
                        && $byId[$foreign->id]['can']['delete'] === false;
                })
            );
    }

    public function test_member_can_flags_on_the_show_payload_track_ownership(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $teammate = $this->member($team, TeamRole::Member);

        $own = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $member->id]);
        $foreign = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $teammate->id]);

        $this->actingAs($member)
            ->get(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $own->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.can.update', true)
                ->where('proxy.can.delete', true)
            );

        $this->actingAs($member)
            ->get(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $foreign->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.can.update', false)
                ->where('proxy.can.delete', false)
            );
    }

    /**
     * @return iterable<string, array{TeamRole}>
     */
    public static function privilegedRoleProvider(): iterable
    {
        yield 'admin' => [TeamRole::Admin];
        yield 'owner' => [TeamRole::Owner];
    }

    #[DataProvider('privilegedRoleProvider')]
    public function test_admin_and_owner_can_flags_are_true_regardless_of_creator(TeamRole $role): void
    {
        $team = Team::factory()->createQuietly();
        $creator = $this->member($team, TeamRole::Member);
        $privileged = $this->member($team, $role);

        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $creator->id]);

        $this->actingAs($privileged)
            ->get(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.can.update', true)
                ->where('proxy.can.delete', true)
            );
    }
}
