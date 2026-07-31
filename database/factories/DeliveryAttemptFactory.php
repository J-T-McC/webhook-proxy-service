<?php

namespace Database\Factories;

use App\Enums\AttemptStatus;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryAttempt>
 */
class DeliveryAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $proxy = Proxy::factory();

        return [
            'proxy_id' => $proxy,
            'team_id' => fn (array $attributes) => Proxy::whereKey($attributes['proxy_id'])->firstOrFail()->team_id,
            'destination_id' => fn (array $attributes) => Destination::factory()->state([
                'proxy_id' => $attributes['proxy_id'],
                'team_id' => $attributes['team_id'],
            ]),
            'ingest_id' => (string) Str::uuid(),
            'status' => AttemptStatus::Dispatched,
            'http_status' => null,
            'error_summary' => null,
            'attempt_number' => 1,
            'started_at' => now(),
            'duration_ms' => null,
        ];
    }

    /**
     * A successful delivery attempt.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'duration_ms' => fake()->numberBetween(5, 500),
        ]);
    }

    /**
     * A failed delivery attempt.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttemptStatus::Failed,
            'http_status' => 500,
            'error_summary' => 'HTTP 500',
            'duration_ms' => fake()->numberBetween(5, 500),
        ]);
    }
}
