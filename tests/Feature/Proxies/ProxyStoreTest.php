<?php

namespace Tests\Feature\Proxies;

use App\Models\Proxy;
use App\Models\User;
use Tests\TestCase;

class ProxyStoreTest extends TestCase
{
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
                ['url' => 'https://b.example.com/hook', 'http_method' => 'PUT'],
            ],
        ], $overrides);
    }

    public function test_creating_a_proxy_persists_it_with_destinations_and_flashes(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(),
        );

        $proxy = Proxy::firstOrFail();
        $response->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));
        $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Proxy created.']);

        $this->assertSame($user->current_team_id, $proxy->team_id);
        $this->assertSame('Stripe proxy', $proxy->name);
        $this->assertCount(2, $proxy->destinations);
        $this->assertNotEmpty($proxy->ingest_token_hash);

        // A distinct ingest URL was minted (AC1/AC12a).
        $this->assertStringContainsString('/ingest/', $proxy->ingestUrl());
    }

    public function test_each_destination_method_is_constrained_and_persisted(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(),
        );

        $methods = Proxy::firstOrFail()->destinations->pluck('http_method')->map(fn ($m) => $m->value)->all();
        $this->assertEqualsCanonicalizing(['POST', 'PUT'], $methods);
    }

    public function test_two_creates_yield_distinct_hashes_and_urls(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(route('proxies.store', ['current_team' => $user->currentTeam->slug]), $this->payload(['name' => 'One']));
        $this->actingAs($user)->post(route('proxies.store', ['current_team' => $user->currentTeam->slug]), $this->payload(['name' => 'Two']));

        $proxies = Proxy::all();
        $this->assertCount(2, $proxies);
        $this->assertNotSame($proxies[0]->ingest_token_hash, $proxies[1]->ingest_token_hash);
        $this->assertNotSame($proxies[0]->ingestUrl(), $proxies[1]->ingestUrl());
    }

    public function test_zero_destinations_is_rejected(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)
            ->post(route('proxies.store', ['current_team' => $user->currentTeam->slug]), $this->payload(['destinations' => []]))
            ->assertInvalid(['destinations']);

        $this->assertSame(0, Proxy::count());
    }

    public function test_creating_with_response_config_persists_both_values(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['response_status' => 200, 'response_body' => '{"ok":true}']),
        );

        $proxy = Proxy::firstOrFail();
        $this->assertSame(200, $proxy->response_status);
        $this->assertSame('{"ok":true}', $proxy->response_body);
    }

    public function test_creating_without_response_config_leaves_both_null(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(),
        );

        $proxy = Proxy::firstOrFail();
        $this->assertNull($proxy->response_status);
        $this->assertNull($proxy->response_body);
    }

    public function test_creating_with_an_explicit_processing_mode_persists_it(): void
    {
        $user = $this->actingUser();

        foreach (['async', 'fifo'] as $mode) {
            $this->actingAs($user)->post(
                route('proxies.store', ['current_team' => $user->currentTeam->slug]),
                $this->payload(['name' => "proxy-{$mode}", 'processing_mode' => $mode]),
            );

            $proxy = Proxy::where('name', "proxy-{$mode}")->firstOrFail();
            $this->assertSame($mode, $proxy->processing_mode->value);
        }
    }

    // --- Retry policy (T30; AC2, AC20) --------------------------------------

    public function test_creating_an_enhanced_proxy_with_retry_policy_persists_it(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['mode' => 'enhanced', 'retry_attempt_limit' => 3, 'retry_backoff_strategy' => 'fixed']),
        );

        $proxy = Proxy::firstOrFail();
        $this->assertSame(3, $proxy->retry_attempt_limit);
        $this->assertSame('fixed', $proxy->retry_backoff_strategy->value);
    }

    public function test_creating_without_a_retry_policy_leaves_both_null(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['mode' => 'enhanced']),
        );

        $proxy = Proxy::firstOrFail();
        $this->assertNull($proxy->retry_attempt_limit);
        $this->assertNull($proxy->retry_backoff_strategy);
    }

    public function test_creating_without_a_processing_mode_is_rejected(): void
    {
        $user = $this->actingUser();

        $payload = $this->payload();
        unset($payload['processing_mode']);

        $this->actingAs($user)
            ->post(route('proxies.store', ['current_team' => $user->currentTeam->slug]), $payload)
            ->assertInvalid(['processing_mode']);

        $this->assertSame(0, Proxy::count());
    }
}
