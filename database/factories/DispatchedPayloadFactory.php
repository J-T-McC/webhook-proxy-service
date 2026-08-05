<?php

namespace Database\Factories;

use App\Models\DispatchedPayload;
use App\Models\Scopes\TeamScope;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispatchedPayload>
 */
class DispatchedPayloadFactory extends Factory
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
            'body' => null,
            'byte_size' => fake()->numberBetween(50, 2000),
            'dispatched_at' => now(),
        ];
    }
}
