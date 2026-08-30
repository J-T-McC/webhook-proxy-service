<?php

namespace Tests\Feature\Proxies;

use App\Enums\ProcessingMode;
use App\Enums\TeamRole;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Policies\ProxyPolicy;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Everything `ProxyController@index` puts on the page: the scoped proxy list, the
 * page-level `permissions` bundle, the per-record `is_creator` flag, and the props
 * that must stay absent from this route.
 */
class ProxyIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Backend-first: assert Inertia props without the Vue pages (built T27-T29).
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

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

    private function member(Team $team, TeamRole $role): User
    {
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_index_lists_only_the_current_teams_proxies_with_ingest_url(): void
    {
        config()->set('ingest.url', 'https://fixed.example.test');

        $user = $this->actingUser();
        Proxy::factory()->count(2)->createQuietly(['team_id' => $user->current_team_id]);

        $other = User::factory()->createQuietly();
        Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $this->teamSlug($user)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Index')
                ->has('proxies.data', 2)
                ->where('proxies.data.0.ingest_url', fn (string $url) => str_starts_with($url, 'https://fixed.example.test/ingest/'))
            );
    }

    public function test_index_exposes_processing_mode_on_every_row(): void
    {
        $user = $this->actingUser();
        Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Async,
        ]);
        Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Fifo,
        ]);

        // Every row carries processing_mode (needed for the T25 column).
        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $this->teamSlug($user)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxies.data', 2)
                ->where('proxies.data.0.processing_mode', fn (string $mode) => in_array($mode, ['async', 'fifo'], true))
                ->where('proxies.data.1.processing_mode', fn (string $mode) => in_array($mode, ['async', 'fifo'], true))
            );
    }

    /**
     * AC14(b): a Simple proxy's retry columns are suppressed on Index even when it
     * holds a dormant policy from a prior Enhanced save — the mode gate always wins
     * on this read surface, unlike Edit (`ProxyFormResource`, T5). The Show half of
     * this rule lives in `ProxyShowTest`.
     */
    public function test_index_suppresses_a_simple_proxys_dormant_retry_policy_fields(): void
    {
        $user = $this->actingUser();
        Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'retry_attempt_limit' => 4,
            'retry_backoff_strategy' => 'fixed',
        ]);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $this->teamSlug($user)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxies.data.0.retry_attempt_limit', null)
                ->where('proxies.data.0.retry_backoff_strategy', null)
            );
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $user = $this->actingUser();

        $this->get(route('proxies.index', ['current_team' => $this->teamSlug($user)]))
            ->assertRedirect(route('login'));
    }

    /**
     * T11 — `defaultSensitiveFieldNames` is a create/edit form prop (AC12; plan-10
     * Technical ruling 3) and must not appear here. The present-on-both-forms half
     * of that rule lives in `ProxyStoreTest` and `ProxyUpdateTest`.
     */
    public function test_index_gains_no_default_sensitive_field_names_prop(): void
    {
        $user = $this->actingUser();
        Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $this->teamSlug($user)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Index')
                ->missing('defaultSensitiveFieldNames')
            );
    }

    /**
     * @return iterable<string, array{TeamRole, bool}>
     */
    public static function roleProvider(): iterable
    {
        // [role, expected -any bypass booleans]
        yield 'owner' => [TeamRole::Owner, true];
        yield 'admin' => [TeamRole::Admin, true];
        yield 'member' => [TeamRole::Member, false];
    }

    /**
     * The index page carries a page-level `permissions` prop (ProxyPermissions DTO)
     * reflecting the acting user's live role bundle (ADR-009 §4, Amendment B4). It
     * carries the update/delete + `-any` booleans the client uses to compose each
     * row's edit/delete affordance against the resource's is_creator flag.
     */
    #[DataProvider('roleProvider')]
    public function test_index_shares_proxy_permissions_reflecting_the_acting_role(TeamRole $role, bool $expectedAny): void
    {
        $team = Team::factory()->createQuietly();
        $user = $this->member($team, $role);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('permissions')
                // Every role holds create/view/update/delete today (AC3); proves the
                // wiring reads live role data rather than a stub.
                ->where('permissions.canCreateProxy', true)
                ->where('permissions.canViewProxy', true)
                ->where('permissions.canUpdateProxy', true)
                ->where('permissions.canDeleteProxy', true)
                // The `-any` ownership bypass distinguishes Admin/Owner from Member.
                ->where('permissions.canUpdateAnyProxy', $expectedAny)
                ->where('permissions.canDeleteAnyProxy', $expectedAny)
            );
    }

    /**
     * Per-record `is_creator` flag on ProxyResource (ADR-009 Amendment B). The flag
     * is a plain `created_by === auth id` comparison — no policy call, no query — and
     * the client composes edit/delete affordances from it plus the page-level
     * permission booleans. The old policy-driven `can:{update,delete}` shape
     * (Amendment A5) is withdrawn (review-02 M2 N+1). Server ProxyPolicy remains the
     * authoritative gate.
     */
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
