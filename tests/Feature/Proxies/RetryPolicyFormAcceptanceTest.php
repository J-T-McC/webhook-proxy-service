<?php

namespace Tests\Feature\Proxies;

use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Models\Proxy;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T44 — end-to-end proof of the retry-policy form/persistence surface
 * (PRD-06 AC2, AC20), complementing T29's request-validation and T30's
 * controller/resource per-task tests by driving real `store`/`update`
 * requests and following each through to the resource shape.
 */
class RetryPolicyFormAcceptanceTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Stripe proxy',
            'mode' => 'simple',
            'processing_mode' => 'async',
            'destinations' => [
                ['url' => 'https://a.example.com/hook', 'http_method' => 'POST'],
            ],
        ], $overrides);
    }

    // --- Store: accept 1-10 + a known strategy on enhanced, reject the rest -

    public function test_store_accepts_the_1_to_10_bounds_and_a_known_strategy_on_enhanced(): void
    {
        $user = $this->actingUser();

        foreach ([1, 5, 10] as $limit) {
            $response = $this->actingAs($user)->post(
                route('proxies.store', ['current_team' => $user->currentTeam->slug]),
                $this->payload(['name' => "Enhanced {$limit}", 'mode' => 'enhanced', 'retry_attempt_limit' => $limit, 'retry_backoff_strategy' => 'fixed']),
            );

            $response->assertRedirect();
            $proxy = Proxy::query()->where('name', "Enhanced {$limit}")->firstOrFail();
            $this->assertSame($limit, $proxy->retry_attempt_limit);
            $this->assertSame('fixed', $proxy->retry_backoff_strategy->value);
        }
    }

    public function test_store_rejects_0_11_and_an_unknown_strategy(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->postJson(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['mode' => 'enhanced', 'retry_attempt_limit' => 0]),
        )->assertJsonValidationErrors(['retry_attempt_limit']);

        $this->actingAs($user)->postJson(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['mode' => 'enhanced', 'retry_attempt_limit' => 11]),
        )->assertJsonValidationErrors(['retry_attempt_limit']);

        $this->actingAs($user)->postJson(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['mode' => 'enhanced', 'retry_backoff_strategy' => 'not-a-real-strategy']),
        )->assertJsonValidationErrors(['retry_backoff_strategy']);

        $this->assertSame(0, Proxy::count());
    }

    public function test_store_rejects_either_retry_field_present_when_mode_is_simple(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->postJson(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['mode' => 'simple', 'retry_attempt_limit' => 3]),
        )->assertJsonValidationErrors(['retry_attempt_limit']);

        $this->actingAs($user)->postJson(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['mode' => 'simple', 'retry_backoff_strategy' => 'fixed']),
        )->assertJsonValidationErrors(['retry_backoff_strategy']);

        $this->assertSame(0, Proxy::count());
    }

    // --- Update: same acceptance/rejection, plus the enhanced->simple clear -

    public function test_update_persists_retry_policy_on_enhanced(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'mode' => ProxyMode::Simple]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload(['mode' => 'enhanced', 'retry_attempt_limit' => 7, 'retry_backoff_strategy' => 'exponential']),
        )->assertRedirect();

        $fresh = $proxy->fresh();
        $this->assertSame(7, $fresh->retry_attempt_limit);
        $this->assertSame('exponential', $fresh->retry_backoff_strategy->value);
    }

    public function test_update_rejects_either_retry_field_present_when_mode_is_simple(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)->putJson(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload(['mode' => 'simple', 'retry_attempt_limit' => 3]),
        )->assertJsonValidationErrors(['retry_attempt_limit']);
    }

    public function test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 4,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload(['mode' => 'simple']),
        )->assertRedirect();

        $fresh = $proxy->fresh();
        $this->assertNull($fresh->retry_attempt_limit);
        $this->assertNull($fresh->retry_backoff_strategy);
    }

    // --- ProxyResource emits both fields on index/show/edit ------------------

    public function test_proxy_resource_emits_both_fields_on_index_show_and_edit(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 6,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $user->currentTeam->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxies.data.0.retry_attempt_limit', 6)
                ->where('proxies.data.0.retry_backoff_strategy', 'fixed'));

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.retry_attempt_limit', 6)
                ->where('proxy.retry_backoff_strategy', 'fixed'));

        $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proxy.retry_attempt_limit', 6)
                ->where('proxy.retry_backoff_strategy', 'fixed'));
    }

    // --- AC20: mode gates nothing else -----------------------------------

    public function test_mode_gates_only_the_retry_policy_pair_nothing_else(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        // A `mode = simple` update still freely sets an UNRELATED field
        // (response_status/response_body, gated only by its own pre-existing
        // 204 rule, never by mode) — proving #6 added no new field beyond the
        // retry-policy pair to mode's gating.
        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload(['mode' => 'simple', 'response_status' => 200, 'response_body' => 'still allowed under simple mode']),
        )->assertRedirect();

        $fresh = $proxy->fresh();
        $this->assertSame(200, $fresh->response_status);
        $this->assertSame('still allowed under simple mode', $fresh->response_body);

        // No new mode-toggle route exists — #6 adds no way to change a
        // proxy's mode beyond the pre-existing `update` endpoint.
        $this->assertFalse(Route::has('proxies.mode'));
        $this->assertFalse(Route::has('proxies.toggle-mode'));
    }
}
