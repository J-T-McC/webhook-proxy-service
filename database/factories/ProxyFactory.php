<?php

namespace Database\Factories;

use App\Enums\ProxyMode;
use App\Models\Proxy;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proxy>
 */
class ProxyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = base64_encode(random_bytes(32));

        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(3, true),
            'mode' => ProxyMode::Simple,
            'ingest_token' => $token,
            'ingest_token_hash' => hash('sha256', $token, binary: true),
        ];
    }

    /**
     * Indicate that the proxy uses enhanced mode.
     */
    public function enhanced(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => ProxyMode::Enhanced,
        ]);
    }

    /**
     * Indicate that the proxy has been soft-deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
