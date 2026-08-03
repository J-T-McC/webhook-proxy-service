<?php

namespace Tests\Feature\Proxies;

use App\Enums\TeamRole;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ProxyPolicyTest extends TestCase
{
    /**
     * Create a user attached to the given team with the given role, switched to it.
     */
    private function member(Team $team, TeamRole $role): User
    {
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_all_roles_may_view_and_create(): void
    {
        $team = Team::factory()->createQuietly();

        foreach ([TeamRole::Owner, TeamRole::Admin, TeamRole::Member] as $role) {
            $user = $this->member($team, $role);
            $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);

            $this->assertTrue(Gate::forUser($user)->allows('view', $proxy), "{$role->value} may view");
            $this->assertTrue(Gate::forUser($user)->allows('create', Proxy::class), "{$role->value} may create");
        }
    }

    public function test_member_may_update_and_delete_only_their_own_proxy(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $teammate = $this->member($team, TeamRole::Member);

        $own = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $member->id]);
        $teammates = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $teammate->id]);
        $ownerless = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => null]);

        // Own proxy: allowed (AC6).
        $this->assertTrue(Gate::forUser($member)->allows('update', $own));
        $this->assertTrue(Gate::forUser($member)->allows('delete', $own));

        // Teammate's proxy: denied despite holding the base CRUD permission (AC5).
        $this->assertFalse(Gate::forUser($member)->allows('update', $teammates));
        $this->assertFalse(Gate::forUser($member)->allows('delete', $teammates));

        // Null-creator proxy: fail-closed deny for the ownership-limited role.
        $this->assertFalse(Gate::forUser($member)->allows('update', $ownerless));
        $this->assertFalse(Gate::forUser($member)->allows('delete', $ownerless));
    }

    public function test_admin_and_owner_may_update_and_delete_any_team_proxy(): void
    {
        $team = Team::factory()->createQuietly();
        $creator = $this->member($team, TeamRole::Member);
        $admin = $this->member($team, TeamRole::Admin);
        $owner = $this->member($team, TeamRole::Owner);

        // A proxy neither the admin nor the owner created.
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $creator->id]);
        // And a null-creator proxy — Admin/Owner still manage via the bypass.
        $ownerless = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => null]);

        foreach ([$admin, $owner] as $privileged) {
            $this->assertTrue(Gate::forUser($privileged)->allows('update', $proxy));
            $this->assertTrue(Gate::forUser($privileged)->allows('delete', $proxy));
            $this->assertTrue(Gate::forUser($privileged)->allows('update', $ownerless));
            $this->assertTrue(Gate::forUser($privileged)->allows('delete', $ownerless));
        }
    }

    public function test_a_role_on_a_different_team_confers_no_permission_on_this_proxy(): void
    {
        // The proxy's owning team.
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);

        // A user who is an Owner of an entirely different team, and not a member here.
        $otherTeam = Team::factory()->createQuietly();
        $outsider = $this->member($otherTeam, TeamRole::Owner);

        // AC4: permission is evaluated on the team that owns the proxy.
        $this->assertFalse(Gate::forUser($outsider)->allows('view', $proxy));
        $this->assertFalse(Gate::forUser($outsider)->allows('update', $proxy));
        $this->assertFalse(Gate::forUser($outsider)->allows('delete', $proxy));
    }
}
