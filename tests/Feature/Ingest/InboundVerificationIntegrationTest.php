<?php

namespace Tests\Feature\Ingest;

use App\Actions\ProcessIngestedWebhook;
use App\Enums\SecretPurpose;
use App\Enums\TeamRole;
use App\Enums\VerificationScheme;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\SecretStore;
use App\Support\StandardWebhooks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T25 — no production code. The end-to-end pinning pass across everything
 * T16–T22 built (AC24, AC25, AC28, AC29, AC51–AC53; ADR-022 Decision 6),
 * through real HTTP requests rather than unit calls.
 */
class InboundVerificationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function ingestUrl(string $token): string
    {
        return 'https://localhost/ingest/'.$token;
    }

    private function sharedSecretProxy(): Proxy
    {
        $proxy = Proxy::factory()->createQuietly([
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'x-webhook-secret',
        ]);
        Destination::factory()->for($proxy)->createQuietly();

        return $proxy;
    }

    private function standardWebhooksProxy(): Proxy
    {
        $proxy = Proxy::factory()->createQuietly([
            'verification_scheme' => VerificationScheme::StandardWebhooks,
        ]);
        Destination::factory()->for($proxy)->createQuietly();

        return $proxy;
    }

    /**
     * @return array{0: string, 1: array<string, string>} the raw JSON body
     *                                                    and the three
     *                                                    signed headers
     */
    private function signedRequest(string $secret, ?int $timestampOverride = null): array
    {
        $body = json_encode(['hello' => 'world']);
        $id = 'msg_'.Str::uuid();
        $timestamp = $timestampOverride ?? time();
        $signature = 'v1,'.StandardWebhooks::sign($id, $timestamp, $body, $secret);

        return [$body, [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => $signature,
        ]];
    }

    // --- AC24: a proxy with no verification behaves identically to today,
    // and queries proxy_secrets zero times ---------------------------------

    public function test_ac24_a_proxy_with_no_verification_is_unaffected_and_queries_proxy_secrets_zero_times(): void
    {
        $proxy = Proxy::factory()->createQuietly([
            'verification_scheme' => null,
            'response_status' => 200,
            'response_body' => 'the-configured-response',
        ]);
        Destination::factory()->for($proxy)->createQuietly();

        $proxySecretsQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$proxySecretsQueries): void {
            if (str_contains($query->sql, 'proxy_secrets')) {
                $proxySecretsQueries++;
            }
        });

        $response = $this->post($this->ingestUrl($proxy->ingest_token), ['hello' => 'world']);

        $response->assertStatus(200);
        $this->assertSame('the-configured-response', $response->getContent());
        $this->assertSame(1, WebhookEvent::count());
        ProcessIngestedWebhook::assertPushed(1);
        $this->assertSame(0, $proxySecretsQueries);
    }

    // --- shared-secret: correct/wrong value, missing header, wrong header
    // name -------------------------------------------------------------

    public function test_shared_secret_correct_value_verifies_and_captures(): void
    {
        $proxy = $this->sharedSecretProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        $this->post(
            $this->ingestUrl($proxy->ingest_token),
            ['hello' => 'world'],
            ['x-webhook-secret' => 'correct-secret'],
        )->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
        ProcessIngestedWebhook::assertPushed(1);
    }

    public function test_shared_secret_wrong_value_is_rejected(): void
    {
        $proxy = $this->sharedSecretProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        $this->post(
            $this->ingestUrl($proxy->ingest_token),
            ['hello' => 'world'],
            ['x-webhook-secret' => 'wrong-secret'],
        )->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
        ProcessIngestedWebhook::assertNotPushed();
    }

    public function test_shared_secret_missing_header_is_rejected(): void
    {
        $proxy = $this->sharedSecretProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        $this->post($this->ingestUrl($proxy->ingest_token), ['hello' => 'world'])
            ->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_shared_secret_wrong_header_name_is_rejected(): void
    {
        $proxy = $this->sharedSecretProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        $this->post(
            $this->ingestUrl($proxy->ingest_token),
            ['hello' => 'world'],
            ['x-some-other-header' => 'correct-secret'],
        )->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
    }

    // --- standard-webhooks -------------------------------------------------

    public function test_standard_webhooks_specification_computed_signature_verifies(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-fine-secret');
        [$body, $headers] = $this->signedRequest('a-fine-secret');

        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($body, true), $headers)
            ->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_standard_webhooks_multi_entry_signature_list_verifies_on_second_entry(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-fine-secret');
        [$body, $headers] = $this->signedRequest('a-fine-secret');

        // Prepend a bogus entry — only the second (real) one matches.
        $headers['webhook-signature'] = 'v1,bm90LXRoZS1yaWdodC1zaWc= '.$headers['webhook-signature'];

        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($body, true), $headers)
            ->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_standard_webhooks_non_v1_entry_is_skipped_and_a_later_entry_still_verifies(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-fine-secret');
        [$body, $headers] = $this->signedRequest('a-fine-secret');

        $headers['webhook-signature'] = 'v2,whatever '.$headers['webhook-signature'];

        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($body, true), $headers)
            ->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_standard_webhooks_missing_header_fails(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-fine-secret');
        [$body, $headers] = $this->signedRequest('a-fine-secret');
        unset($headers['webhook-timestamp']);

        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($body, true), $headers)
            ->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_standard_webhooks_malformed_header_fails(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-fine-secret');
        [$body, $headers] = $this->signedRequest('a-fine-secret');
        $headers['webhook-timestamp'] = 'not-a-number';

        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($body, true), $headers)
            ->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_standard_webhooks_hex_instead_of_base64_fails(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-fine-secret');
        $body = json_encode(['hello' => 'world']);
        $id = 'msg_'.Str::uuid();
        $timestamp = time();
        $hexSignature = hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", 'a-fine-secret');

        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($body, true), [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,'.$hexSignature,
        ])->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_standard_webhooks_whsec_prefixed_and_bare_secret_both_verify(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'whsec_'.base64_encode('raw-key-bytes'));
        [$body, $headers] = $this->signedRequest('whsec_'.base64_encode('raw-key-bytes'));

        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($body, true), $headers)
            ->assertStatus(202);
        $this->assertSame(1, WebhookEvent::count());

        // A second proxy, the bare (unprefixed) form of the same key material.
        $proxy2 = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy2, SecretPurpose::Verification, base64_encode('raw-key-bytes'));
        [$body2, $headers2] = $this->signedRequest(base64_encode('raw-key-bytes'));

        $this->postJson($this->ingestUrl($proxy2->ingest_token), json_decode($body2, true), $headers2)
            ->assertStatus(202);
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_standard_webhooks_tolerance_boundary(): void
    {
        $proxy = $this->standardWebhooksProxy();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'a-fine-secret');

        // TOLERANCE_SECONDS + 1 outside now -> rejected.
        [$bodyOutside, $headersOutside] = $this->signedRequest(
            'a-fine-secret',
            time() - (StandardWebhooks::TOLERANCE_SECONDS + 1),
        );
        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($bodyOutside, true), $headersOutside)
            ->assertStatus(401);
        $this->assertSame(0, WebhookEvent::count());

        // One second inside -> accepted.
        [$bodyInside, $headersInside] = $this->signedRequest(
            'a-fine-secret',
            time() - (StandardWebhooks::TOLERANCE_SECONDS - 1),
        );
        $this->postJson($this->ingestUrl($proxy->ingest_token), json_decode($bodyInside, true), $headersInside)
            ->assertStatus(202);
        $this->assertSame(1, WebhookEvent::count());
    }

    // --- AC29, end to end over HTTP -----------------------------------

    public function test_ac29_during_an_overlap_both_secrets_verify_inbound(): void
    {
        $proxy = $this->sharedSecretProxy();
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 1], ['x-webhook-secret' => 'old-secret'])
            ->assertStatus(202);
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 2], ['x-webhook-secret' => 'new-secret'])
            ->assertStatus(202);

        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_ac29_after_expiry_only_the_current_secret_verifies_with_no_sweeper_run(): void
    {
        $proxy = $this->sharedSecretProxy();
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        // Push the superseded row's expiry into the past directly — no
        // sweeper, no job, ever run (liveness is a property of the data).
        ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->whereNull('is_current')
            ->update(['expires_at' => now()->subMinute()]);

        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 1], ['x-webhook-secret' => 'old-secret'])
            ->assertStatus(401);
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 2], ['x-webhook-secret' => 'new-secret'])
            ->assertStatus(202);

        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_ac29_a_second_rotation_discards_the_oldest_secret_immediately(): void
    {
        $proxy = $this->sharedSecretProxy();
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'oldest');
        $store->replace($proxy, SecretPurpose::Verification, 'middle');
        $store->replace($proxy, SecretPurpose::Verification, 'newest');

        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 1], ['x-webhook-secret' => 'oldest'])
            ->assertStatus(401);
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 2], ['x-webhook-secret' => 'middle'])
            ->assertStatus(202);
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 3], ['x-webhook-secret' => 'newest'])
            ->assertStatus(202);
    }

    public function test_ac29_end_overlap_now_discards_the_previous_secret_immediately(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'x-webhook-secret',
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        // Verifies now, before ending the overlap.
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 1], ['x-webhook-secret' => 'old-secret'])
            ->assertStatus(202);

        $this->actingAs($user)->delete(route('proxies.verification.overlap.destroy', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ]))->assertRedirect();

        // The previous secret stops verifying immediately.
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 2], ['x-webhook-secret' => 'old-secret'])
            ->assertStatus(401);
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 3], ['x-webhook-secret' => 'new-secret'])
            ->assertStatus(202);
    }

    // --- AC28: every new mutating endpoint this milestone added is 403 for
    // a Member without update rights on a teammate's proxy -----------------

    public function test_ac28_a_member_without_update_rights_is_forbidden_on_the_overlap_destroy_endpoint(): void
    {
        $owner = $this->actingUser();
        $team = $owner->currentTeam;
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'created_by' => $owner->id,
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'x-webhook-secret',
        ]);
        Destination::factory()->for($proxy)->createQuietly();
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Verification, 'old-secret');
        $store->replace($proxy, SecretPurpose::Verification, 'new-secret');

        $member = User::factory()->createQuietly();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        $this->actingAs($member)->delete(route('proxies.verification.overlap.destroy', [
            'current_team' => $team->slug,
            'proxy' => $proxy->id,
        ]))->assertForbidden();

        // Nothing changed — both secrets still verify.
        $this->post($this->ingestUrl($proxy->ingest_token), ['a' => 1], ['x-webhook-secret' => 'old-secret'])
            ->assertStatus(202);
    }

    // --- ADR-022 Decision 6: nothing re-verifies on replay -----------------

    public function test_a_replay_of_a_verified_events_event_dispatches_without_reverifying(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'x-webhook-secret',
        ]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        // A genuinely verified capture.
        $this->post(
            $this->ingestUrl($proxy->ingest_token),
            ['hello' => 'world'],
            ['x-webhook-secret' => 'correct-secret'],
        )->assertStatus(202);
        $event = WebhookEvent::firstOrFail();

        // Corrupt the stored secret's ciphertext so ANY live re-verification
        // attempt would throw SecretUnavailableException (fail loud, T14) —
        // if replay re-verified, this would 500 rather than redirect.
        DB::table('proxy_secrets')
            ->where('proxy_id', $proxy->id)
            ->update(['value' => 'not-valid-ciphertext']);

        $this->actingAs($user)->post(route('proxies.events.replay', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'event' => $event->id,
        ]), ['destinations' => [$destination->id]])->assertRedirect();

        $this->assertSame(1, Delivery::query()->where('webhook_event_id', $event->id)->count());
    }
}
