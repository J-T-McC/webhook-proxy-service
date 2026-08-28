<?php

namespace Tests\Unit\Pipeline;

use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use ReflectionProperty;
use Tests\TestCase;

class DeliveryUnitTest extends TestCase
{
    public function test_forward_headers_keeps_benign_and_strips_sensitive_case_insensitively(): void
    {
        $destination = Destination::factory()->create();

        // Every stripped header rendered in a mixed-case variant to prove
        // case-insensitive matching, alongside forwardable headers. ADR-026
        // Decision A: `cookie`, `authorization` and the five provider
        // signature names are no longer stripped — they forward like any
        // other benign header.
        $headers = [
            'Content-Type' => ['application/json'],
            'X-Custom-Event' => ['invoice.paid'],
            'HoSt' => ['sender.example.com'],
            'ConNection' => ['keep-alive'],
            'Keep-ALIVE' => ['timeout=5'],
            'Proxy-Authenticate' => ['Basic'],
            'Proxy-Authorization' => ['Basic abc'],
            'te' => ['trailers'],
            'Trailer' => ['Expires'],
            'Transfer-Encoding' => ['chunked'],
            'Upgrade' => ['h2c'],
            'Content-LENGTH' => ['123'],
            'CookIE' => ['session=1'],
            'AUTHORIZATION' => ['Bearer inbound'],
            'Stripe-Signature' => ['t=1,v1=abc'],
            'X-Hub-Signature' => ['sha1=abc'],
            'X-Hub-Signature-256' => ['sha256=abc'],
            'X-Signature' => ['abc'],
            'X-Webhook-Signature' => ['abc'],
        ];

        $unit = new DeliveryUnit(
            ingestId: 'id',
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: 'POST',
            headers: $headers,
            payload: '{}',
            deliveryId: 1,
            attemptNumber: 1,
        );

        $forwarded = array_map('strtolower', array_keys($unit->forwardHeaders()));

        // Forwarded: Content-Type + custom header, plus the seven names
        // ADR-026 Decision A moves off the strip list.
        $forwardable = [
            'content-type', 'x-custom-event', 'cookie', 'authorization',
            'stripe-signature', 'x-hub-signature', 'x-hub-signature-256',
            'x-signature', 'x-webhook-signature',
        ];

        foreach ($forwardable as $name) {
            $this->assertContains($name, $forwarded, "{$name} must be forwarded");
        }

        // Stripped: the ten-entry transport-scoped deny-list only,
        // regardless of casing.
        foreach ([
            'host', 'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
            'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length',
        ] as $stripped) {
            $this->assertNotContains($stripped, $forwarded, "{$stripped} must be stripped");
        }

        // Exactly the forwardable headers remain — nothing more, nothing
        // fewer than the count of $forwardable.
        $this->assertCount(count($forwardable), $forwarded);
    }

    public function test_no_header_is_added(): void
    {
        $destination = Destination::factory()->create();

        $unit = new DeliveryUnit(
            ingestId: 'id',
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: 'PUT',
            headers: ['Content-Type' => ['text/plain']],
            payload: 'x',
            deliveryId: 2,
            attemptNumber: 1,
        );

        $this->assertSame(['Content-Type'], array_keys($unit->forwardHeaders()));
    }

    public function test_delivery_id_is_stored_and_readonly(): void
    {
        $destination = Destination::factory()->create();

        $unit = new DeliveryUnit(
            ingestId: 'id',
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: 'POST',
            headers: [],
            payload: '{}',
            deliveryId: 42,
            attemptNumber: 1,
        );

        $this->assertSame(42, $unit->deliveryId);
        $this->assertTrue(
            (new ReflectionProperty(DeliveryUnit::class, 'deliveryId'))->isReadOnly(),
        );
    }
}
