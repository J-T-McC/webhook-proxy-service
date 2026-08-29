<?php

namespace Tests\Feature\Proxies;

use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Tests\TestCase;

/**
 * T31 — Screen 3's Remove credential control (correction B3; `plan-10` §
 * Revision A, technical ruling 15): the sibling `destinations.*.remove_credential`
 * boolean, distinguishable end to end from an ordinary blank Replace field.
 */
class CredentialRemovalTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function credentialedDestination(Proxy $proxy): Destination
    {
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $destination->credential_header_name = 'X-Api-Key';
        $destination->credential_secret = 'original-secret';
        $destination->credential_set_at = now();
        $destination->save();

        return $destination;
    }

    /**
     * The distinguishability pair (ruling 15's whole point), asserted in one
     * test so the two cases can never be split apart independently later:
     * a present-but-empty `credential_secret` with no `remove_credential`
     * leaves the credential byte-identical; `remove_credential: true` for
     * the SAME row on the SAME route nulls it.
     */
    public function test_a_blank_replace_leaves_the_credential_unchanged_but_remove_credential_true_nulls_it(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = $this->credentialedDestination($proxy);

        // First: a present-but-empty Replace field, no remove_credential — unchanged.
        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'id' => $destination->id,
                        'url' => $destination->url,
                        'http_method' => $destination->http_method->value,
                        'credential_header_name' => 'X-Api-Key',
                        'credential_secret' => '',
                    ],
                ],
            ],
        )->assertRedirect();

        $stillSet = $destination->fresh();
        $this->assertSame('original-secret', $stillSet->credential_secret);
        $this->assertSame('X-Api-Key', $stillSet->credential_header_name);
        $this->assertNotNull($stillSet->credential_set_at);

        // Same row, same route: remove_credential: true — nulls it.
        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'id' => $destination->id,
                        'url' => $destination->url,
                        'http_method' => $destination->http_method->value,
                        'remove_credential' => true,
                    ],
                ],
            ],
        )->assertRedirect();

        $removed = $destination->fresh();
        $this->assertNull($removed->credential_secret);
        $this->assertNull($removed->credential_header_name);
        $this->assertNull($removed->credential_set_at);
    }

    public function test_a_saved_removal_leaves_all_three_credential_columns_null_via_a_raw_query(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = $this->credentialedDestination($proxy);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'id' => $destination->id,
                        'url' => $destination->url,
                        'http_method' => $destination->http_method->value,
                        'remove_credential' => true,
                    ],
                ],
            ],
        )->assertRedirect();

        $row = \DB::table('destinations')->where('id', $destination->id)->first();
        $this->assertNull($row->credential_header_name);
        $this->assertNull($row->credential_secret);
        $this->assertNull($row->credential_set_at);
    }

    public function test_remove_credential_true_with_a_non_empty_secret_is_a_422_and_changes_nothing(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = $this->credentialedDestination($proxy);

        $response = $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'id' => $destination->id,
                        'url' => $destination->url,
                        'http_method' => $destination->http_method->value,
                        'credential_header_name' => 'X-Api-Key',
                        'credential_secret' => 'a-new-secret',
                        'remove_credential' => true,
                    ],
                ],
            ],
        );

        $response->assertSessionHasErrors('destinations.0.credential_secret');

        $unchanged = $destination->fresh();
        $this->assertSame('original-secret', $unchanged->credential_secret);
        $this->assertSame('X-Api-Key', $unchanged->credential_header_name);
    }

    public function test_remove_credential_true_on_a_row_with_no_id_is_a_no_op(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $response = $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'url' => 'https://brand-new.example.com/hook',
                        'http_method' => 'POST',
                        'remove_credential' => true,
                    ],
                ],
            ],
        );

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $created = Destination::query()->where('url', 'https://brand-new.example.com/hook')->firstOrFail();
        $this->assertNull($created->credential_secret);
        $this->assertNull($created->credential_header_name);
        $this->assertNull($created->credential_set_at);
    }

    /**
     * The `transform()` supersession rule: clicking Remove credential
     * in-session, then typing a new secret into the now-unconfigured row
     * before saving, persists the NEW secret rather than removing the
     * credential. This is a frontend-only decision (`ProxyForm.vue`'s
     * `transform()`) — asserted here at the transport boundary this test
     * suite exercises, by submitting exactly what that superseding
     * `transform()` output would be: `remove_credential: false` alongside a
     * non-empty `credential_secret`.
     */
    public function test_the_transform_supersession_rule_persists_a_new_secret_typed_after_remove_was_clicked(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = $this->credentialedDestination($proxy);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'id' => $destination->id,
                        'url' => $destination->url,
                        'http_method' => $destination->http_method->value,
                        'credential_header_name' => 'Authorization',
                        'credential_secret' => 'retyped-secret',
                        'remove_credential' => false,
                    ],
                ],
            ],
        )->assertRedirect();

        $fresh = $destination->fresh();
        $this->assertSame('retyped-secret', $fresh->credential_secret);
        $this->assertSame('Authorization', $fresh->credential_header_name);
    }
}
