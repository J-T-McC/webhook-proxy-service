<?php

namespace Tests\Unit\Services;

use App\Models\Proxy;
use App\Services\ResponseResolver;
use Tests\TestCase;

class ResponseResolverTest extends TestCase
{
    public function test_it_returns_202_for_any_proxy(): void
    {
        $proxy = Proxy::factory()->create();

        $response = (new ResponseResolver)->resolve($proxy);

        $this->assertSame(202, $response->getStatusCode());
    }
}
