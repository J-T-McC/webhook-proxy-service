<?php

namespace Tests\Feature\Proxies;

use App\Enums\ProcessingMode;
use App\Enums\TeamRole;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Everything `ProxyController@show` puts on the page: the proxy itself with its
 * live destinations, the response and retry configuration it exposes, the
 * per-record `is_creator` flag, and cross-team isolation.
 */
class ProxyShowTest extends TestCase
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

    public function test_show_returns_ingest_url_mode_and_destinations(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Show')
                ->where('proxy.id', $proxy->id)
                ->where('proxy.mode', $proxy->mode->value)
                ->has('proxy.ingest_url')
                ->has('proxy.destinations', 2)
                ->has('proxy.destinations.0.url')
                ->has('proxy.destinations.0.http_method')
            );
    }

    public function test_show_exposes_response_config_for_a_configured_proxy(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'response_status' => 201,
            'response_body' => '{"ok":true}',
        ]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.response_status', 201)
                ->where('proxy.response_body', '{"ok":true}')
            );
    }

    public function test_show_exposes_null_response_config_for_an_unconfigured_proxy(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.response_status', null)
                ->where('proxy.response_body', null)
            );
    }

    public function test_show_exposes_processing_mode_verbatim(): void
    {
        $user = $this->actingUser();
        $async = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Async,
        ]);
        $fifo = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Fifo,
        ]);
        Destination::factory()->for($fifo)->createQuietly();

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $fifo->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('proxy.processing_mode', 'fifo'));

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $async->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('proxy.processing_mode', 'async'));
    }

    /**
     * AC14(b): a Simple proxy's retry columns are suppressed on Show even when it
     * holds a dormant policy from a prior Enhanced save — the mode gate always wins
     * on this read surface, unlike Edit (`ProxyFormResource`, T5). The Index half of
     * this rule lives in `ProxyIndexTest`.
     */
    public function test_show_suppresses_a_simple_proxys_dormant_retry_policy_fields(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'retry_attempt_limit' => 4,
            'retry_backoff_strategy' => 'fixed',
        ]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.retry_attempt_limit', null)
                ->where('proxy.retry_backoff_strategy', null)
            );
    }

    public function test_unconfigured_retry_policy_fields_are_null(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.retry_attempt_limit', null)
                ->where('proxy.retry_backoff_strategy', null)
            );
    }

    public function test_cross_team_show_returns_404(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->createQuietly();
        $foreignProxy = Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $foreignProxy->id]))
            ->assertNotFound();
    }

    public function test_ingest_url_uses_config_host_not_spoofed_host_header(): void
    {
        config()->set('ingest.url', 'https://fixed.example.test');

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->withHeaders(['Host' => 'attacker.example.com'])
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.ingest_url', fn (string $url) => str_starts_with($url, 'https://fixed.example.test/ingest/')
                    && ! str_contains($url, 'attacker.example.com'))
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
}
