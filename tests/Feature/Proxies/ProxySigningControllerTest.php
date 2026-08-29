<?php

namespace Tests\Feature\Proxies;

use App\Enums\SecretPurpose;
use App\Enums\TeamRole;
use App\Models\Proxy;
use App\Models\User;
use App\Services\SecretStore;
use Tests\TestCase;

/**
 * T37 (AC56, AC57, AC58; plan-10 §API, Technical ruling 5) — the three
 * proxy-scoped signing endpoints. `store()` is the one JSON-returning
 * endpoint in this whole feature — the one-time secret reveal.
 */
class ProxySigningControllerTest extends TestCase
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

    private function storeRoute(User $user, Proxy $proxy): string
    {
        return route('proxies.signing.store', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ]);
    }

    private function destroyRoute(User $user, Proxy $proxy): string
    {
        return route('proxies.signing.destroy', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ]);
    }

    private function overlapRoute(User $user, Proxy $proxy): string
    {
        return route('proxies.signing.overlap.destroy', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ]);
    }

    public function test_store_always_generates_a_different_secret_and_returns_it_once_with_no_store(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $first = $this->actingAs($user)->postJson($this->storeRoute($user, $proxy))->assertOk();
        $firstSecret = $first->json('secret');
        $this->assertNotEmpty($firstSecret);
        $this->assertNotNull($first->json('generated_at'));
        $first->assertHeader('Cache-Control', 'no-store, private');

        $second = $this->actingAs($user)->postJson($this->storeRoute($user, $proxy))->assertOk();
        $secondSecret = $second->json('secret');

        $this->assertNotSame($firstSecret, $secondSecret);
        $second->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(
            [$secondSecret, $firstSecret],
            app(SecretStore::class)->liveFor($proxy->fresh(), SecretPurpose::Signing),
        );
    }

    public function test_the_generated_secret_never_appears_in_any_subsequent_page_prop_or_response(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $secret = $this->actingAs($user)
            ->postJson($this->storeRoute($user, $proxy))
            ->assertOk()
            ->json('secret');

        $show = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();
        $edit = $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();
        $index = $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk();

        $this->assertStringNotContainsString($secret, $show->getContent());
        $this->assertStringNotContainsString($secret, $edit->getContent());
        $this->assertStringNotContainsString($secret, $index->getContent());
    }

    public function test_destroy_deletes_every_signing_row_and_a_subsequent_store_produces_a_different_value(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Signing, 'old-secret');
        $store->replace($proxy, SecretPurpose::Signing, 'current-secret');

        $this->actingAs($user)->delete($this->destroyRoute($user, $proxy))->assertRedirect();

        $this->assertSame([], $store->liveFor($proxy, SecretPurpose::Signing));

        $regenerated = $this->actingAs($user)
            ->postJson($this->storeRoute($user, $proxy))
            ->assertOk()
            ->json('secret');

        $this->assertNotSame('old-secret', $regenerated);
        $this->assertNotSame('current-secret', $regenerated);
    }

    public function test_the_overlap_end_endpoint_stops_the_previous_signing_secret_being_included_immediately(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Signing, 'old-secret');
        $store->replace($proxy, SecretPurpose::Signing, 'new-secret');

        $this->assertSame(['new-secret', 'old-secret'], $store->liveFor($proxy, SecretPurpose::Signing));

        $this->actingAs($user)->delete($this->overlapRoute($user, $proxy))->assertRedirect();

        $this->assertSame(['new-secret'], $store->liveFor($proxy, SecretPurpose::Signing));
    }

    public function test_a_member_without_update_rights_on_a_teammates_proxy_is_forbidden_on_all_three_endpoints(): void
    {
        $owner = $this->actingUser();
        $team = $owner->currentTeam;
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'created_by' => $owner->id,
        ]);
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Signing, 'old-secret');
        $store->replace($proxy, SecretPurpose::Signing, 'new-secret');

        $member = User::factory()->createQuietly();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        $this->actingAs($member)->postJson($this->storeRoute($member, $proxy))->assertForbidden();
        $this->actingAs($member)->delete($this->overlapRoute($member, $proxy))->assertForbidden();
        $this->actingAs($member)->delete($this->destroyRoute($member, $proxy))->assertForbidden();

        // Nothing changed.
        $this->assertSame(['new-secret', 'old-secret'], $store->liveFor($proxy, SecretPurpose::Signing));
    }
}
