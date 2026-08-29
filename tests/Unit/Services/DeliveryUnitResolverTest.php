<?php

namespace Tests\Unit\Services;

use App\Enums\SecretPurpose;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Services\DeliveryUnitResolver;
use App\Services\SecretStore;
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
     * T27 (R3), retargeted by ADR-026 Decision 3 — a retry against a
     * soft-deleted proxy resolves successfully and still carries that
     * proxy's live signing set, the one reason this `withTrashed()` load
     * remains load-bearing after inbound verification's removal
     * (`ProcessIngestedWebhook` and `DeliverToDestination::settleDelivery()`
     * are the existing `withTrashed()` precedents this follows).
     */
    public function test_a_retry_against_a_soft_deleted_proxy_resolves_successfully_and_carries_its_signing_secrets(): void
    {
        $proxy = Proxy::factory()->create();
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
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_current');
        $proxy->delete();

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 2);

        $this->assertNotNull($unit);
        $this->assertSame(['whsec_current'], $unit->signingSecrets);
    }

    // --- T36: the proxy's live signing set --------------------------------

    public function test_a_proxy_with_signing_off_carries_no_signing_secrets(): void
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
        $this->assertSame([], $unit->signingSecrets);
        $this->assertNull($unit->signingSecretsUnavailable);
    }

    public function test_a_proxy_with_signing_on_and_no_overlap_carries_exactly_one_secret(): void
    {
        $proxy = Proxy::factory()->create();
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
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_current');

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 1);

        $this->assertNotNull($unit);
        $this->assertSame(['whsec_current'], $unit->signingSecrets);
        $this->assertNull($unit->signingSecretsUnavailable);
    }

    public function test_a_proxy_with_signing_on_and_an_overlap_running_carries_both_secrets_current_first(): void
    {
        $proxy = Proxy::factory()->create();
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
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Signing, 'whsec_superseded');
        $store->replace($proxy, SecretPurpose::Signing, 'whsec_current');

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 1);

        $this->assertNotNull($unit);
        $this->assertSame(['whsec_current', 'whsec_superseded'], $unit->signingSecrets);
        $this->assertNull($unit->signingSecretsUnavailable);
    }

    /**
     * AC54: enabling signing on a proxy signs dispatches to EVERY destination
     * of that proxy, including one added AFTER signing was enabled — no
     * per-row lookup, no per-row state. Proven here at the resolver level: a
     * destination created after the secret already exists resolves the same
     * live signing set as one that predates it.
     */
    public function test_a_destination_added_after_signing_was_enabled_still_resolves_the_same_signing_set(): void
    {
        $proxy = Proxy::factory()->create();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_current');

        // Created after the secret already exists.
        $lateDestination = Destination::factory()->createQuietly([
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
            'destination_id' => $lateDestination->id,
            'webhook_event_id' => $event->id,
        ]);

        $unit = app(DeliveryUnitResolver::class)->resolve($delivery, 1);

        $this->assertNotNull($unit);
        $this->assertSame(['whsec_current'], $unit->signingSecrets);
    }
}
