<?php

namespace Tests\Unit\Support;

use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use App\Support\OutboundHeaders;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T26 — `App\Support\OutboundHeaders` (credential + verification-strip only;
 * AC27, AC30, AC38). AC37's byte-identical regression is named and run
 * first, per the task's own instruction.
 */
class OutboundHeadersTest extends TestCase
{
    private function unit(array $headers): DeliveryUnit
    {
        return new DeliveryUnit(
            ingestId: 'evt_test',
            teamId: 1,
            proxyId: 1,
            destination: Destination::factory()->create(),
            method: HttpMethod::Post->value,
            headers: $headers,
            payload: '{"a":1}',
            deliveryId: 1,
            attemptNumber: 1,
        );
    }

    #[Test]
    public function ac37_a_destination_with_no_credential_on_a_proxy_with_no_verification_is_byte_identical_to_the_pre_10_baseline(): void
    {
        $headers = [
            'Content-Type' => ['application/json'],
            'X-Custom' => ['value'],
            'Authorization' => ['Bearer sender-token'],
        ];
        $unit = $this->unit($headers);

        $this->assertSame(
            $unit->forwardHeaders(),
            OutboundHeaders::build($unit, [], null, null),
        );
    }

    #[Test]
    public function shared_secret_scheme_strips_the_member_named_header_outbound(): void
    {
        $unit = $this->unit([
            'Content-Type' => ['application/json'],
            'X-Signature' => ['secret-value'],
        ]);

        $result = OutboundHeaders::build($unit, ['X-Signature'], null, null);

        $this->assertArrayNotHasKey('X-Signature', $result);
        $this->assertArrayHasKey('Content-Type', $result);
    }

    #[Test]
    public function standard_webhooks_scheme_strips_all_three_webhook_headers_outbound(): void
    {
        $unit = $this->unit([
            'Content-Type' => ['application/json'],
            'webhook-id' => ['msg_1'],
            'webhook-timestamp' => ['1700000000'],
            'webhook-signature' => ['v1,abc'],
        ]);

        $result = OutboundHeaders::build(
            $unit,
            ['webhook-id', 'webhook-timestamp', 'webhook-signature'],
            null,
            null,
        );

        $this->assertArrayNotHasKey('webhook-id', $result);
        $this->assertArrayNotHasKey('webhook-timestamp', $result);
        $this->assertArrayNotHasKey('webhook-signature', $result);
        $this->assertArrayHasKey('Content-Type', $result);
    }

    #[Test]
    public function ac43_a_proxy_with_verification_off_still_forwards_a_webhook_signature_a_sender_happened_to_send(): void
    {
        $unit = $this->unit([
            'webhook-signature' => ['v1,abc'],
        ]);

        // No verification header names passed — nothing to strip it for.
        $result = OutboundHeaders::build($unit, [], null, null);

        $this->assertArrayHasKey('webhook-signature', $result);
    }

    #[Test]
    public function ac38_a_forwarded_header_colliding_with_the_credential_header_is_displaced_by_the_credential(): void
    {
        $unit = $this->unit([
            'Content-Type' => ['application/json'],
            'authorization' => ['sender-value'],
        ]);

        $result = OutboundHeaders::build($unit, [], 'Authorization', 'Bearer abc123');

        $this->assertSame('Bearer abc123', $result['Authorization']);
        $this->assertArrayNotHasKey('authorization', $result);
        $this->assertCount(2, $result);
    }

    #[Test]
    public function the_credential_value_is_sent_verbatim_with_no_scheme_prefix_added(): void
    {
        $unit = $this->unit(['Content-Type' => ['application/json']]);

        $result = OutboundHeaders::build($unit, [], 'X-Api-Key', 'Bearer abc123');

        $this->assertSame('Bearer abc123', $result['X-Api-Key']);
    }
}
