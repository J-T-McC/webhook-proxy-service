<?php

namespace Tests\Unit\Services;

use App\Enums\VerificationScheme;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Services\DeliveryUnitResolver;
use Tests\TestCase;

/**
 * The property that makes ADR-020 Decision 7's reference resolve totally:
 * attempt 1 (via `DeliverToDestination::asJob()`) and a retry (via
 * `RetryDelivery`) must resolve IDENTICAL bytes for the same delivery,
 * regardless of the attempt number — proven directly against the shared
 * resolver both callers use, covering both sides of ADR-013's divergence
 * gate.
 */
class DeliveryUnitResolverTest extends TestCase
{
    public function test_attempt_1_and_a_retry_resolve_the_diverged_dispatched_output_identically(): void
    {
        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
            'body' => '{"raw":true}',
        ]);
        DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $event->team_id,
            'proxy_id' => $event->proxy_id,
            'body' => '{"mapped":true}',
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);

        $resolver = app(DeliveryUnitResolver::class);

        $attempt1 = $resolver->resolve($delivery, 1);
        $retry = $resolver->resolve($delivery, 2);

        $this->assertNotNull($attempt1);
        $this->assertNotNull($retry);
        $this->assertSame('{"mapped":true}', $attempt1->payload);
        $this->assertSame($attempt1->payload, $retry->payload);
        $this->assertSame(1, $attempt1->attemptNumber);
        $this->assertSame(2, $retry->attemptNumber);
    }

    public function test_attempt_1_and_a_retry_resolve_the_raw_capture_identically_when_the_output_never_diverged(): void
    {
        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
            'body' => '{"raw":true}',
        ]);
        // No DispatchedPayload row at all — the simple-mode / identical-output
        // case (ADR-013 Decision 2's no-row branch).
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);

        $resolver = app(DeliveryUnitResolver::class);

        $attempt1 = $resolver->resolve($delivery, 1);
        $retry = $resolver->resolve($delivery, 2);

        $this->assertNotNull($attempt1);
        $this->assertNotNull($retry);
        $this->assertSame('{"raw":true}', $attempt1->payload);
        $this->assertSame($attempt1->payload, $retry->payload);
    }

    public function test_a_cleaned_parent_resolves_to_null_regardless_of_attempt_number(): void
    {
        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);

        $resolver = app(DeliveryUnitResolver::class);

        $this->assertNull($resolver->resolve($delivery, 1));
        $this->assertNull($resolver->resolve($delivery, 2));
    }

    public function test_resolves_the_destination_withtrashed_and_the_captured_event_headers(): void
    {
        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
            'headers' => ['x-signature' => ['abc123']],
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);
        $destination->delete();

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 1);

        $this->assertNotNull($unit);
        $this->assertSame($destination->id, $unit->destination->id);
        $this->assertSame(['x-signature' => ['abc123']], $unit->headers);
    }

    /**
     * T27 (R3) — a retry against a soft-deleted proxy resolves successfully
     * and still carries that proxy's verification header name(s) for
     * `OutboundHeaders`' strip step, the regression this task exists to
     * prevent (`ProcessIngestedWebhook` and `DeliverToDestination::settleDelivery()`
     * are the existing `withTrashed()` precedents this follows).
     */
    public function test_a_retry_against_a_soft_deleted_proxy_resolves_successfully_and_carries_its_verification_header_names(): void
    {
        $proxy = Proxy::factory()->create([
            'verification_scheme' => VerificationScheme::SharedSecret,
            'verification_header_name' => 'X-Signature',
        ]);
        $destination = Destination::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);
        $proxy->delete();

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 2);

        $this->assertNotNull($unit);
        $this->assertSame(['X-Signature'], $unit->verificationHeaderNames);
    }

    public function test_standard_webhooks_scheme_carries_all_three_fixed_header_names(): void
    {
        $proxy = Proxy::factory()->create([
            'verification_scheme' => VerificationScheme::StandardWebhooks,
        ]);
        $destination = Destination::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 1);

        $this->assertNotNull($unit);
        $this->assertSame(['webhook-id', 'webhook-timestamp', 'webhook-signature'], $unit->verificationHeaderNames);
    }

    public function test_no_verification_configured_carries_no_header_names(): void
    {
        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 1);

        $this->assertNotNull($unit);
        $this->assertSame([], $unit->verificationHeaderNames);
    }
}
