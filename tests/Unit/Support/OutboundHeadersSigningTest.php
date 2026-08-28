<?php

namespace Tests\Unit\Support;

use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use App\Support\OutboundHeaders;
use App\Support\StandardWebhooks;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T34 — `OutboundHeaders` extended with the signing headers (AC54, AC55,
 * AC58, AC59, AC60, AC64; ADR-023 Decisions 2-3). One test per Acceptance
 * Criteria bullet. `OutboundHeaders` is a pure function of its explicit
 * arguments — the live signing secret set (T36's concern) is passed in
 * directly here, never fetched through `SecretStore`.
 */
class OutboundHeadersSigningTest extends TestCase
{
    private function unit(
        string $dispatchUuid = 'dispatch-a',
        ?Destination $destination = null,
        string $payload = '{"a":1}',
        array $headers = [],
    ): DeliveryUnit {
        return new DeliveryUnit(
            ingestId: 'evt_test',
            teamId: 1,
            proxyId: 1,
            destination: $destination ?? Destination::factory()->create(),
            method: HttpMethod::Post->value,
            headers: $headers,
            payload: $payload,
            deliveryId: 1,
            attemptNumber: 1,
            dispatchUuid: $dispatchUuid,
        );
    }

    #[Test]
    public function webhook_id_is_identical_across_an_attempt_and_its_retry_different_on_a_replay_and_different_per_destination(): void
    {
        $destination = Destination::factory()->create();
        $otherDestination = Destination::factory()->create();

        $idFor = fn (DeliveryUnit $unit): string => OutboundHeaders::build($unit, null, null, ['whsec_secret'])['WebhookProxy-Id'];

        $attempt1 = $this->unit(destination: $destination, dispatchUuid: 'dispatch-a');
        $retry = $this->unit(destination: $destination, dispatchUuid: 'dispatch-a');
        $replay = $this->unit(destination: $destination, dispatchUuid: 'dispatch-b');
        $sameDispatchOtherDestination = $this->unit(destination: $otherDestination, dispatchUuid: 'dispatch-a');

        $this->assertSame("msg_dispatch-a_{$destination->id}", $idFor($attempt1));
        $this->assertSame($idFor($attempt1), $idFor($retry));
        $this->assertNotSame($idFor($attempt1), $idFor($replay));
        $this->assertNotSame($idFor($attempt1), $idFor($sameDispatchOtherDestination));
    }

    #[Test]
    public function webhook_timestamp_reflects_each_attempts_own_time_not_the_original(): void
    {
        $unit = $this->unit();

        Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
        $original = OutboundHeaders::build($unit, null, null, ['whsec_secret'])['WebhookProxy-Timestamp'];

        Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_600));
        $retry = OutboundHeaders::build($unit, null, null, ['whsec_secret'])['WebhookProxy-Timestamp'];

        Carbon::setTestNow();

        $this->assertSame('1700000000', $original);
        $this->assertSame('1700000600', $retry);
    }

    #[Test]
    public function during_an_overlap_the_header_carries_one_entry_per_live_secret_each_verifying_independently_after_expiry_exactly_one(): void
    {
        $unit = $this->unit(payload: '{"exact":"bytes"}');

        $overlap = OutboundHeaders::build($unit, null, null, ['whsec_current', 'whsec_superseded']);
        $entries = explode(' ', $overlap['WebhookProxy-Signature']);

        $this->assertCount(2, $entries);
        $this->assertTrue(StandardWebhooks::verify(
            $overlap['WebhookProxy-Id'],
            (int) $overlap['WebhookProxy-Timestamp'],
            $unit->payload,
            $overlap['WebhookProxy-Signature'],
            ['whsec_current'],
        ));
        $this->assertTrue(StandardWebhooks::verify(
            $overlap['WebhookProxy-Id'],
            (int) $overlap['WebhookProxy-Timestamp'],
            $unit->payload,
            $overlap['WebhookProxy-Signature'],
            ['whsec_superseded'],
        ));

        $afterExpiry = OutboundHeaders::build($unit, null, null, ['whsec_current']);

        $this->assertCount(1, explode(' ', $afterExpiry['WebhookProxy-Signature']));
    }

    #[Test]
    public function the_signature_verifies_against_the_specification_over_the_exact_dispatched_bytes_and_the_body_is_untouched(): void
    {
        $payload = '{"exact":"bytes"}';
        $unit = $this->unit(payload: $payload);

        $signed = OutboundHeaders::build($unit, null, null, ['whsec_secret']);
        $unsigned = OutboundHeaders::build($unit, null, null, []);

        $this->assertTrue(StandardWebhooks::verify(
            $signed['WebhookProxy-Id'],
            (int) $signed['WebhookProxy-Timestamp'],
            $unit->payload,
            $signed['WebhookProxy-Signature'],
            ['whsec_secret'],
        ));
        // Signing changes nothing but the headers (AC59) — the unit's own
        // payload, what actually gets dispatched, is never read from the
        // returned header array, and is identical before/after either call.
        $this->assertSame($payload, $unit->payload);
        $this->assertArrayNotHasKey('body', $signed);
        $this->assertArrayNotHasKey('body', $unsigned);
    }

    #[Test]
    public function an_inbound_webhook_signature_header_never_reaches_a_destination_as_the_proxys_own(): void
    {
        $unit = $this->unit(headers: [
            'WebhookProxy-Id' => ['msg_forged'],
            'WebhookProxy-Timestamp' => ['1'],
            'WebhookProxy-Signature' => ['v1,forged'],
        ]);

        $result = OutboundHeaders::build($unit, null, null, ['whsec_secret']);

        $this->assertNotSame('msg_forged', $result['WebhookProxy-Id']);
        $this->assertNotSame('1', $result['WebhookProxy-Timestamp']);
        $this->assertNotSame('v1,forged', $result['WebhookProxy-Signature']);
        $this->assertStringStartsWith('v1,', $result['WebhookProxy-Signature']);
    }
}
