<?php

namespace Tests\Unit\Enums;

use App\Enums\AttemptStatus;
use App\Enums\HttpMethod;
use App\Enums\ProxyMode;
use PHPUnit\Framework\TestCase;

class DomainEnumsTest extends TestCase
{
    public function test_proxy_mode_has_exactly_simple_and_enhanced(): void
    {
        $this->assertSame(
            ['simple', 'enhanced'],
            array_map(fn (ProxyMode $c) => $c->value, ProxyMode::cases()),
        );
    }

    public function test_http_method_has_exactly_post_and_put(): void
    {
        $this->assertSame(
            ['POST', 'PUT'],
            array_map(fn (HttpMethod $c) => $c->value, HttpMethod::cases()),
        );
    }

    public function test_attempt_status_has_exactly_dispatched_succeeded_failed(): void
    {
        $this->assertSame(
            ['dispatched', 'succeeded', 'failed'],
            array_map(fn (AttemptStatus $c) => $c->value, AttemptStatus::cases()),
        );
    }
}
