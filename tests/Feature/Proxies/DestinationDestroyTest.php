<?php

namespace Tests\Feature\Proxies;

use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Tests\TestCase;

class DestinationDestroyTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function route(User $user, Proxy $proxy, Destination $destination): string
    {
        return route('proxies.destinations.destroy', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'destination' => $destination->id,
        ]);
    }

    public function test_removing_a_non_last_destination_soft_deletes_it(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $a = Destination::factory()->for($proxy)->createQuietly();
        $b = Destination::factory()->for($proxy)->createQuietly();

        $this->actingAs($user)
            ->delete($this->route($user, $proxy, $a))
            ->assertRedirect(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $this->assertSoftDeleted($a);
        $this->assertSame(1, $proxy->destinations()->count());
        $this->assertTrue($proxy->destinations()->whereKey($b->id)->exists());
    }

    public function test_removing_the_last_live_destination_is_refused(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $only = Destination::factory()->for($proxy)->createQuietly();

        $this->actingAs($user)
            ->deleteJson($this->route($user, $proxy, $only))
            ->assertStatus(422);

        // Nothing removed.
        $this->assertNotSoftDeleted($only);
        $this->assertSame(1, $proxy->destinations()->count());
    }

    public function test_cross_team_destination_returns_404(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $other = User::factory()->createQuietly();
        $foreignProxy = Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);
        $foreignDestination = Destination::factory()->for($foreignProxy)->createQuietly();

        // Attempt to remove a foreign proxy's destination via the acting team.
        $this->actingAs($user)
            ->delete(route('proxies.destinations.destroy', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $foreignProxy->id,
                'destination' => $foreignDestination->id,
            ]))
            ->assertNotFound();

        $this->assertNotSoftDeleted($foreignDestination);
    }
}
