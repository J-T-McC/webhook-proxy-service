<?php

namespace Tests\Feature\Proxies;

use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProxyIndexShowTest extends TestCase
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

    public function test_cross_team_show_returns_404(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->createQuietly();
        $foreignProxy = Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $foreignProxy->id]))
            ->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $user = $this->actingUser();

        $this->get(route('proxies.index', ['current_team' => $this->teamSlug($user)]))
            ->assertRedirect(route('login'));
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
}
