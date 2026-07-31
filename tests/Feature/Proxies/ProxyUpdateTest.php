<?php

namespace Tests\Feature\Proxies;

use App\Enums\HttpMethod;
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

    public function test_update_that_would_leave_zero_live_destinations_is_rejected(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->createQuietly();

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            ['name' => 'x', 'mode' => 'simple', 'destinations' => []],
        )->assertInvalid(['destinations']);

        // Nothing committed: original destination remains live.
        $this->assertSame(1, $proxy->destinations()->count());
    }
}
