<?php

namespace Tests\Unit\Http\Resources;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Http\Resources\WebhookEventResource;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T25 — `WebhookEventResource`: never-content, the payload-state mapping, and
 * the legacy-fallback derivation for a pre-#6 event (AC12, AC15-AC17, AC22,
 * AC25; ADR-017 Decision 5; Q-06-03 ruling 3).
 */
class WebhookEventResourceTest extends TestCase
{
    private function toArray(WebhookEvent $event): array
    {
        return (new WebhookEventResource($event))->resolve(request());
    }

    public function test_it_never_emits_body_or_headers(): void
    {
        $event = WebhookEvent::factory()->createQuietly();

        $array = $this->toArray($event);

        $this->assertArrayNotHasKey('body', $array);
        $this->assertArrayNotHasKey('headers', $array);
    }

    // --- payload_state mapping ----------------------------------------------

    public function test_payload_state_is_retained_when_not_cleaned(): void
    {
        $event = WebhookEvent::factory()->createQuietly();

        $this->assertSame('retained', $this->toArray($event)['payload_state']);
    }

    public function test_payload_state_is_cleaned_when_erased(): void
    {
        $event = WebhookEvent::factory()->cleaned()->createQuietly();

        $this->assertSame('cleaned', $this->toArray($event)['payload_state']);
    }

    public function test_payload_state_is_never_captured_for_an_unknown_ingest_id(): void
    {
        $event = WebhookEvent::factory()->createQuietly();
        $event->ingest_id = 'unknown-ingest-id';

        $this->assertSame('never_captured', $this->toArray($event)['payload_state']);
    }

    // --- deliveries: not loaded / loaded ------------------------------------

    public function test_deliveries_key_is_omitted_when_the_relation_is_not_loaded(): void
    {
        $event = WebhookEvent::factory()->createQuietly();

        $this->assertArrayNotHasKey('deliveries', $this->toArray($event));
    }

    public function test_deliveries_key_renders_delivery_resources_when_loaded_and_non_empty(): void
    {
        $event = WebhookEvent::factory()->createQuietly();
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'proxy_id' => $event->proxy_id,
            'team_id' => $event->team_id,
        ]);
        $event->load('deliveries.destination');

        $array = $this->toArray($event);

        $this->assertCount(1, $array['deliveries']);
    }

    // --- legacy fallback (ruling 3) ------------------------------------------

    /**
     * @return array<string, array{0: AttemptStatus, 1: string}>
     */
    public static function legacyOutcomes(): array
    {
        return [
            'succeeded -> delivered/succeeded' => [AttemptStatus::Succeeded, DeliveryStatus::Succeeded->value],
            'failed -> failed' => [AttemptStatus::Failed, DeliveryStatus::Failed->value],
            'dispatched -> retrying' => [AttemptStatus::Dispatched, DeliveryStatus::Retrying->value],
        ];
    }

    #[DataProvider('legacyOutcomes')]
    public function test_legacy_fallback_derives_the_expected_status(AttemptStatus $attemptStatus, string $expectedDeliveryStatus): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
            'status' => $attemptStatus,
            'attempt_number' => 1,
        ]);
        $event->load('deliveries');

        $array = $this->toArray($event);

        $this->assertCount(1, $array['deliveries']);
        $derived = $array['deliveries'][0];
        $this->assertNull($derived['id']);
        $this->assertNull($derived['dispatch_uuid']);
        $this->assertSame('original', $derived['kind']);
        $this->assertSame($expectedDeliveryStatus, $derived['status']);
        $this->assertNull($derived['created_at']);
        $this->assertNull($derived['next_attempt_at']);
        $this->assertNull($derived['attempt_limit']);
        $this->assertNull($derived['attempts']);
        $this->assertSame($destination->url, $derived['destination']['url']);
    }

    public function test_legacy_fallback_uses_the_latest_attempt_per_destination(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
            'status' => AttemptStatus::Failed,
            'attempt_number' => 1,
        ]);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
            'status' => AttemptStatus::Succeeded,
            'attempt_number' => 2,
        ]);
        $event->load('deliveries');

        $array = $this->toArray($event);

        $this->assertCount(1, $array['deliveries']);
        $this->assertSame(DeliveryStatus::Succeeded->value, $array['deliveries'][0]['status']);
    }

    public function test_legacy_fallback_creates_no_delivery_row(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        DeliveryAttempt::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
        ]);
        $event->load('deliveries');

        $this->toArray($event);

        $this->assertSame(0, Delivery::query()->count());
    }
}
