<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The page-level ProxyPermissions DTO reads every boolean from the role bundle
 * (ADR-009 §4 tier 1, Amendment B4). Create/view/update/delete are held by all
 * three roles (AC3); the `-any` ownership-bypass booleans distinguish Admin/Owner
 * from Member — the differentiator the client uses to compose per-record affordances.
 */
class ProxyPermissionsDtoTest extends TestCase
{
    private function userWithRole(TeamRole $role): array
    {
        $team = Team::factory()->createQuietly();
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);

        return [$user, $team];
    }

    public function test_member_holds_full_crud_but_not_the_any_bypass(): void
    {
        [$user, $team] = $this->userWithRole(TeamRole::Member);

        $permissions = $user->toProxyPermissions($team);

        $this->assertTrue($permissions->canCreateProxy);
        $this->assertTrue($permissions->canViewProxy);
        $this->assertTrue($permissions->canUpdateProxy);
        $this->assertTrue($permissions->canDeleteProxy);
        // Member acts only on proxies they created — no ownership bypass.
        $this->assertFalse($permissions->canUpdateAnyProxy);
        $this->assertFalse($permissions->canDeleteAnyProxy);
        // Replay has no ownership limit at all (AC14; ADR-017 Decision 4).
        $this->assertTrue($permissions->canReplayProxy);
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
    public function test_admin_and_owner_hold_full_crud_and_both_any_bypasses(TeamRole $role): void
    {
        [$user, $team] = $this->userWithRole($role);

        $permissions = $user->toProxyPermissions($team);

        $this->assertTrue($permissions->canCreateProxy);
        $this->assertTrue($permissions->canViewProxy);
        $this->assertTrue($permissions->canUpdateProxy);
        $this->assertTrue($permissions->canDeleteProxy);
        $this->assertTrue($permissions->canUpdateAnyProxy);
        $this->assertTrue($permissions->canDeleteAnyProxy);
        $this->assertTrue($permissions->canReplayProxy);
    }

    public function test_a_non_member_gets_all_false(): void
    {
        $team = Team::factory()->createQuietly();
        $stranger = User::factory()->createQuietly();

        $permissions = $stranger->toProxyPermissions($team);

        $this->assertFalse($permissions->canCreateProxy);
        $this->assertFalse($permissions->canViewProxy);
        $this->assertFalse($permissions->canUpdateProxy);
        $this->assertFalse($permissions->canDeleteProxy);
        $this->assertFalse($permissions->canUpdateAnyProxy);
        $this->assertFalse($permissions->canDeleteAnyProxy);
        $this->assertFalse($permissions->canReplayProxy);
    }
}
