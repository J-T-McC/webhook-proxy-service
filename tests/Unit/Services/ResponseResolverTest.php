<?php

namespace Tests\Unit\Services;

use App\Models\Proxy;
use App\Services\ResponseResolver;
use Tests\TestCase;

class ResponseResolverTest extends TestCase
{
    public function test_both_configured_returns_exact_status_body_and_content_type(): void
    {
        $proxy = Proxy::factory()->createQuietly([
            'response_status' => 201,
            'response_body' => '{"ok":true}',
        ]);

        $response = (new ResponseResolver)->resolve($proxy);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"ok":true}', $response->getContent());
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
    }

    public function test_neither_configured_returns_202_empty_body_no_forced_content_type(): void
    {
        $proxy = Proxy::factory()->createQuietly([
            'response_status' => null,
            'response_body' => null,
        ]);

        $response = (new ResponseResolver)->resolve($proxy);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        // No body → no forced text/plain content-type.
        $this->assertStringNotContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_only_status_configured_uses_status_with_default_empty_body(): void
    {
        $proxy = Proxy::factory()->createQuietly([
            'response_status' => 204,
            'response_body' => null,
        ]);

        $response = (new ResponseResolver)->resolve($proxy);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertStringNotContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_only_body_configured_uses_body_with_default_202_status(): void
    {
        $proxy = Proxy::factory()->createQuietly([
            'response_status' => null,
            'response_body' => 'thanks',
        ]);

        $response = (new ResponseResolver)->resolve($proxy);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('thanks', $response->getContent());
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
    }
}
