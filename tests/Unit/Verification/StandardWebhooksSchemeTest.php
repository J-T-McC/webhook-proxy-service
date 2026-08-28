<?php

namespace Tests\Unit\Verification;

use App\Models\Proxy;
use App\Support\StandardWebhooks;
use App\Verification\StandardWebhooksScheme;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * T17 (AC52, AC53) — `StandardWebhooksScheme`: delegates to
 * `StandardWebhooks::verify()` over the three specified headers and the
 * live secret set.
 */
class StandardWebhooksSchemeTest extends TestCase
{
    private function requestWith(?string $id, ?string $timestamp, ?string $signature, string $body = '{}'): Request
    {
        $request = Request::create('/ingest/token', 'POST', content: $body);

        if ($id !== null) {
            $request->headers->set('webhook-id', $id);
        }
        if ($timestamp !== null) {
            $request->headers->set('webhook-timestamp', $timestamp);
        }
        if ($signature !== null) {
            $request->headers->set('webhook-signature', $signature);
        }

        return $request;
    }

    public function test_a_specification_computed_request_verifies(): void
    {
        $secret = 'whsec_c2VjcmV0LWtleS1tYXRlcmlhbA==';
        $id = 'msg_123';
        $timestamp = time();
        $body = '{"hello":"world"}';
        $signature = 'v1,'.StandardWebhooks::sign($id, $timestamp, $body, $secret);

        $request = $this->requestWith($id, (string) $timestamp, $signature, $body);

        $result = (new StandardWebhooksScheme)->verify(new Proxy, $request, $body, [$secret]);

        $this->assertTrue($result);
    }

    public function test_a_missing_header_fails(): void
    {
        $body = '{}';
        $request = $this->requestWith(null, (string) time(), 'v1,whatever', $body);

        $result = (new StandardWebhooksScheme)->verify(new Proxy, $request, $body, ['whsec_secret']);

        $this->assertFalse($result);
        $this->assertSame(
            'missing_header',
            (new StandardWebhooksScheme)->reasonFor($request, $body, ['whsec_secret']),
        );
    }

    public function test_a_malformed_timestamp_header_fails(): void
    {
        $body = '{}';
        $request = $this->requestWith('msg_123', 'not-a-number', 'v1,whatever', $body);

        $result = (new StandardWebhooksScheme)->verify(new Proxy, $request, $body, ['whsec_secret']);

        $this->assertFalse($result);
        $this->assertSame(
            'malformed_header',
            (new StandardWebhooksScheme)->reasonFor($request, $body, ['whsec_secret']),
        );
    }

    public function test_a_timestamp_outside_tolerance_fails(): void
    {
        $secret = 'whsec_c2VjcmV0LWtleS1tYXRlcmlhbA==';
        $id = 'msg_123';
        $timestamp = time() - (StandardWebhooks::TOLERANCE_SECONDS + 1);
        $body = '{}';
        $signature = 'v1,'.StandardWebhooks::sign($id, $timestamp, $body, $secret);

        $request = $this->requestWith($id, (string) $timestamp, $signature, $body);

        $result = (new StandardWebhooksScheme)->verify(new Proxy, $request, $body, [$secret]);

        $this->assertFalse($result);
        $this->assertSame(
            'timestamp_out_of_tolerance',
            (new StandardWebhooksScheme)->reasonFor($request, $body, [$secret]),
        );
    }

    public function test_a_multi_entry_signature_list_verifies_when_only_one_entry_matches(): void
    {
        $secret = 'whsec_c2VjcmV0LWtleS1tYXRlcmlhbA==';
        $id = 'msg_123';
        $timestamp = time();
        $body = '{}';
        $correctSignature = StandardWebhooks::sign($id, $timestamp, $body, $secret);
        $signatureHeader = "v1,bm90LXRoZS1yaWdodC1vbmU= v1,{$correctSignature}";

        $request = $this->requestWith($id, (string) $timestamp, $signatureHeader, $body);

        $result = (new StandardWebhooksScheme)->verify(new Proxy, $request, $body, [$secret]);

        $this->assertTrue($result);
    }

    public function test_wrong_signature_fails_with_signature_mismatch_reason(): void
    {
        $secret = 'whsec_c2VjcmV0LWtleS1tYXRlcmlhbA==';
        $id = 'msg_123';
        $timestamp = time();
        $body = '{}';

        $request = $this->requestWith($id, (string) $timestamp, 'v1,d3Jvbmctc2lnbmF0dXJl', $body);

        $result = (new StandardWebhooksScheme)->verify(new Proxy, $request, $body, [$secret]);

        $this->assertFalse($result);
        $this->assertSame(
            'signature_mismatch',
            (new StandardWebhooksScheme)->reasonFor($request, $body, [$secret]),
        );
    }
}
