<?php

namespace Tests\Unit\Http\Resources;

use App\Enums\ProxyMode;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * T25 — `DeliveryResource`: never-content, field mapping, the
 * `RetryPolicy`-resolved effective `attempt_limit`, and `withTrashed`
 * destination rendering (AC12, AC15-AC17; ADR-017 Decision 5).
 */
class DeliveryResourceTest extends TestCase
{
    private function delivery(array $overrides = []): Delivery
    {
        $proxy = Proxy::factory()->createQuietly($overrides['proxy'] ?? []);
        unset($overrides['proxy']);
        $destination = $overrides['destination'] ?? Destination::factory()->for($proxy)->createQuietly();
        unset($overrides['destination']);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        return Delivery::factory()->createQuietly(array_merge([
            'webhook_event_id' => $event->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
        ], $overrides));
    }

    private function toArray(Delivery $delivery): array
    {
        return (new DeliveryResource($delivery->load('destination', 'proxy')))->resolve(request());
    }

    public function test_it_never_emits_body_or_headers(): void
    {
        $array = $this->toArray($this->delivery());

        $this->assertArrayNotHasKey('body', $array);
        $this->assertArrayNotHasKey('headers', $array);
    }

    public function test_it_maps_the_expected_fields(): void
    {
        $delivery = $this->delivery();

        $array = $this->toArray($delivery);

        $this->assertSame($delivery->id, $array['id']);
        $this->assertSame($delivery->dispatch_uuid, $array['dispatch_uuid']);
        $this->assertSame($delivery->kind->value, $array['kind']);
        $this->assertSame($delivery->status->value, $array['status']);
        $this->assertTrue($delivery->created_at->equalTo($array['created_at']));
        $this->assertSame($delivery->destination->url, $array['destination']['url']);
        $this->assertSame($delivery->destination->http_method->value, $array['destination']['http_method']);
    }

    public function test_attempt_limit_reflects_the_proxys_effective_policy(): void
    {
        // Enhanced (ADR-018 Decision 2): the column is only consulted for an
        // Enhanced proxy.
        $delivery = $this->delivery(['proxy' => ['mode' => ProxyMode::Enhanced, 'retry_attempt_limit' => 3]]);

        $this->assertSame(3, $this->toArray($delivery)['attempt_limit']);
    }

    public function test_attempt_limit_falls_back_to_the_system_default_when_the_column_is_null(): void
    {
        Config::set('retry.default_attempt_limit', 7);
        $delivery = $this->delivery(['proxy' => ['retry_attempt_limit' => null]]);

        $this->assertSame(7, $this->toArray($delivery)['attempt_limit']);
    }

    public function test_destination_renders_through_with_trashed(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $delivery = Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'destination_id' => $destination->id,
        ]);
        $destination->delete();

        $array = (new DeliveryResource($delivery->load(['destination' => fn ($query) => $query->withTrashed(), 'proxy'])))->resolve(request());

        $this->assertSame($destination->url, $array['destination']['url']);
    }

    public function test_attempt_limit_still_resolves_when_the_proxy_has_been_soft_deleted(): void
    {
        // Regression: `$this->proxy` (the default, trashed-exclusive relation)
        // resolves to null once the proxy is soft-deleted, and RetryPolicy::
        // attemptLimitFor() takes a non-nullable Proxy — a bare access threw a
        // TypeError here before the fix.
        $delivery = $this->delivery(['proxy' => ['mode' => ProxyMode::Enhanced, 'retry_attempt_limit' => 3]]);
        $delivery->proxy->delete();

        $this->assertSame(3, $this->toArray($delivery)['attempt_limit']);
    }

    public function test_attempts_key_is_omitted_when_not_loaded(): void
    {
        $this->assertArrayNotHasKey('attempts', $this->toArray($this->delivery()));
    }

    public function test_attempts_key_renders_delivery_attempt_resources_when_loaded(): void
    {
        $delivery = $this->delivery();
        DeliveryAttempt::factory()->createQuietly([
            'delivery_id' => $delivery->id,
            'proxy_id' => $delivery->proxy_id,
            'team_id' => $delivery->team_id,
            'destination_id' => $delivery->destination_id,
        ]);

        $array = (new DeliveryResource($delivery->load(['destination', 'proxy', 'deliveryAttempts'])))->resolve(request());

        $this->assertCount(1, $array['attempts']);
    }
}
