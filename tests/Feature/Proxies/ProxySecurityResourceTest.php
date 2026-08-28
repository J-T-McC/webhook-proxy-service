<?php

namespace Tests\Feature\Proxies;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Models\User;
use App\Services\SecretStore;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T22 (AC20, AC26, AC28; plan-10 Technical rulings 3, 5) — `ProxySecurityResource`'s
 * `verification` sub-object, wired as the sibling `security` prop on
 * `ProxyController::show()`/`edit()`. Status only — never a value, never a length.
 */
class ProxySecurityResourceTest extends TestCase
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

    public function test_show_emits_the_verification_shape_when_not_required(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Show')
                ->where('security.verification.scheme', null)
                ->where('security.verification.header_name', null)
                ->where('security.verification.secret_set', false)
                ->where('security.verification.secret_changed_at', null)
                ->where('security.verification.overlap_expires_at', null)
            );
    }

    public function test_edit_emits_the_same_security_prop_as_show(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'shared-secret',
            'verification_header_name' => 'X-Signature',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-live-secret');

        $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Edit')
                ->where('security.verification.scheme', 'shared-secret')
                ->where('security.verification.header_name', 'X-Signature')
                ->where('security.verification.secret_set', true)
                ->has('security.verification.secret_changed_at')
                ->where('security.verification.overlap_expires_at', null)
            );
    }

    public function test_a_running_overlap_carries_a_non_null_overlap_expires_at(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Show')
                ->where('security.verification.secret_set', true)
                ->has('security.verification.overlap_expires_at')
            );
    }

    public function test_index_gains_no_security_prop(): void
    {
        $user = $this->actingUser();
        Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Index')
                ->missing('security')
            );
    }

    public function test_the_response_never_contains_the_secret_value(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => 'standard-webhooks',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'super-secret-value-do-not-leak');

        $response = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString('super-secret-value-do-not-leak', $response->getContent());

        $editResponse = $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString('super-secret-value-do-not-leak', $editResponse->getContent());
    }
}
