<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Scopes\TeamScope;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = WebhookEvent::factory();

        return [
            'webhook_event_id' => $event,
            'team_id' => fn (array $attributes) => WebhookEvent::withoutGlobalScope(TeamScope::class)
                ->whereKey($attributes['webhook_event_id'])->firstOrFail()->team_id,
            'proxy_id' => fn (array $attributes) => WebhookEvent::withoutGlobalScope(TeamScope::class)
                ->whereKey($attributes['webhook_event_id'])->firstOrFail()->proxy_id,
            'destination_id' => fn (array $attributes) => Destination::factory()->state([
                'proxy_id' => $attributes['proxy_id'],
                'team_id' => $attributes['team_id'],
            ]),
            'dispatch_uuid' => (string) Str::uuid(),
            'kind' => DispatchKind::Original,
            'status' => DeliveryStatus::Pending,
            'next_attempt_at' => null,
        ];
    }
}
