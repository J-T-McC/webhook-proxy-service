<?php

namespace Tests\Feature\Proxies;

use App\Enums\SecretPurpose;
use App\Enums\TeamRole;
use App\Models\Proxy;
use App\Models\User;
use App\Services\SecretStore;
use Tests\TestCase;

/**
 * T21 (AC29; plan-10 §API) — `ProxyVerificationOverlapController@destroy`
 * ends an inbound verification rotation overlap immediately.
 */
class ProxyVerificationOverlapControllerTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function route(User $user, Proxy $proxy): string
    {
        return route('proxies.verification.overlap.destroy', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ]);
    }

    public function test_ending_an_overlap_stops_the_previous_secret_verifying_immediately(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        // Overlap running — both verify.
        $this->assertSame(['new-secret', 'old-secret'], $store->liveFor($proxy, SecretPurpose::Verification));

        $this->actingAs($user)
            ->delete($this->route($user, $proxy))
            ->assertRedirect();

        // The previous secret stops verifying immediately — no longer in the live set.
        $this->assertSame(['new-secret'], $store->liveFor($proxy, SecretPurpose::Verification));
    }

    public function test_ending_an_overlap_is_idempotent(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        $this->actingAs($user)->delete($this->route($user, $proxy))->assertRedirect();
        $this->actingAs($user)->delete($this->route($user, $proxy))->assertRedirect();

        $this->assertSame(['new-secret'], $store->liveFor($proxy, SecretPurpose::Verification));
    }

    public function test_ending_an_overlap_when_none_is_running_is_a_no_op(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'only-secret');

        $this->actingAs($user)
            ->delete($this->route($user, $proxy))
            ->assertRedirect();

        $this->assertSame(['only-secret'], app(SecretStore::class)->liveFor($proxy, SecretPurpose::Verification));
    }

    public function test_a_member_without_update_rights_on_a_teammates_proxy_is_forbidden(): void
    {
        $owner = $this->actingUser();
        $team = $owner->currentTeam;
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'created_by' => $owner->id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        $member = User::factory()->createQuietly();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        $this->actingAs($member)
            ->delete($this->route($member, $proxy))
            ->assertForbidden();

        // Nothing changed.
        $this->assertSame(['new-secret', 'old-secret'], $store->liveFor($proxy, SecretPurpose::Verification));
    }
}
