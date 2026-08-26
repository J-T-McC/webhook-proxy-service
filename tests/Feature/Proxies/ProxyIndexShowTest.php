<?php

namespace Tests\Feature\Proxies;

use App\Enums\ProcessingMode;
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

    public function test_index_and_show_expose_processing_mode(): void
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

        // Index: every row carries processing_mode (needed for the T25 column).
        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $this->teamSlug($user)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxies.data', 2)
                ->where('proxies.data.0.processing_mode', fn (string $mode) => in_array($mode, ['async', 'fifo'], true))
                ->where('proxies.data.1.processing_mode', fn (string $mode) => in_array($mode, ['async', 'fifo'], true))
            );

        // Show: the fifo proxy's mode is exposed verbatim.
        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $fifo->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('proxy.processing_mode', 'fifo'));

        // Show: the async proxy's mode is exposed verbatim.
        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $this->teamSlug($user), 'proxy' => $async->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('proxy.processing_mode', 'async'));
    }

    /**
     * AC14(b): a Simple proxy's retry columns are suppressed on Index and Show
     * even when it holds a dormant policy from a prior Enhanced save — the
     * mode gate always wins on these read surfaces, unlike Edit
     * (`ProxyFormResource`, T5). This test previously seeded a default-mode
     * (Simple) proxy and asserted the raw 4/`fixed` were emitted verbatim;
     * that assumption is retired by ADR-018, so the test now asserts the
     * dormant-suppression outcome instead.
     */
    public function test_index_and_show_suppress_a_simple_proxys_dormant_retry_policy_fields(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
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
