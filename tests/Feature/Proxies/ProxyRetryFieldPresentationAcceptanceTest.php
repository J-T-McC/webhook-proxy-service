<?php

namespace Tests\Feature\Proxies;

use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Http\Resources\ProxyFormResource;
use App\Http\Resources\ProxyResource;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * T5 — read-surface suppression of the retry-policy pair (AC12, AC14(b),
 * AC16; Amendment A; ADR-018 Decision 4). `RetryPolicyFormAcceptanceTest`
 * already proves an Enhanced proxy's retry fields survive on Index/Show/Edit
 * unmodified; this file proves the opposite half — a Simple proxy's fields
 * are suppressed to null on every non-Edit surface, always, regardless of any
 * dormant value, and that the Edit-only carve-out (`ProxyFormResource`) is
 * the sole path a dormant value can reach the client through.
 */
class ProxyRetryFieldPresentationAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    /**
     * AC14(b) lead: a Simple proxy holding a dormant policy renders
     * identically, on Index and Show, to a Simple proxy that never had one —
     * "identically" is the assertion, not an inference, so both proxies are
     * checked in the same test.
     */
    public function test_a_simple_proxy_with_a_dormant_policy_renders_identically_to_one_that_never_had_one_on_index_and_show(): void
    {
        $user = $this->actingUser();

        $dormant = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Simple,
            'retry_attempt_limit' => 8,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        $neverConfigured = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Simple,
        ]);

        // Both entries must be null regardless of which proxy Index returns at
        // which position, so the assertion is made over every element via
        // `each()` rather than pinning `.0`/`.1` to a proxy — it survives a
        // fixture (or Index ordering) reorder by construction.
        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('proxies.data', 2)
                ->has('proxies.data', fn (Assert $data) => $data
                    ->each(fn (Assert $proxy) => $proxy
                        ->where('retry_attempt_limit', null)
                        ->where('retry_backoff_strategy', null)
                        ->etc())));

        foreach ([$dormant, $neverConfigured] as $proxy) {
            $this->actingAs($user)
                ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('proxy.retry_attempt_limit', null)
                    ->where('proxy.retry_backoff_strategy', null));
        }
    }

    /**
     * Amendment A: the same dormant proxy's Edit payload is the sole surface
     * that emits the raw persisted values.
     */
    public function test_the_same_dormant_proxys_edit_payload_emits_the_raw_values(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Simple,
            'retry_attempt_limit' => 8,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();

        $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.retry_attempt_limit', 8)
                ->where('proxy.retry_backoff_strategy', 'fixed'));
    }

    /**
     * AC14(b): no non-Edit response — here, the events read surface, which
     * embeds `ProxyResource` alongside the events list/detail — carries a
     * Simple proxy's dormant retry values either.
     */
    public function test_the_events_index_and_show_pages_also_suppress_a_simple_proxys_dormant_retry_policy(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Simple,
            'retry_attempt_limit' => 8,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->actingAs($user)
            ->get(route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.retry_attempt_limit', null)
                ->where('proxy.retry_backoff_strategy', null));

        $this->actingAs($user)
            ->get(route('proxies.events.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $event->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.retry_attempt_limit', null)
                ->where('proxy.retry_backoff_strategy', null));
    }

    /**
     * AC14(b)/ADR-018 Decision 4: `ProxyFormResource` has exactly one caller
     * in the codebase — `ProxyController::edit()`. A second caller is a
     * review finding, not a refactor. Asserted via reflection over the
     * source tree rather than a runtime probe, so it fails the moment a new
     * caller is introduced anywhere, not just in a route this test happens
     * to hit.
     */
    public function test_proxy_form_resource_has_exactly_one_caller(): void
    {
        $needle = (new ReflectionClass(ProxyFormResource::class))->getShortName();

        $matches = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $contents = $file->getContents();

            if (str_contains($contents, $needle.'::make(') || str_contains($contents, 'new '.$needle.'(')) {
                $matches[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            ['Http/Controllers/ProxyController.php'],
            $matches,
            'ProxyFormResource must have exactly one caller: ProxyController::edit().',
        );
    }

    /**
     * Sanity pin: `ProxyFormResource` really does extend `ProxyResource`
     * (Amendment A's carve-out shape, not a parallel resource).
     */
    public function test_proxy_form_resource_extends_proxy_resource(): void
    {
        $this->assertTrue(is_subclass_of(ProxyFormResource::class, ProxyResource::class));
    }
}
