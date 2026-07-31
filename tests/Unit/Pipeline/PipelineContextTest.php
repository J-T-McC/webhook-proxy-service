<?php

namespace Tests\Unit\Pipeline;

use App\Models\Proxy;
use App\Pipeline\PipelineContext;
use ReflectionProperty;
use Tests\TestCase;

class PipelineContextTest extends TestCase
{
    public function test_payload_is_initialised_to_the_raw_body(): void
    {
        $proxy = Proxy::factory()->create();

        $ctx = new PipelineContext(
            ingestId: 'abc',
            proxy: $proxy,
            method: 'POST',
            headers: ['content-type' => ['application/json']],
            rawBody: '{"a":1}',
        );

        $this->assertSame('{"a":1}', $ctx->payload);
        $this->assertSame($ctx->rawBody, $ctx->payload);
    }

    public function test_raw_input_fields_are_readonly(): void
    {
        foreach (['ingestId', 'proxy', 'method', 'headers', 'rawBody'] as $field) {
            $this->assertTrue(
                (new ReflectionProperty(PipelineContext::class, $field))->isReadOnly(),
                "{$field} must be readonly",
            );
        }

        // payload is intentionally mutable (accumulated state).
        $this->assertFalse(
            (new ReflectionProperty(PipelineContext::class, 'payload'))->isReadOnly(),
        );
    }

    public function test_payload_can_be_overridden_and_mutated(): void
    {
        $proxy = Proxy::factory()->create();

        $ctx = new PipelineContext(
            ingestId: 'abc',
            proxy: $proxy,
            method: 'PUT',
            headers: [],
            rawBody: 'raw',
            payload: 'override',
        );

        $this->assertSame('override', $ctx->payload);

        $ctx->payload = 'mutated';
        $this->assertSame('mutated', $ctx->payload);
    }
}
