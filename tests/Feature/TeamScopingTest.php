<?php

namespace Tests\Feature;

use App\Enums\HttpMethod;
use App\Enums\ProxyMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Policies\ProxyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_return_only_the_current_teams_proxies(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $ownProxy = Proxy::factory()->create(['team_id' => $userA->current_team_id]);
        $otherProxy = Proxy::factory()->create(['team_id' => $userB->current_team_id]);

        $this->actingAs($userA);

        $ids = Proxy::query()->pluck('id');

        $this->assertTrue($ids->contains($ownProxy->id));
        $this->assertFalse($ids->contains($otherProxy->id));
        $this->assertNull(Proxy::find($otherProxy->id));
    }

    public function test_creating_a_proxy_auto_assigns_the_current_team(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $proxy = new Proxy(['name' => 'Auto', 'mode' => ProxyMode::Simple]);
        $proxy->ingest_token = 'auto-token';
        $proxy->ingest_token_hash = hash('sha256', 'auto-token', binary: true);
        $proxy->save();

        $this->assertSame($user->current_team_id, $proxy->team_id);
    }

    public function test_creating_a_destination_and_attempt_auto_assigns_the_current_team(): void
    {
        $user = User::factory()->create();
        $proxy = Proxy::factory()->create(['team_id' => $user->current_team_id]);

        $this->actingAs($user);

        $destination = new Destination([
            'proxy_id' => $proxy->id,
            'url' => 'https://example.test/hook',
            'http_method' => HttpMethod::Post,
        ]);
        $destination->save();

        $attempt = new DeliveryAttempt([
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => (string) Str::uuid(),
            'status' => 'dispatched',
            'attempt_number' => 1,
            'started_at' => now(),
        ]);
        $attempt->save();

        $this->assertSame($user->current_team_id, $destination->team_id);
        $this->assertSame($user->current_team_id, $attempt->team_id);
    }

    public function test_proxy_policy_allows_owning_team_member_and_denies_others(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $proxy = Proxy::factory()->create(['team_id' => $owner->current_team_id]);
        $policy = new ProxyPolicy;

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($policy->{$ability}($owner, $proxy), "owner should be allowed to {$ability}");
            $this->assertFalse($policy->{$ability}($outsider, $proxy), "outsider should be denied {$ability}");
        }
    }

    public function test_proxy_policy_is_registered_via_the_gate(): void
    {
        $owner = User::factory()->create();
        $proxy = Proxy::factory()->create(['team_id' => $owner->current_team_id]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $proxy));
    }
}
