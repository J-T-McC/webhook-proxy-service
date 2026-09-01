<?php

namespace Database\Factories;

use App\Enums\DestinationValidationState;
use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Scopes\TeamScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            // Validated by default (#18). The factory models a destination that
            // works, because that is what every other feature's tests need — a
            // proxy that fans out, retries and replays. The column default is
            // `unvalidated` and stays that way; only the factory is opinionated.
            // Item #18's own tests use the explicit states below.
            'validation_state' => DestinationValidationState::Validated,
            'validated_at' => now(),
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

    /**
     * A validated destination — the only state that receives traffic (#18 AC8).
     * The factory default already is this; kept for tests that state it.
     */
    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_state' => DestinationValidationState::Validated,
            'validated_at' => now(),
        ]);
    }

    /**
     * A destination nobody has approved — no challenge sent.
     */
    public function unvalidated(): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_state' => DestinationValidationState::Unvalidated,
            'validated_at' => null,
        ]);
    }

    /**
     * A challenge sent and still open.
     */
    public function pendingValidation(): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_state' => DestinationValidationState::Pending,
            'validation_challenge_sent_at' => now(),
            'validation_challenge_expires_at' => now()->addDays(7),
            'validation_nonce' => Str::random(32),
        ]);
    }

    /**
     * A challenge that was sent and whose window has closed. Stored as
     * `Pending` with a past expiry — `expired` is derived, never written.
     */
    public function expiredValidation(): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_state' => DestinationValidationState::Pending,
            'validation_challenge_sent_at' => now()->subDays(8),
            'validation_challenge_expires_at' => now()->subDay(),
            'validation_nonce' => Str::random(32),
        ]);
    }
}
