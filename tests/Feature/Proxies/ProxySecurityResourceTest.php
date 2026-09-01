<?php

namespace Tests\Feature\Proxies;

use App\Enums\DestinationValidationSendFailure;
use App\Enums\SecretPurpose;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Services\SecretStore;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T22 (AC20, AC33; plan-10 Technical rulings 3, 5) — `ProxySecurityResource`'s
 * `security` prop on `ProxyController::show()`/`edit()`. Status only — never
 * a value, never a length.
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
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'super-secret-value-do-not-leak');

        $response = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString('super-secret-value-do-not-leak', $response->getContent());

        $editResponse = $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString('super-secret-value-do-not-leak', $editResponse->getContent());
    }

    // --- T38: the `signing` sub-object -------------------------------------

    public function test_signing_reflects_not_enabled_no_overlap_and_overlap_running_states(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('security.signing.enabled', false)
                ->where('security.signing.generated_at', null)
                ->where('security.signing.overlap_expires_at', null)
            );

        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_current');

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('security.signing.enabled', true)
                ->has('security.signing.generated_at')
                ->where('security.signing.overlap_expires_at', null)
            );

        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_new');

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('security.signing.enabled', true)
                ->has('security.signing.generated_at')
                ->has('security.signing.overlap_expires_at')
            );
    }

    public function test_the_signing_secrets_value_is_never_present_anywhere_in_this_resources_output(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'do-not-leak-this-signing-secret');

        $response = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString('do-not-leak-this-signing-secret', $response->getContent());

        $editResponse = $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString('do-not-leak-this-signing-secret', $editResponse->getContent());
    }

    // --- T15: the `validation` sub-object -----------------------------------

    /**
     * T15 (AC31, AC32, AC34) — every destination's display state reaches the
     * Show page, including Expired, which is derived server-side from a
     * pending challenge whose window closed rather than stored.
     */
    public function test_each_destination_reports_its_validation_state_including_derived_expired(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $unvalidated = Destination::factory()->for($proxy)->unvalidated()->createQuietly();
        $pending = Destination::factory()->for($proxy)->pendingValidation()->createQuietly();
        $expired = Destination::factory()->for($proxy)->expiredValidation()->createQuietly();
        $validated = Destination::factory()->for($proxy)->validated()->createQuietly();

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Show')
                ->where("security.destinations.{$unvalidated->id}.validation.status", 'unvalidated')
                ->where("security.destinations.{$unvalidated->id}.validation.challenge_sent_at", null)
                ->where("security.destinations.{$pending->id}.validation.status", 'pending')
                ->has("security.destinations.{$pending->id}.validation.challenge_sent_at")
                ->has("security.destinations.{$pending->id}.validation.challenge_expires_at")
                ->where("security.destinations.{$expired->id}.validation.status", 'expired')
                ->has("security.destinations.{$expired->id}.validation.challenge_expires_at")
                ->where("security.destinations.{$validated->id}.validation.status", 'validated')
                ->has("security.destinations.{$validated->id}.validation.approved_at")
            );
    }

    /**
     * T19 (AC35) — the outcome of the last validation send reaches the page in
     * the same `validation` object, so a member can tell "never arrived" from
     * "arrived and was rejected" from "nobody has opened it". The failure
     * travels as a key; the sentence design-18 fixes for it is the frontend's.
     */
    public function test_each_destination_reports_the_outcome_of_its_last_validation_send(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $answered = Destination::factory()->for($proxy)->pendingValidation()->createQuietly();
        $answered->forceFill(['validation_last_send_status' => 404])->save();

        $failed = Destination::factory()->for($proxy)->unvalidated()->createQuietly();
        $failed->forceFill([
            'validation_last_send_failure' => DestinationValidationSendFailure::AddressRefused,
        ])->save();

        $never = Destination::factory()->for($proxy)->unvalidated()->createQuietly();

        $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Show')
                ->where("security.destinations.{$answered->id}.validation.last_send_status", 404)
                ->where("security.destinations.{$answered->id}.validation.last_send_failure", null)
                ->where("security.destinations.{$failed->id}.validation.last_send_failure", 'address_refused')
                ->where("security.destinations.{$failed->id}.validation.last_send_status", null)
                ->where("security.destinations.{$never->id}.validation.last_send_status", null)
                ->where("security.destinations.{$never->id}.validation.last_send_failure", null)
            );
    }

    /**
     * T17 (AC5, design-18 Screen 1) — the edit form reads the same per-id
     * `validation` object the Show page does, so its rows can show their
     * persisted state and key the URL-change warning off it.
     */
    public function test_the_edit_page_carries_each_destinations_validation_state(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $validated = Destination::factory()->for($proxy)->validated()->createQuietly();
        $pending = Destination::factory()->for($proxy)->pendingValidation()->createQuietly();

        $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("security.destinations.{$validated->id}.validation.status", 'validated')
                ->where("security.destinations.{$pending->id}.validation.status", 'pending')
            );
    }

    /**
     * T15 (AC24) — the challenge's nonce, the only secret half of the
     * validation link, is never present anywhere in the page: not as a value
     * and not even as a key name, because the resource never selects the
     * column.
     */
    public function test_the_validation_nonce_never_reaches_the_page(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $pending = Destination::factory()->for($proxy)->pendingValidation()->createQuietly();

        $response = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString($pending->validation_nonce, $response->getContent());
        $this->assertStringNotContainsString('validation_nonce', $response->getContent());

        $editResponse = $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertStringNotContainsString($pending->validation_nonce, $editResponse->getContent());
        $this->assertStringNotContainsString('validation_nonce', $editResponse->getContent());
    }
}
