<?php

namespace Tests\Feature\Proxies;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Models\User;
use App\Services\SecretStore;
use Tests\TestCase;

/**
 * T20 (AC23, AC24, AC26; plan-10 §Validation) — the `verification_scheme`/
 * `verification_header_name`/`verification_secret` rules on `StoreProxyRequest`
 * and `UpdateProxyRequest`, and the write-only persistence they gate
 * (necessary plumbing named by this task's own second Acceptance Criterion —
 * "leaves the stored secret unchanged" is a persistence claim, not merely a
 * validation-shape one).
 */
class VerificationValidationTest extends TestCase
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

    public function test_shared_secret_without_a_header_name_fails_validation(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload([
                'verification_scheme' => 'shared-secret',
                'verification_secret' => 'a-fine-secret-value',
            ]),
        );

        $response->assertInvalid(['verification_header_name']);
        $this->assertSame(0, Proxy::query()->count());
    }

    public function test_standard_webhooks_with_a_header_name_present_fails_validation(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload([
                'verification_scheme' => 'standard-webhooks',
                'verification_header_name' => 'X-Signature',
                'verification_secret' => 'a-fine-secret-value',
            ]),
        );

        $response->assertInvalid(['verification_header_name']);
        $this->assertSame(0, Proxy::query()->count());
    }

    public function test_first_time_scheme_selection_with_no_secret_fails_validation(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload(['verification_scheme' => 'standard-webhooks']),
        );

        $response->assertInvalid(['verification_secret']);
        $this->assertSame(0, Proxy::query()->count());
    }

    public function test_a_valid_first_time_selection_persists_scheme_and_rotates_a_secret(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload([
                'verification_scheme' => 'standard-webhooks',
                'verification_secret' => 'a-fine-secret-value',
            ]),
        )->assertRedirect();

        $proxy = Proxy::firstOrFail();
        $this->assertNotNull($proxy->verification_scheme);
        $this->assertSame('standard-webhooks', $proxy->verification_scheme->value);
        $this->assertSame(
            ['a-fine-secret-value'],
            app(SecretStore::class)->liveFor($proxy, SecretPurpose::Verification),
        );
    }

    public function test_absent_verification_secret_on_update_leaves_the_stored_secret_unchanged(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'already-set-secret');

        $response = $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload([
                'destinations' => [['id' => null, 'url' => 'https://a.example.com/hook', 'http_method' => 'POST']],
                'verification_scheme' => 'standard-webhooks',
                // verification_secret deliberately absent (the write-only
                // "leave unchanged" contract) — no key sent at all.
            ]),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame(
            ['already-set-secret'],
            app(SecretStore::class)->liveFor($proxy, SecretPurpose::Verification),
        );
    }

    public function test_an_empty_verification_secret_on_update_never_clears_the_stored_secret(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'already-set-secret');

        $response = $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload([
                'destinations' => [['id' => null, 'url' => 'https://a.example.com/hook', 'http_method' => 'POST']],
                'verification_scheme' => 'standard-webhooks',
                'verification_secret' => '',
            ]),
        );

        // The app's global `ConvertEmptyStringsToNull` middleware normalises
        // "" to null before validation runs, so an empty submission takes
        // exactly the same "leave unchanged" path as an absent one — never a
        // clear, and never a 422 either.
        $response->assertSessionHasNoErrors();
        $this->assertSame(
            ['already-set-secret'],
            app(SecretStore::class)->liveFor($proxy, SecretPurpose::Verification),
        );
    }

    public function test_replacing_the_secret_on_update_rotates_it_with_an_overlap(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'old-secret-value');

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload([
                'destinations' => [['id' => null, 'url' => 'https://a.example.com/hook', 'http_method' => 'POST']],
                'verification_scheme' => 'standard-webhooks',
                'verification_secret' => 'new-secret-value',
            ]),
        )->assertSessionHasNoErrors();

        $live = app(SecretStore::class)->liveFor($proxy, SecretPurpose::Verification);
        $this->assertSame(['new-secret-value', 'old-secret-value'], $live);
    }

    public function test_switching_scheme_back_to_not_required_clears_the_scheme_but_keeps_the_dormant_secret(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'dormant-secret-value');

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            $this->payload([
                'destinations' => [['id' => null, 'url' => 'https://a.example.com/hook', 'http_method' => 'POST']],
                // verification_scheme absent -> not required.
            ]),
        )->assertSessionHasNoErrors();

        $proxy->refresh();
        $this->assertNull($proxy->verification_scheme);
        // The dormant secret survives (plan-10 §Architecture B) — turning
        // verification off never calls SecretStore::disable() for it.
        $this->assertSame(
            ['dormant-secret-value'],
            app(SecretStore::class)->liveFor($proxy, SecretPurpose::Verification),
        );
    }

    public function test_shared_secret_scheme_requires_a_header_name(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            $this->payload([
                'verification_scheme' => 'shared-secret',
                'verification_header_name' => 'X-Signature',
                'verification_secret' => 'a-fine-secret-value',
            ]),
        );

        $response->assertRedirect();
        $proxy = Proxy::firstOrFail();
        $this->assertSame('X-Signature', $proxy->verification_header_name);
    }
}
