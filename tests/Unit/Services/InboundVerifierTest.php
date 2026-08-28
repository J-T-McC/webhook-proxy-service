<?php

namespace Tests\Unit\Services;

use App\Enums\SecretPurpose;
use App\Enums\VerificationResult;
use App\Enums\VerificationScheme;
use App\Exceptions\SecretUnavailableException;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\InboundVerifier;
use App\Services\SecretStore;
use App\Support\StandardWebhooks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T18 (AC24, AC25) — the resolution-time verification gate.
 */
class InboundVerifierTest extends TestCase
{
    private function makeProxy(array $attributes = []): Proxy
    {
        $team = Team::factory()->createQuietly();

        return Proxy::factory()->state(['team_id' => $team->id, ...$attributes])->createQuietly();
    }

    private function verifier(): InboundVerifier
    {
        return app(InboundVerifier::class);
    }

    public function test_a_proxy_with_no_scheme_returns_not_required_and_issues_zero_proxy_secrets_queries(): void
    {
        $proxy = $this->makeProxy(['verification_scheme' => null]);
        $request = Request::create('/ingest/token', 'POST', content: '{}');

        $proxySecretsQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$proxySecretsQueries): void {
            if (str_contains($query->sql, 'proxy_secrets')) {
                $proxySecretsQueries++;
            }
        });

        $result = $this->verifier()->verify($proxy, $request, '{}');

        $this->assertSame(VerificationResult::NotRequired, $result);
        $this->assertSame(0, $proxySecretsQueries);
    }

    public function test_a_correctly_verifying_shared_secret_request_returns_verified(): void
    {
        $proxy = $this->makeProxy([
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'x-webhook-secret',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        $request = Request::create('/ingest/token', 'POST', content: '{}');
        $request->headers->set('x-webhook-secret', 'correct-secret');

        $result = $this->verifier()->verify($proxy, $request, '{}');

        $this->assertSame(VerificationResult::Verified, $result);
    }

    public function test_an_incorrect_shared_secret_request_returns_failed(): void
    {
        $proxy = $this->makeProxy([
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'x-webhook-secret',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        $request = Request::create('/ingest/token', 'POST', content: '{}');
        $request->headers->set('x-webhook-secret', 'wrong-secret');

        $result = $this->verifier()->verify($proxy, $request, '{}');

        $this->assertSame(VerificationResult::Failed, $result);
    }

    public function test_a_correctly_verifying_standard_webhooks_request_returns_verified(): void
    {
        $proxy = $this->makeProxy(['verification_scheme' => VerificationScheme::StandardWebhooks]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'whsec_c2VjcmV0LWtleQ==');

        $id = 'msg_123';
        $timestamp = time();
        $body = '{"hello":"world"}';
        $signature = 'v1,'.StandardWebhooks::sign($id, $timestamp, $body, 'whsec_c2VjcmV0LWtleQ==');

        $request = Request::create('/ingest/token', 'POST', content: $body);
        $request->headers->set('webhook-id', $id);
        $request->headers->set('webhook-timestamp', (string) $timestamp);
        $request->headers->set('webhook-signature', $signature);

        $result = $this->verifier()->verify($proxy, $request, $body);

        $this->assertSame(VerificationResult::Verified, $result);
    }

    public function test_an_incorrect_standard_webhooks_request_returns_failed(): void
    {
        $proxy = $this->makeProxy(['verification_scheme' => VerificationScheme::StandardWebhooks]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'whsec_c2VjcmV0LWtleQ==');

        $body = '{}';
        $request = Request::create('/ingest/token', 'POST', content: $body);
        $request->headers->set('webhook-id', 'msg_123');
        $request->headers->set('webhook-timestamp', (string) time());
        $request->headers->set('webhook-signature', 'v1,d3Jvbmc=');

        $result = $this->verifier()->verify($proxy, $request, $body);

        $this->assertSame(VerificationResult::Failed, $result);
    }

    public function test_secret_unavailable_exception_propagates_rather_than_being_treated_as_failed(): void
    {
        $proxy = $this->makeProxy([
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'x-webhook-secret',
        ]);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Verification, 'correct-secret');

        DB::table('proxy_secrets')
            ->where('proxy_id', $proxy->id)
            ->update(['value' => 'not-valid-ciphertext']);

        $request = Request::create('/ingest/token', 'POST', content: '{}');
        $request->headers->set('x-webhook-secret', 'correct-secret');

        $this->expectException(SecretUnavailableException::class);

        $this->verifier()->verify($proxy, $request, '{}');
    }
}
