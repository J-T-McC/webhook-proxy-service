<?php

namespace Tests\Feature\Proxies;

use App\Enums\TeamRole;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Policies\ProxyPolicy;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Per-record `is_creator` flag on ProxyResource (ADR-009 Amendment B). The flag is
 * a plain `created_by === auth id` comparison — no policy call, no query — and the
 * client composes edit/delete affordances from it plus the page-level permission
 * booleans. The old policy-driven `can:{update,delete}` shape (Amendment A5) is
 * withdrawn (review-02 M2 N+1). Server ProxyPolicy remains the authoritative gate.
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

    public function test_is_creator_is_true_only_on_the_actors_own_proxy_in_the_index_list(): void
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

                    // The new shape: a per-record is_creator boolean, and no `can` key.
                    return $byId[$own->id]['is_creator'] === true
                        && $byId[$foreign->id]['is_creator'] === false
                        && ! array_key_exists('can', $byId[$own->id])
                        && ! array_key_exists('can', $byId[$foreign->id]);
                })
            );
    }

    public function test_is_creator_on_the_show_payload_tracks_ownership_and_no_can_key_is_present(): void
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
                ->where('proxy.is_creator', true)
                ->missing('proxy.can')
            );

        $this->actingAs($member)
            ->get(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $foreign->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.is_creator', false)
                ->missing('proxy.can')
            );
    }

    public function test_a_null_created_by_proxy_reports_is_creator_false(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);

        // Pre-feature / no-actor proxy: created_by is null, so the plain comparison is
        // false and the Member sees no update/delete affordance — fail-closed (A4).
        $orphan = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => null]);

        $this->actingAs($member)
            ->get(route('proxies.show', ['current_team' => $team->slug, 'proxy' => $orphan->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.is_creator', false)
                ->missing('proxy.can')
            );
    }

    /**
     * Proves the M2 N+1 is gone: the index render composes affordances from the
     * page-level bundle + is_creator, so ProxyPolicy's per-record update/delete
     * abilities are never evaluated during serialization — regardless of row count.
     * viewAny still runs (real, via the partial mock) to authorize the page.
     */
    public function test_index_serialization_never_invokes_the_per_record_update_delete_policy(): void
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);

        Proxy::factory()->count(3)->createQuietly(['team_id' => $team->id, 'created_by' => $member->id]);

        $this->partialMock(ProxyPolicy::class, function (MockInterface $mock) {
            $mock->shouldReceive('update')->never();
            $mock->shouldReceive('delete')->never();
        });

        $this->actingAs($member)
            ->get(route('proxies.index', ['current_team' => $team->slug]))
            ->assertOk();
    }
}
