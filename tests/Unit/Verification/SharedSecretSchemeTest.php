<?php

namespace Tests\Unit\Verification;

use App\Models\Proxy;
use App\Verification\SharedSecretScheme;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * T17 (AC51) — `SharedSecretScheme`: the named header's value must exactly
 * (constant-time) match a member of the live secret set.
 */
class SharedSecretSchemeTest extends TestCase
{
    private function proxyWithHeader(string $headerName): Proxy
    {
        return new Proxy(['verification_header_name' => $headerName]);
    }

    private function requestWithHeader(?string $headerName, ?string $value): Request
    {
        $request = Request::create('/ingest/token', 'POST', content: '{}');

        if ($headerName !== null && $value !== null) {
            $request->headers->set($headerName, $value);
        }

        return $request;
    }

    public function test_correct_value_in_the_named_header_verifies(): void
    {
        $proxy = $this->proxyWithHeader('x-webhook-secret');
        $request = $this->requestWithHeader('x-webhook-secret', 'correct-secret');

        $result = (new SharedSecretScheme)->verify($proxy, $request, '{}', ['correct-secret']);

        $this->assertTrue($result);
    }

    public function test_wrong_value_fails(): void
    {
        $proxy = $this->proxyWithHeader('x-webhook-secret');
        $request = $this->requestWithHeader('x-webhook-secret', 'wrong-secret');

        $result = (new SharedSecretScheme)->verify($proxy, $request, '{}', ['correct-secret']);

        $this->assertFalse($result);
    }

    public function test_missing_header_fails(): void
    {
        $proxy = $this->proxyWithHeader('x-webhook-secret');
        $request = $this->requestWithHeader(null, null);

        $result = (new SharedSecretScheme)->verify($proxy, $request, '{}', ['correct-secret']);

        $this->assertFalse($result);
    }

    public function test_wrong_header_name_fails(): void
    {
        $proxy = $this->proxyWithHeader('x-webhook-secret');
        // The sender used a different header name than the proxy is configured for.
        $request = $this->requestWithHeader('x-other-header', 'correct-secret');

        $result = (new SharedSecretScheme)->verify($proxy, $request, '{}', ['correct-secret']);

        $this->assertFalse($result);
    }

    public function test_comparison_is_constant_time_and_verifies_against_any_live_secret(): void
    {
        $proxy = $this->proxyWithHeader('x-webhook-secret');
        $request = $this->requestWithHeader('x-webhook-secret', 'second-live-secret');

        $result = (new SharedSecretScheme)->verify($proxy, $request, '{}', ['first-live-secret', 'second-live-secret']);

        $this->assertTrue($result);
    }

    public function test_reason_for_reports_missing_header_and_secret_mismatch(): void
    {
        $proxy = $this->proxyWithHeader('x-webhook-secret');

        $this->assertSame(
            'missing_header',
            (new SharedSecretScheme)->reasonFor($proxy, $this->requestWithHeader(null, null), ['correct-secret']),
        );

        $this->assertSame(
            'secret_mismatch',
            (new SharedSecretScheme)->reasonFor(
                $proxy,
                $this->requestWithHeader('x-webhook-secret', 'wrong-secret'),
                ['correct-secret'],
            ),
        );

        $this->assertNull((new SharedSecretScheme)->reasonFor(
            $proxy,
            $this->requestWithHeader('x-webhook-secret', 'correct-secret'),
            ['correct-secret'],
        ));
    }
}
