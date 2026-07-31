<?php

namespace Database\Factories;

use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Scopes\TeamScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Destination>
 */
class DestinationFactory extends Factory
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
            'team_id' => fn (array $attributes) => Proxy::withoutGlobalScope(TeamScope::class)
                ->whereKey($attributes['proxy_id'])->firstOrFail()->team_id,
            'url' => 'https://'.fake()->domainName().'/'.fake()->slug(),
            'http_method' => HttpMethod::Post,
        ];
    }

    /**
     * Indicate that the destination has been soft-deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
