<?php

namespace Tests\Unit\Enums;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TeamRoleTest extends TestCase
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
    public function test_every_role_holds_the_full_proxy_crud_bundle(TeamRole $role): void
    {
        // AC3: Owner/Admin/Member alike hold the four base CRUD permissions.
        $this->assertTrue($role->hasPermission(TeamPermission::ViewProxy));
        $this->assertTrue($role->hasPermission(TeamPermission::CreateProxy));
        $this->assertTrue($role->hasPermission(TeamPermission::UpdateProxy));
        $this->assertTrue($role->hasPermission(TeamPermission::DeleteProxy));
    }

    public function test_ownership_bypass_cases_differentiate_the_roles(): void
    {
        // AC6: Owner/Admin unrestricted via -any bypass; Member without it.
        $this->assertTrue(TeamRole::Owner->hasPermission(TeamPermission::UpdateAnyProxy));
        $this->assertTrue(TeamRole::Owner->hasPermission(TeamPermission::DeleteAnyProxy));

        $this->assertTrue(TeamRole::Admin->hasPermission(TeamPermission::UpdateAnyProxy));
        $this->assertTrue(TeamRole::Admin->hasPermission(TeamPermission::DeleteAnyProxy));

        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::UpdateAnyProxy));
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::DeleteAnyProxy));
    }

    public function test_existing_team_administration_permissions_are_unchanged(): void
    {
        // AC9 regression guard: proxy additions must not alter team-admin truth values.
        $this->assertTrue(TeamRole::Admin->hasPermission(TeamPermission::UpdateTeam));
        $this->assertTrue(TeamRole::Admin->hasPermission(TeamPermission::CreateInvitation));
        $this->assertTrue(TeamRole::Admin->hasPermission(TeamPermission::CancelInvitation));
        // Admin never held team deletion or member management.
        $this->assertFalse(TeamRole::Admin->hasPermission(TeamPermission::DeleteTeam));
        $this->assertFalse(TeamRole::Admin->hasPermission(TeamPermission::AddMember));
        $this->assertFalse(TeamRole::Admin->hasPermission(TeamPermission::UpdateMember));
        $this->assertFalse(TeamRole::Admin->hasPermission(TeamPermission::RemoveMember));

        // Member held no team-admin permission before and still holds none.
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::UpdateTeam));
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::DeleteTeam));
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::AddMember));
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::UpdateMember));
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::RemoveMember));
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::CreateInvitation));
        $this->assertFalse(TeamRole::Member->hasPermission(TeamPermission::CancelInvitation));

        // Owner holds every case (TeamPermission::cases()).
        $this->assertTrue(TeamRole::Owner->hasPermission(TeamPermission::UpdateTeam));
        $this->assertTrue(TeamRole::Owner->hasPermission(TeamPermission::DeleteTeam));
        $this->assertTrue(TeamRole::Owner->hasPermission(TeamPermission::RemoveMember));
    }
}
