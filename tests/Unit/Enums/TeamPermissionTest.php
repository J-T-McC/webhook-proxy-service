<?php

namespace Tests\Unit\Enums;

use App\Enums\TeamPermission;
use PHPUnit\Framework\TestCase;

class TeamPermissionTest extends TestCase
{
    public function test_enum_has_exactly_the_expected_case_set_with_backing_values(): void
    {
        $actual = array_map(
            fn (TeamPermission $c) => [$c->name, $c->value],
            TeamPermission::cases(),
        );

        // 7 pre-existing team-administration cases (byte-identical values — AC9
        // regression guard) followed by the 6 new proxy cases (T1).
        $this->assertSame([
            ['UpdateTeam', 'team:update'],
            ['DeleteTeam', 'team:delete'],
            ['AddMember', 'member:add'],
            ['UpdateMember', 'member:update'],
            ['RemoveMember', 'member:remove'],
            ['CreateInvitation', 'invitation:create'],
            ['CancelInvitation', 'invitation:cancel'],
            ['ViewProxy', 'proxy:view'],
            ['CreateProxy', 'proxy:create'],
            ['UpdateProxy', 'proxy:update'],
            ['DeleteProxy', 'proxy:delete'],
            ['UpdateAnyProxy', 'proxy:update-any'],
            ['DeleteAnyProxy', 'proxy:delete-any'],
        ], $actual);
    }

    public function test_enum_has_thirteen_cases(): void
    {
        $this->assertCount(13, TeamPermission::cases());
    }
}
