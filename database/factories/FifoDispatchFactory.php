<?php

namespace Database\Factories;

use App\Enums\FifoDispatchStatus;
use App\Models\FifoDispatch;
use App\Models\Scopes\TeamScope;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FifoDispatch>
 */
class FifoDispatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The captured event is the anchor: proxy_id and team_id are derived from it
        // (unscoped by team, mirroring WebhookEventFactory — the ingest path is
        // team-unscoped), so all three references stay consistent.
        $webhookEvent = WebhookEvent::factory();

        return [
            'webhook_event_id' => $webhookEvent,
            'proxy_id' => fn (array $attributes) => WebhookEvent::withoutGlobalScope(TeamScope::class)
                ->whereKey($attributes['webhook_event_id'])->firstOrFail()->proxy_id,
            'team_id' => fn (array $attributes) => WebhookEvent::withoutGlobalScope(TeamScope::class)
                ->whereKey($attributes['webhook_event_id'])->firstOrFail()->team_id,
            'status' => FifoDispatchStatus::Pending,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'settled_at' => null,
        ];
    }
}
