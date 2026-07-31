<?php

namespace Tests\Feature\Proxies;

use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Tests\TestCase;

class ProxyDestroyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    public function test_destroy_soft_deletes_proxy_and_destinations_and_retains_attempts(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->create(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->create();
        $attempt = DeliveryAttempt::factory()->create([
            'team_id' => $user->current_team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
        ]);

        $this->actingAs($user)
            ->delete(route('proxies.destroy', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertRedirect(route('proxies.index', ['current_team' => $user->currentTeam->slug]));

        // Proxy + destination soft-deleted (not hard-deleted).
        $this->assertSoftDeleted($proxy);
        $this->assertSoftDeleted($destination);

        // No longer visible via team-scoped default queries.
        $this->assertNull(Proxy::find($proxy->id));

        // delivery_attempts retained + still queryable.
        $this->assertDatabaseHas('delivery_attempts', ['id' => $attempt->id]);
        $this->actingAs($user);
        $this->assertSame(1, DeliveryAttempt::where('proxy_id', $proxy->id)->count());
    }

    public function test_soft_deleted_proxy_token_no_longer_ingests(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->create(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->create();
        $token = $proxy->ingest_token;

        $this->actingAs($user)
            ->delete(route('proxies.destroy', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]));

        $this->post('https://localhost/ingest/'.$token)->assertStatus(404);
    }

    public function test_new_proxy_after_soft_delete_still_gets_a_distinct_hash(): void
    {
        $user = $this->actingUser();
        $deleted = Proxy::factory()->create(['team_id' => $user->current_team_id]);
        $deletedHash = $deleted->ingest_token_hash;
        $deleted->delete();

        $fresh = Proxy::factory()->create(['team_id' => $user->current_team_id]);

        // The soft-deleted row keeps its hash slot; a new proxy never reuses it.
        $this->assertNotSame($deletedHash, $fresh->ingest_token_hash);
        $this->assertDatabaseHas('proxies', ['id' => $deleted->id, 'ingest_token_hash' => $deletedHash]);
    }
}
