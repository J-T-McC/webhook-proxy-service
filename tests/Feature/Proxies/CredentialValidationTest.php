<?php

namespace Tests\Feature\Proxies;

use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Tests\TestCase;

/**
 * T29 — credential validation and persistence (AC30, AC33).
 */
class CredentialValidationTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    public function test_a_header_name_without_a_secret_value_does_not_fail_validation(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            [
                'name' => 'Stripe proxy',
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'url' => 'https://a.example.com/hook',
                        'http_method' => 'POST',
                        'credential_header_name' => 'X-Api-Key',
                    ],
                ],
            ],
        );

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $destination = Destination::firstOrFail();
        $this->assertNull($destination->credential_secret);
    }

    public function test_a_secret_value_without_a_header_name_fails_validation(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            [
                'name' => 'Stripe proxy',
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'url' => 'https://a.example.com/hook',
                        'http_method' => 'POST',
                        'credential_secret' => 'top-secret-value',
                    ],
                ],
            ],
        );

        $response->assertSessionHasErrors('destinations.0.credential_header_name');
        $this->assertSame(0, Proxy::count());
    }

    public function test_an_empty_credential_secret_on_a_destination_that_already_has_one_leaves_it_stored_unchanged(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $destination->credential_header_name = 'X-Api-Key';
        $destination->credential_secret = 'original-secret';
        $destination->credential_set_at = now();
        $destination->save();
        $originalChangedAt = $destination->credential_set_at;

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
                        'credential_secret' => '',
                    ],
                ],
            ],
        );

        $response->assertRedirect();

        $fresh = $destination->fresh();
        $this->assertSame('original-secret', $fresh->credential_secret);
        $this->assertSame('X-Api-Key', $fresh->credential_header_name);
        $this->assertTrue($originalChangedAt->equalTo($fresh->credential_set_at));
    }

    public function test_a_changed_header_name_with_a_blank_secret_persists_the_new_name_and_leaves_the_secret_and_changed_at_unchanged(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $destination->credential_header_name = 'Authorization';
        $destination->credential_secret = 'original-secret';
        $destination->credential_set_at = now();
        $destination->save();
        $originalChangedAt = $destination->credential_set_at;

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
                        'credential_secret' => '',
                    ],
                ],
            ],
        );

        $response->assertRedirect();

        $fresh = $destination->fresh();
        $this->assertSame('X-Api-Key', $fresh->credential_header_name);
        $this->assertSame('original-secret', $fresh->credential_secret);
        $this->assertTrue($originalChangedAt->equalTo($fresh->credential_set_at));
    }

    public function test_a_new_destination_row_added_this_session_persists_its_credential_exactly_like_a_replacement(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $existing = Destination::factory()->for($proxy)->createQuietly();

        $response = $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'id' => $existing->id,
                        'url' => $existing->url,
                        'http_method' => $existing->http_method->value,
                    ],
                    [
                        'url' => 'https://new.example.com/hook',
                        'http_method' => 'POST',
                        'credential_header_name' => 'X-Api-Key',
                        'credential_secret' => 'brand-new-secret',
                    ],
                ],
            ],
        );

        $response->assertRedirect();

        $created = Destination::query()->where('url', 'https://new.example.com/hook')->firstOrFail();
        $this->assertSame('brand-new-secret', $created->credential_secret);
        $this->assertSame('X-Api-Key', $created->credential_header_name);
        $this->assertNotNull($created->credential_set_at);
    }
}
