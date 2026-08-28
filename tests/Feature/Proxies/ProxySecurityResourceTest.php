<?php

namespace Tests\Feature\Proxies;

use App\Enums\SecretPurpose;
use App\Models\Destination;
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

    /**
     * T32 — the `destinations` map's shape (AC30, AC33): one entry per
     * destination the proxy has, keyed by id, `has_credential`/
     * `credential_changed_at` only. Includes a soft-deleted destination
     * (Technical ruling 4 — the same superset `Show.vue`'s analytics-sourced
     * Destinations table, T33, can render).
     */
    public function test_the_destinations_map_has_one_entry_per_destination_keyed_by_id(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $withCredential = Destination::factory()->for($proxy)->createQuietly();
        $withCredential->credential_header_name = 'X-Api-Key';
        $withCredential->credential_secret = 'do-not-leak-this-value';
        $withCredential->credential_set_at = now();
        $withCredential->save();

        $withoutCredential = Destination::factory()->for($proxy)->createQuietly();

        $trashedWithCredential = Destination::factory()->for($proxy)->createQuietly();
        $trashedWithCredential->credential_header_name = 'Authorization';
        $trashedWithCredential->credential_secret = 'also-do-not-leak-this';
        $trashedWithCredential->credential_set_at = now();
        $trashedWithCredential->save();
        $trashedWithCredential->delete();

        $response = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Show')
                ->where("security.destinations.{$withCredential->id}.has_credential", true)
                ->has("security.destinations.{$withCredential->id}.credential_changed_at")
                ->where("security.destinations.{$withoutCredential->id}.has_credential", false)
                ->where("security.destinations.{$withoutCredential->id}.credential_changed_at", null)
                ->where("security.destinations.{$trashedWithCredential->id}.has_credential", true)
                ->has("security.destinations.{$trashedWithCredential->id}.credential_changed_at")
            );

        $this->assertStringNotContainsString('do-not-leak-this-value', $response->getContent());
        $this->assertStringNotContainsString('also-do-not-leak-this', $response->getContent());
        $this->assertStringNotContainsString('credential_secret', $response->getContent());
    }

    /**
     * T32 — Technical ruling 4: putting security flags on the analytics DTO
     * would make the analytics service read secret columns and reopen a
     * shape plan-11 certified. Grep-level check that this task's diff never
     * touched either file.
     */
    public function test_the_analytics_dto_and_service_are_untouched_by_this_task(): void
    {
        $dtoPath = base_path('app/Data/Analytics/DestinationBreakdownRow.php');
        $servicePath = base_path('app/Services/DeliveryStatistics.php');

        $this->assertStringNotContainsString('credential', file_get_contents($dtoPath));
        $this->assertStringNotContainsString('credential', file_get_contents($servicePath));
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
