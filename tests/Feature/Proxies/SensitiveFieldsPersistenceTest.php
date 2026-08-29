<?php

namespace Tests\Feature\Proxies;

use App\Models\Proxy;
use App\Models\User;
use Tests\TestCase;

/**
 * T10 — validation and persistence of `sensitive_fields` (AC13): the dedup
 * case, the blank-entry rejection, and the per-proxy isolation case.
 */
class SensitiveFieldsPersistenceTest extends TestCase
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
            ],
        ], $overrides);
    }

    public function test_a_duplicate_addition_by_normalised_form_is_not_stored_twice(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['sensitive_fields' => ['ssn_last4', 'SSN Last4', 'ssn-last-4']]),
        )->assertRedirect();

        $proxy = Proxy::firstOrFail();
        $this->assertSame(['ssn_last4'], $proxy->sensitive_fields);
    }

    public function test_a_blank_or_whitespace_only_entry_is_rejected_server_side(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['sensitive_fields' => ['ssn_last4', '   ']]),
        );

        $response->assertSessionHasErrors('sensitive_fields.1');
        $this->assertSame(0, Proxy::count());
    }

    public function test_additions_persist_per_proxy_and_a_second_proxy_is_unaffected(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['name' => 'First', 'sensitive_fields' => ['ssn_last4']]),
        )->assertRedirect();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['name' => 'Second']),
        )->assertRedirect();

        $first = Proxy::where('name', 'First')->firstOrFail();
        $second = Proxy::where('name', 'Second')->firstOrFail();

        $this->assertSame(['ssn_last4'], $first->sensitive_fields);
        $this->assertSame([], $second->sensitive_fields);
    }

    public function test_removing_an_addition_on_update_never_removes_a_default(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'sensitive_fields' => ['ssn_last4', 'internal_ref'],
        ]);
        $destination = $proxy->destinations()->create([
            'team_id' => $proxy->team_id,
            'url' => 'https://a.example.com/hook',
            'http_method' => 'POST',
        ]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'sensitive_fields' => ['ssn_last4'],
                'destinations' => [
                    ['id' => $destination->id, 'url' => 'https://a.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect();

        // The default list is code, never data — "removing" a default from
        // the submission has nothing to remove from this column at all.
        $this->assertSame(['ssn_last4'], $proxy->fresh()->sensitive_fields);
    }

    public function test_an_absent_sensitive_fields_key_on_update_clears_previously_saved_additions(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'sensitive_fields' => ['ssn_last4'],
        ]);
        $destination = $proxy->destinations()->create([
            'team_id' => $proxy->team_id,
            'url' => 'https://a.example.com/hook',
            'http_method' => 'POST',
        ]);

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    ['id' => $destination->id, 'url' => 'https://a.example.com/hook', 'http_method' => 'POST'],
                ],
            ],
        )->assertRedirect();

        $this->assertSame([], $proxy->fresh()->sensitive_fields);
    }
}
