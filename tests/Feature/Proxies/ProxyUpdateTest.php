<?php

namespace Tests\Feature\Proxies;

use App\Enums\HttpMethod;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProxyUpdateTest extends TestCase
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

    public function test_edit_prefills_current_values_with_live_destinations_only(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'name' => 'Original']);
        $live = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://live.example.com/hook']);
        $trashed = Destination::factory()->for($proxy)->createQuietly();
        $trashed->delete();

        $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Edit')
                ->where('proxy.name', 'Original')
                ->has('proxy.destinations', 1)
                ->where('proxy.destinations.0.id', $live->id)
            );
    }

    public function test_update_changes_name_mode_and_reconciles_destinations(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'name' => 'Old', 'mode' => ProxyMode::Simple]);
        $keep = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://keep.example.com/hook', 'http_method' => HttpMethod::Post]);
        $remove = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://remove.example.com/hook']);

        $ingestUrlBefore = $proxy->ingestUrl();

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => 'New name',
                'mode' => 'enhanced',
                'processing_mode' => 'async',
                'destinations' => [
                    // update existing kept row (change method)
                    ['id' => $keep->id, 'url' => 'https://keep.example.com/hook', 'http_method' => 'PUT'],
                    // add a new row
                    ['url' => 'https://added.example.com/hook', 'http_method' => 'POST'],
                    // (remove omitted -> soft-deleted)
                ],
            ],
        )->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $proxy->refresh();
        $this->assertSame('New name', $proxy->name);
        $this->assertSame(ProxyMode::Enhanced, $proxy->mode);

        // kept row updated, new row added, removed row soft-deleted.
        $this->assertSame(HttpMethod::Put, $keep->fresh()->http_method);
        $this->assertSoftDeleted($remove);
        $this->assertCount(2, $proxy->destinations()->get());
        $this->assertTrue($proxy->destinations()->where('url', 'https://added.example.com/hook')->exists());

        // Ingest URL is unchanged by an edit (token not rotated).
        $this->assertSame($ingestUrlBefore, $proxy->fresh()->ingestUrl());
    }

    public function test_update_sets_response_config(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $keep = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://keep.example.com/hook', 'http_method' => HttpMethod::Post]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => 'x',
                'mode' => 'simple',
                'processing_mode' => 'async',
                'response_status' => 200,
                'response_body' => 'thanks',
                'destinations' => [
                    ['id' => $keep->id, 'url' => 'https://keep.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $proxy->refresh();
        $this->assertSame(200, $proxy->response_status);
        $this->assertSame('thanks', $proxy->response_body);
    }

    public function test_update_clears_previously_configured_response_config_to_null(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'response_status' => 201,
            'response_body' => 'previously set',
        ]);
        $keep = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://keep.example.com/hook', 'http_method' => HttpMethod::Post]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => 'x',
                'mode' => 'simple',
                'processing_mode' => 'async',
                'response_status' => null,
                'response_body' => null,
                'destinations' => [
                    ['id' => $keep->id, 'url' => 'https://keep.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $proxy->refresh();
        $this->assertNull($proxy->response_status);
        $this->assertNull($proxy->response_body);
    }

    public function test_update_persists_a_processing_mode_switch(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'processing_mode' => ProcessingMode::Async,
        ]);
        $keep = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://keep.example.com/hook', 'http_method' => HttpMethod::Post]);

        $update = fn (string $mode) => $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => 'x',
                'mode' => 'simple',
                'processing_mode' => $mode,
                'destinations' => [
                    ['id' => $keep->id, 'url' => 'https://keep.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        // async -> fifo, then fifo -> async both persist.
        $update('fifo');
        $this->assertSame(ProcessingMode::Fifo, $proxy->fresh()->processing_mode);

        $update('async');
        $this->assertSame(ProcessingMode::Async, $proxy->fresh()->processing_mode);
    }

    // --- Retry policy (T30; AC2, AC20) --------------------------------------

    public function test_update_sets_retry_policy_on_an_enhanced_proxy(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $keep = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://keep.example.com/hook', 'http_method' => HttpMethod::Post]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => 'x',
                'mode' => 'enhanced',
                'processing_mode' => 'async',
                'retry_attempt_limit' => 7,
                'retry_backoff_strategy' => 'exponential',
                'destinations' => [
                    ['id' => $keep->id, 'url' => 'https://keep.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $proxy->refresh();
        $this->assertSame(7, $proxy->retry_attempt_limit);
        $this->assertSame('exponential', $proxy->retry_backoff_strategy->value);
    }

    public function test_update_clears_a_previously_configured_retry_policy_when_omitted(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'retry_attempt_limit' => 5,
            'retry_backoff_strategy' => 'fixed',
        ]);
        $keep = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://keep.example.com/hook', 'http_method' => HttpMethod::Post]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => 'x',
                'mode' => 'enhanced',
                'processing_mode' => 'async',
                'destinations' => [
                    ['id' => $keep->id, 'url' => 'https://keep.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $proxy->refresh();
        $this->assertNull($proxy->retry_attempt_limit);
        $this->assertNull($proxy->retry_backoff_strategy);
    }

    /**
     * ADR-018 Decision 3 (partially supersedes ADR-015 Decision 3, review-06
     * Minor 8(c)): a downgrade save no longer clears the stored policy — it
     * preserves it, dormant, ready to reactivate on a later return to
     * Enhanced (PRD-07 AC14).
     */
    public function test_switching_from_enhanced_to_simple_preserves_the_retry_policy(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 5,
            'retry_backoff_strategy' => 'fixed',
        ]);
        $keep = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://keep.example.com/hook', 'http_method' => HttpMethod::Post]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => 'x',
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    ['id' => $keep->id, 'url' => 'https://keep.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $proxy->refresh();
        $this->assertSame(ProxyMode::Simple, $proxy->mode);
        $this->assertSame(5, $proxy->retry_attempt_limit);
        $this->assertSame('fixed', $proxy->retry_backoff_strategy->value);
    }

    public function test_update_that_would_leave_zero_live_destinations_is_rejected(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->createQuietly();

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            ['name' => 'x', 'mode' => 'simple', 'processing_mode' => 'async', 'destinations' => []],
        )->assertInvalid(['destinations']);

        // Nothing committed: original destination remains live.
        $this->assertSame(1, $proxy->destinations()->count());
    }
}
