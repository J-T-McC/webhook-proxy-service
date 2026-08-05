<?php

namespace Database\Factories;

use App\Models\Proxy;
use App\Models\Scopes\TeamScope;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $proxy = Proxy::factory();
        $body = '{"event":"test","id":"'.fake()->uuid().'"}';

        return [
            'proxy_id' => $proxy,
            'team_id' => fn (array $attributes) => Proxy::withoutGlobalScope(TeamScope::class)
                ->whereKey($attributes['proxy_id'])->firstOrFail()->team_id,
            'ingest_id' => (string) Str::uuid(),
            'method' => 'POST',
            'headers' => ['content-type' => ['application/json']],
            'content_type' => 'application/json',
            'body' => $body,
            'byte_size' => strlen($body),
            'received_at' => now(),
        ];
    }
}
