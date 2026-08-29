<?php

namespace Tests\Unit\Support;

use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use App\Support\OutboundHeaders;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T26 — `App\Support\OutboundHeaders` (credential composition; AC30, AC38).
 * AC37's byte-identical regression is named and run first, per the task's
 * own instruction.
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
    public function ac37_a_destination_with_no_credential_and_no_signing_secret_is_byte_identical_to_the_pre_10_baseline_plus_the_hop_header(): void
    {
        $headers = [
            'Content-Type' => ['application/json'],
            'X-Custom' => ['value'],
            'Authorization' => ['Bearer sender-token'],
        ];
        $unit = $this->unit($headers);

        // Delivery-loop guard (docs/briefs/delivery-loop-guard.md): the ONE
        // addition to the AC37 baseline is the always-stamped hop header —
        // nothing else changes with no credential and no signing secret.
        $this->assertSame(
            [...$unit->forwardHeaders(), 'WebhookProxy-Hops' => '1'],
            OutboundHeaders::build($unit, null, null),
        );
    }

    #[Test]
    public function a_forwarded_webhook_signature_a_sender_happened_to_send_is_forwarded_unchanged(): void
    {
        $unit = $this->unit([
            'webhook-signature' => ['v1,abc'],
        ]);

        $result = OutboundHeaders::build($unit, null, null);

        $this->assertArrayHasKey('webhook-signature', $result);
    }

    #[Test]
    public function ac38_a_forwarded_header_colliding_with_the_credential_header_is_displaced_by_the_credential(): void
    {
        $unit = $this->unit([
            'Content-Type' => ['application/json'],
            'authorization' => ['sender-value'],
        ]);

        $result = OutboundHeaders::build($unit, 'Authorization', 'Bearer abc123');

        $this->assertSame('Bearer abc123', $result['Authorization']);
        $this->assertArrayNotHasKey('authorization', $result);
        // Content-Type, Authorization, and the always-stamped hop header.
        $this->assertCount(3, $result);
    }

    #[Test]
    public function the_credential_value_is_sent_verbatim_with_no_scheme_prefix_added(): void
    {
        $unit = $this->unit(['Content-Type' => ['application/json']]);

        $result = OutboundHeaders::build($unit, 'X-Api-Key', 'Bearer abc123');

        $this->assertSame('Bearer abc123', $result['X-Api-Key']);
    }

    // --- Delivery-loop guard hop counter (docs/briefs/delivery-loop-guard.md) ---

    #[Test]
    public function an_absent_inbound_hop_header_is_stamped_outbound_as_1(): void
    {
        $unit = $this->unit(['Content-Type' => ['application/json']]);

        $result = OutboundHeaders::build($unit, null, null);

        $this->assertSame('1', $result['WebhookProxy-Hops']);
    }

    #[Test]
    public function an_inbound_hop_count_is_stamped_outbound_as_one_more(): void
    {
        $unit = $this->unit(['WebhookProxy-Hops' => ['2']]);

        $result = OutboundHeaders::build($unit, null, null);

        $this->assertSame('3', $result['WebhookProxy-Hops']);
    }

    #[Test]
    public function a_non_numeric_inbound_hop_value_is_treated_as_0_and_stamped_outbound_as_1(): void
    {
        $unit = $this->unit(['WebhookProxy-Hops' => ['not-a-number']]);

        $result = OutboundHeaders::build($unit, null, null);

        $this->assertSame('1', $result['WebhookProxy-Hops']);
    }

    #[Test]
    public function a_forwarded_inbound_hop_header_is_displaced_not_duplicated(): void
    {
        $unit = $this->unit([
            'Content-Type' => ['application/json'],
            'webhookproxy-hops' => ['2'],
        ]);

        $result = OutboundHeaders::build($unit, null, null);

        $names = array_filter(array_keys($result), fn (string $name): bool => strtolower($name) === 'webhookproxy-hops');

        $this->assertCount(1, $names, 'exactly one hop header, case-insensitively');
        $this->assertSame('3', $result['WebhookProxy-Hops']);
    }
}
