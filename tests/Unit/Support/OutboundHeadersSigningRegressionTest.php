<?php

namespace Tests\Unit\Support;

use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use App\Support\OutboundHeaders;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T35 — the AC63 byte-identical regression, dedicated (plan-10 § Test
 * strategy, "the regression that matters most"). The signing-surface
 * counterpart to T26's AC37 test, kept as its own named, independently
 * identifiable file per the task's own instruction — a partial landing here
 * is a shipped defect a broader test (T40) could quietly pass around.
 *
 * Composes with T26's AC37 fixture rather than duplicating it from scratch:
 * the same header set, the same byte-identical assertion against
 * `DeliveryUnit::forwardHeaders()`, now additionally proven unaffected by an
 * empty (never-enabled, or disabled) signing secret set.
 */
class OutboundHeadersSigningRegressionTest extends TestCase
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
            dispatchUuid: 'dispatch-a',
        );
    }

    #[Test]
    public function ac63_a_destination_of_a_proxy_with_no_signing_secret_configured_is_byte_identical_to_the_ac37_baseline_plus_the_hop_header(): void
    {
        $headers = [
            'Content-Type' => ['application/json'],
            'X-Custom' => ['value'],
            'Authorization' => ['Bearer sender-token'],
        ];
        $unit = $this->unit($headers);

        $result = OutboundHeaders::build($unit, null, null, []);

        // No WebhookProxy-Id/Timestamp/Signature added (signing not enabled).
        $this->assertArrayNotHasKey('WebhookProxy-Id', $result);
        $this->assertArrayNotHasKey('WebhookProxy-Timestamp', $result);
        $this->assertArrayNotHasKey('WebhookProxy-Signature', $result);

        // Byte-identical to the T26 AC37 baseline plus the always-stamped hop
        // header (docs/briefs/delivery-loop-guard.md) — the same assertion,
        // same fixture, narrowed the same way T26's own test was.
        $this->assertSame([...$unit->forwardHeaders(), 'WebhookProxy-Hops' => '1'], $result);
    }

    #[Test]
    public function ac63_a_destination_of_a_proxy_that_had_signing_enabled_and_then_disabled_is_also_byte_identical(): void
    {
        $headers = [
            'Content-Type' => ['application/json'],
        ];
        $unit = $this->unit($headers);

        // ADR-021 Decision 5's delete-on-disable, exercised at the header-building
        // layer: once disabled, SecretStore::liveFor() returns an empty set (T14) —
        // the same input a proxy that never enabled signing produces, so a
        // previously-signing proxy dispatches byte-identically once disabled.
        $result = OutboundHeaders::build($unit, null, null, []);

        $this->assertArrayNotHasKey('WebhookProxy-Id', $result);
        $this->assertArrayNotHasKey('WebhookProxy-Timestamp', $result);
        $this->assertArrayNotHasKey('WebhookProxy-Signature', $result);
        $this->assertSame([...$unit->forwardHeaders(), 'WebhookProxy-Hops' => '1'], $result);
    }
}
