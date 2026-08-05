<?php

namespace Tests\Feature\Proxies;

use App\Enums\TeamRole;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Policies\ProxyPolicy;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end authorization acceptance over the real proxies.update/destroy/edit
 * routes (PRD-02 AC1/AC4/AC5/AC6). A denied action returns 403 and leaves the row
 * unchanged; an allowed action persists. Proves the composed ProxyPolicy is wired at
 * the controller layer, not merely true at the policy-unit level.
 */
class ProxyAuthorizationTest extends TestCase
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

    /**
     * A proxy in the given team with one live destination and the given creator.
     */
    private function proxyIn(Team $team, ?int $createdBy): Proxy
    {
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'created_by' => $createdBy,
            'name' => 'Original',
        ]);
        Destination::factory()->for($proxy)->createQuietly();

        return $proxy;
    }

    /**
     * @return array<string, mixed>
     */
    private function validUpdatePayload(): array
    {
        return [
            'name' => 'Changed name',
            'mode' => 'enhanced',
            'processing_mode' => 'async',
            'destinations' => [
                ['url' => 'https://changed.example.com/hook', 'http_method' => 'POST'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validStorePayload(): array
    {
        return [
            'name' => 'New proxy',
            'mode' => 'simple',
            'processing_mode' => 'async',
            'destinations' => [
                ['url' => 'https://new.example.com/hook', 'http_method' => 'POST'],
            ],
        ];
    }

    private function storeRoute(User $actor): string
    {
        return route('proxies.store', ['current_team' => $actor->currentTeam->slug]);
    }

    private function updateRoute(User $actor, Proxy $proxy): string
    {
        return route('proxies.update', ['current_team' => $actor->currentTeam->slug, 'proxy' => $proxy->id]);
    }

    private function destroyRoute(User $actor, Proxy $proxy): string
    {
        return route('proxies.destroy', ['current_team' => $actor->currentTeam->slug, 'proxy' => $proxy->id]);
    }

    // --- Member: own proxy succeeds ---------------------------------------------

    public function test_member_updates_own_proxy_succeeds_and_persists(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $proxy = $this->proxyIn($team, $member->id);

        $this->actingAs($member)
            ->put($this->updateRoute($member, $proxy), $this->validUpdatePayload())
            ->assertRedirect(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $proxy->id]));

        $this->assertDatabaseHas('proxies', ['id' => $proxy->id, 'name' => 'Changed name']);
    }

    public function test_member_deletes_own_proxy_succeeds(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $proxy = $this->proxyIn($team, $member->id);

        $this->actingAs($member)
            ->delete($this->destroyRoute($member, $proxy))
            ->assertRedirect(route('proxies.index', ['current_team' => $team->slug]));

        $this->assertSoftDeleted($proxy);
    }

    // --- Member: teammate's proxy denied 403, unchanged -------------------------

    public function test_member_updating_a_teammates_proxy_is_forbidden_and_unchanged(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $teammate = $this->member($team, TeamRole::Member);
        $proxy = $this->proxyIn($team, $teammate->id);

        $this->actingAs($member)
            ->put($this->updateRoute($member, $proxy), $this->validUpdatePayload())
            ->assertForbidden();

        $this->assertDatabaseHas('proxies', ['id' => $proxy->id, 'name' => 'Original']);
    }

    public function test_member_deleting_a_teammates_proxy_is_forbidden_and_unchanged(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $teammate = $this->member($team, TeamRole::Member);
        $proxy = $this->proxyIn($team, $teammate->id);

        $this->actingAs($member)
            ->delete($this->destroyRoute($member, $proxy))
            ->assertForbidden();

        $this->assertNotSoftDeleted($proxy);
    }

    // --- Member: null-creator proxy denied 403, unchanged -----------------------

    public function test_member_updating_a_null_creator_proxy_is_forbidden_and_unchanged(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $proxy = $this->proxyIn($team, null);

        $this->actingAs($member)
            ->put($this->updateRoute($member, $proxy), $this->validUpdatePayload())
            ->assertForbidden();

        $this->assertDatabaseHas('proxies', ['id' => $proxy->id, 'name' => 'Original']);
    }

    public function test_member_deleting_a_null_creator_proxy_is_forbidden_and_unchanged(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $proxy = $this->proxyIn($team, null);

        $this->actingAs($member)
            ->delete($this->destroyRoute($member, $proxy))
            ->assertForbidden();

        $this->assertNotSoftDeleted($proxy);
    }

    // --- Admin / Owner: any team proxy succeeds ---------------------------------

    public function test_admin_updates_and_deletes_a_proxy_they_did_not_create(): void
    {
        $team = Team::factory()->createQuietly();
        $creator = $this->member($team, TeamRole::Member);
        $admin = $this->member($team, TeamRole::Admin);

        $toUpdate = $this->proxyIn($team, $creator->id);
        $this->actingAs($admin)
            ->put($this->updateRoute($admin, $toUpdate), $this->validUpdatePayload())
            ->assertRedirect(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $toUpdate->id]));
        $this->assertDatabaseHas('proxies', ['id' => $toUpdate->id, 'name' => 'Changed name']);

        $toDelete = $this->proxyIn($team, $creator->id);
        $this->actingAs($admin)
            ->delete($this->destroyRoute($admin, $toDelete))
            ->assertRedirect(route('proxies.index', ['current_team' => $team->slug]));
        $this->assertSoftDeleted($toDelete);
    }

    public function test_owner_updates_and_deletes_a_proxy_they_did_not_create(): void
    {
        $team = Team::factory()->createQuietly();
        $creator = $this->member($team, TeamRole::Member);
        $owner = $this->member($team, TeamRole::Owner);

        $toUpdate = $this->proxyIn($team, $creator->id);
        $this->actingAs($owner)
            ->put($this->updateRoute($owner, $toUpdate), $this->validUpdatePayload())
            ->assertRedirect(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $toUpdate->id]));
        $this->assertDatabaseHas('proxies', ['id' => $toUpdate->id, 'name' => 'Changed name']);

        $toDelete = $this->proxyIn($team, $creator->id);
        $this->actingAs($owner)
            ->delete($this->destroyRoute($owner, $toDelete))
            ->assertRedirect(route('proxies.index', ['current_team' => $team->slug]));
        $this->assertSoftDeleted($toDelete);
    }

    // --- edit route mirrors update authorization --------------------------------

    public function test_member_cannot_open_the_edit_route_for_a_teammates_proxy(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $teammate = $this->member($team, TeamRole::Member);
        $proxy = $this->proxyIn($team, $teammate->id);

        $this->actingAs($member)
            ->get(route('proxies.edit', ['current_team' => $team->slug, 'proxy' => $proxy->id]))
            ->assertForbidden();
    }

    // --- store route is authorization-gated on the create ability ----------------

    /**
     * A role that holds CreateProxy reaches the write and persists — proving
     * store()'s `authorize('create', ...)` passes for a permitted actor rather than
     * blocking every request.
     */
    public function test_permitted_role_can_store_a_proxy(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);

        $this->actingAs($member)
            ->post($this->storeRoute($member), $this->validStorePayload())
            ->assertRedirect();

        $this->assertDatabaseHas('proxies', [
            'team_id' => $team->id,
            'name' => 'New proxy',
            'created_by' => $member->id,
        ]);
    }

    /**
     * store() consults ProxyPolicy::create before writing: when the policy denies,
     * the request is 403 and no row is committed. Every real role holds CreateProxy
     * today, so a role-based denial is unreachable — this proves the gate is *wired*
     * into store() (defense-in-depth) at the policy level, honestly, without a
     * contrived role. If store() omitted the authorize call, a denying policy would
     * have no effect and the proxy would still be created.
     */
    public function test_store_is_denied_when_the_create_policy_denies(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);

        $this->partialMock(ProxyPolicy::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')->andReturn(false);
        });

        $this->actingAs($member)
            ->post($this->storeRoute($member), $this->validStorePayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('proxies', ['name' => 'New proxy']);
    }
}
