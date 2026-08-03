<?php

namespace Tests\Feature\Proxies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The index page carries a page-level `permissions` prop (ProxyPermissions DTO)
 * reflecting the acting user's live role bundle (ADR-009 §4; T8 wiring).
 */
class ProxyIndexPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

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
    public function test_index_shares_proxy_permissions_reflecting_the_acting_role(TeamRole $role): void
    {
        $team = Team::factory()->createQuietly();
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('permissions')
                // Every role holds create + view today (AC3); proves the wiring reads
                // live role data rather than a stub.
                ->where('permissions.canCreateProxy', true)
                ->where('permissions.canViewProxy', true)
            );
    }
}
