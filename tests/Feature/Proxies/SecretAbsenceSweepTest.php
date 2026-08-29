<?php

namespace Tests\Feature\Proxies;

use App\Enums\SecretPurpose;
use App\Http\Resources\ProxyFormResource;
use App\Http\Resources\ProxyResource;
use App\Http\Resources\ProxySecurityResource;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\SecretStore;
use Tests\TestCase;

/**
 * T48 (R6) — a sweep across every proxy-bearing response (`show`, `edit`,
 * `index`, the events pages, and the payload endpoint) asserting the absence
 * of every stored secret's value, plus a deliberately constructed case
 * proving the two independent guards (`ProxySecret` is never serialized into
 * any resource; `ProxySecret::$hidden = ['value']`) both hold even when the
 * `secrets` relation has been eager-loaded onto the proxy before
 * serialization — the mistake neither guard alone is meant to require.
 */
class SecretAbsenceSweepTest extends TestCase
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

    /**
     * A proxy carrying every kind of stored secret this feature knows about
     * — a destination credential and a live signing secret — plus one
     * captured event, so every one of the five surfaces below has something
     * to leak if a guard were broken.
     *
     * @return array{Proxy, Destination, WebhookEvent}
     */
    private function proxyWithEverySecret(User $user): array
    {
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $destination = Destination::factory()->for($proxy)->createQuietly();
        $destination->credential_header_name = 'X-Api-Key';
        $destination->credential_secret = 'do-not-leak-destination-credential';
        $destination->credential_set_at = now();
        $destination->save();

        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'do-not-leak-signing-secret');

        $event = WebhookEvent::factory()->for($proxy)->createQuietly();

        return [$proxy, $destination, $event];
    }

    private function assertNoSecretLeak(string $content): void
    {
        $this->assertStringNotContainsString('do-not-leak-destination-credential', $content);
        $this->assertStringNotContainsString('do-not-leak-signing-secret', $content);
    }

    // --- Ordinary query path: the five surfaces as production actually builds them ---

    public function test_index_carries_no_secret_value(): void
    {
        $user = $this->actingUser();
        $this->proxyWithEverySecret($user);

        $response = $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk();

        $this->assertNoSecretLeak($response->getContent());
    }

    public function test_show_carries_no_secret_value(): void
    {
        $user = $this->actingUser();
        [$proxy] = $this->proxyWithEverySecret($user);

        $response = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertNoSecretLeak($response->getContent());
    }

    public function test_edit_carries_no_secret_value(): void
    {
        $user = $this->actingUser();
        [$proxy] = $this->proxyWithEverySecret($user);

        $response = $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertNoSecretLeak($response->getContent());
    }

    public function test_events_index_carries_no_secret_value(): void
    {
        $user = $this->actingUser();
        [$proxy] = $this->proxyWithEverySecret($user);

        $response = $this->actingAs($user)
            ->get(route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();

        $this->assertNoSecretLeak($response->getContent());
    }

    public function test_events_show_carries_no_secret_value(): void
    {
        $user = $this->actingUser();
        [$proxy, , $event] = $this->proxyWithEverySecret($user);

        $response = $this->actingAs($user)
            ->get(route('proxies.events.show', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]))
            ->assertOk();

        $this->assertNoSecretLeak($response->getContent());
    }

    public function test_payload_endpoint_carries_no_secret_value(): void
    {
        $user = $this->actingUser();
        [$proxy, , $event] = $this->proxyWithEverySecret($user);

        $response = $this->actingAs($user)
            ->get(route('proxies.events.payload', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]))
            ->assertOk();

        $this->assertNoSecretLeak($response->getContent());
    }

    // --- Deliberately-constructed eager-loaded-relation path ---------------

    /**
     * Every resource that carries a `Proxy` onto one of the five surfaces
     * above — `ProxyResource` (index/show/events pages), `ProxyFormResource`
     * (edit), and `ProxySecurityResource` (show/edit) — is exercised directly
     * against a proxy whose `secrets` relation was eager-loaded ahead of
     * serialization, the mistake `ProxySecret`'s own doc-block calls out
     * ("this relation is never eager-loaded onto a resource"). Both guards
     * must hold independently of that discipline actually being followed:
     * `ProxySecret::$hidden = ['value']` even if some future resource change
     * started reading `$proxy->secrets`, and no resource here reads the
     * relation at all today regardless.
     */
    public function test_secrets_eager_loaded_before_serialization_still_never_leaks(): void
    {
        $user = $this->actingUser();
        [$proxy] = $this->proxyWithEverySecret($user);

        $eagerLoaded = $proxy->fresh()->load(['destinations', 'secrets']);

        $this->assertGreaterThan(0, $eagerLoaded->secrets->count(), 'Fixture must actually carry a loaded secret to prove anything.');

        $this->actingAs($user);

        $proxyContent = json_encode((new ProxyResource($eagerLoaded))->resolve());
        $formContent = json_encode((new ProxyFormResource($eagerLoaded))->resolve());
        $securityContent = json_encode((new ProxySecurityResource($eagerLoaded))->resolve());

        $this->assertIsString($proxyContent);
        $this->assertIsString($formContent);
        $this->assertIsString($securityContent);

        // Guard 1 — never serialized into a resource: no resource output carries
        // the relation at all, loaded or not.
        $this->assertStringNotContainsString('"secrets"', $proxyContent);
        $this->assertStringNotContainsString('"secrets"', $formContent);
        $this->assertStringNotContainsString('"secrets"', $securityContent);

        // Guard 2 — ProxySecret::$hidden = ['value'] — proven directly against
        // the eager-loaded relation collection itself, independent of whether
        // any resource reads it.
        $secretArray = $eagerLoaded->secrets->first()?->toArray();
        $this->assertIsArray($secretArray);
        $this->assertArrayNotHasKey('value', $secretArray);

        // Belt-and-suspenders: the literal secret values never surface anywhere
        // in any of the three resources' output either.
        $this->assertNoSecretLeak($proxyContent);
        $this->assertNoSecretLeak($formContent);
        $this->assertNoSecretLeak($securityContent);
    }
}
