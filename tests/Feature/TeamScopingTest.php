<?php

namespace Tests\Feature;

use App\Enums\HttpMethod;
use App\Enums\ProxyMode;
use App\Http\Middleware\ApplyTeamScope;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Scopes\TeamScope;
use App\Models\User;
use App\Policies\ProxyPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamScopingTest extends TestCase
{
    /**
     * Run the given callback inside the ApplyTeamScope middleware, mirroring how a
     * team-scoped route resolves queries while the scope is active.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $inside
     * @return TReturn
     */
    private function withinTeamScope(callable $inside): mixed
    {
        $captured = null;

        (new ApplyTeamScope)->handle(Request::create('/'), function () use ($inside, &$captured): Response {
            $captured = $inside();

            return new Response('ok');
        });

        return $captured;
    }

    public function test_team_owned_models_carry_no_global_read_scope_by_default(): void
    {
        // Scoping is no longer always-on: a default/settings route (which never runs
        // ApplyTeamScope) must not see a team predicate silently applied.
        foreach ([Proxy::class, Destination::class, DeliveryAttempt::class] as $model) {
            $this->assertFalse(
                $model::hasGlobalScope(TeamScope::class),
                "{$model} must not register TeamScope globally",
            );
        }
    }

    public function test_default_queries_are_unscoped_across_teams(): void
    {
        $userA = User::factory()->createQuietly();
        $userB = User::factory()->createQuietly();

        Proxy::factory()->createQuietly(['team_id' => $userA->current_team_id]);
        Proxy::factory()->createQuietly(['team_id' => $userB->current_team_id]);

        $this->actingAs($userA);

        // Outside the team-scoped middleware (e.g. ingest, console, settings) the
        // query spans every team.
        $this->assertSame(2, Proxy::query()->count());
    }

    public function test_middleware_filters_queries_to_the_current_team(): void
    {
        $userA = User::factory()->createQuietly();
        $userB = User::factory()->createQuietly();

        $ownProxy = Proxy::factory()->createQuietly(['team_id' => $userA->current_team_id]);
        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $userB->current_team_id]);

        $this->actingAs($userA);

        [$ids, $found, $foreign] = $this->withinTeamScope(fn () => [
            Proxy::query()->pluck('id'),
            Proxy::find($ownProxy->id),
            Proxy::find($otherProxy->id),
        ]);

        $this->assertTrue($ids->contains($ownProxy->id));
        $this->assertFalse($ids->contains($otherProxy->id));
        $this->assertNotNull($found);
        $this->assertNull($foreign, 'cross-team id must resolve to null under the scope');
    }

    public function test_middleware_fails_closed_for_an_authenticated_user_without_a_current_team(): void
    {
        $other = User::factory()->createQuietly();
        Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);

        $teamless = User::factory()->createQuietly();
        $teamless->forceFill(['current_team_id' => null])->saveQuietly();

        $this->actingAs($teamless);

        // team_id ?? 0 constrains to a sentinel no row owns: zero rows, not global.
        $count = $this->withinTeamScope(fn () => Proxy::query()->count());

        $this->assertSame(0, $count);
    }

    public function test_middleware_removes_the_scope_after_the_request(): void
    {
        $user = User::factory()->createQuietly();
        $this->actingAs($user);

        $this->withinTeamScope(fn () => Proxy::query()->count());

        // Global scopes live in a shared static; a leak would bleed into later
        // requests in the same process (ingest, queue, other tests).
        foreach ([Proxy::class, Destination::class, DeliveryAttempt::class] as $model) {
            $this->assertFalse(
                $model::hasGlobalScope(TeamScope::class),
                "{$model} must not retain TeamScope after the request",
            );
        }
    }

    public function test_creating_a_proxy_auto_assigns_the_current_team(): void
    {
        $user = User::factory()->createQuietly();
        $this->actingAs($user);

        $proxy = new Proxy(['name' => 'Auto', 'mode' => ProxyMode::Simple]);
        $proxy->ingest_token = 'auto-token';
        $proxy->ingest_token_hash = hash('sha256', 'auto-token', binary: true);
        $proxy->save();

        $this->assertSame($user->current_team_id, $proxy->team_id);
    }

    public function test_creating_a_destination_and_attempt_auto_assigns_the_current_team(): void
    {
        $user = User::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

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
        $owner = User::factory()->createQuietly();
        $outsider = User::factory()->createQuietly();

        $proxy = Proxy::factory()->createQuietly(['team_id' => $owner->current_team_id]);
        $policy = new ProxyPolicy;

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($policy->{$ability}($owner, $proxy), "owner should be allowed to {$ability}");
            $this->assertFalse($policy->{$ability}($outsider, $proxy), "outsider should be denied {$ability}");
        }
    }

    public function test_proxy_policy_is_registered_via_the_gate(): void
    {
        $owner = User::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $owner->current_team_id]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $proxy));
    }
}
