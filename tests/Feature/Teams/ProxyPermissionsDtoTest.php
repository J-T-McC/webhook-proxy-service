<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProxyPermissionsDtoTest extends TestCase
{
    /**
     * @return iterable<string, array{TeamRole}>
     */
    public static function roleProvider(): iterable
    {
        yield 'owner' => [TeamRole::Owner];
        yield 'admin' => [TeamRole::Admin];
        yield 'member' => [TeamRole::Member];
    }

    #[DataProvider('roleProvider')]
    public function test_to_proxy_permissions_reads_from_the_role_bundle(TeamRole $role): void
    {
        $team = Team::factory()->createQuietly();
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);

        $permissions = $user->toProxyPermissions($team);

        // Every role holds create + view today (AC3), proving the DTO reads the
        // bundle rather than hardcoding true.
        $this->assertTrue($permissions->canCreateProxy);
        $this->assertTrue($permissions->canViewProxy);
    }

    public function test_a_non_member_gets_all_false(): void
    {
        $team = Team::factory()->createQuietly();
        $stranger = User::factory()->createQuietly();

        $permissions = $stranger->toProxyPermissions($team);

        $this->assertFalse($permissions->canCreateProxy);
        $this->assertFalse($permissions->canViewProxy);
    }
}
