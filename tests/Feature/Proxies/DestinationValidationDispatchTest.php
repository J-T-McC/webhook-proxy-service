<?php

namespace Tests\Feature\Proxies;

use App\Actions\SendDestinationValidationChallenge;
use App\Enums\DestinationValidationState;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Automatic challenge dispatch on create and on URL change (#18 AC5, AC15), and
 * the AC13 rule that configuration changes other than the URL leave validation
 * alone.
 */
class DestinationValidationDispatchTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Stripe proxy',
            'mode' => 'simple',
            'processing_mode' => 'async',
            'destinations' => [
                ['url' => 'https://a.example.com/hook', 'http_method' => 'POST'],
                ['url' => 'https://b.example.com/hook', 'http_method' => 'PUT'],
            ],
        ], $overrides);
    }

    public function test_creating_a_proxy_dispatches_a_challenge_for_each_destination(): void
    {
        Queue::fake();

        $user = $this->actingUser();

        $this->actingAs($user)
            ->post(route('proxies.store', ['current_team' => $user->currentTeam->slug]), $this->payload())
            ->assertRedirect();

        SendDestinationValidationChallenge::assertPushed(2);
    }

    public function test_a_newly_created_destination_starts_unvalidated(): void
    {
        Queue::fake();

        $user = $this->actingUser();

        $this->actingAs($user)
            ->post(route('proxies.store', ['current_team' => $user->currentTeam->slug]), $this->payload());

        $this->assertTrue(
            Destination::query()->withoutGlobalScopes()->get()
                ->every(fn (Destination $d) => $d->validation_state === DestinationValidationState::Unvalidated),
        );
    }

    public function test_changing_a_destinations_url_resets_it_and_dispatches_a_fresh_challenge(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly([
            'team_id' => $proxy->team_id,
            'url' => 'https://original.example.com/hook',
        ]);
        $destination->forceFill(['validation_last_send_status' => 404])->save();

        $this->actingAs($user)
            ->put(
                route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
                $this->payload([
                    'destinations' => [[
                        'id' => $destination->id,
                        'url' => 'https://moved.example.com/hook',
                        'http_method' => 'POST',
                    ]],
                ]),
            )
            ->assertRedirect();

        $destination->refresh();

        $this->assertSame(DestinationValidationState::Unvalidated, $destination->validation_state);
        $this->assertNull($destination->validated_at);
        $this->assertNull(
            $destination->validation_nonce,
            'The outstanding link must be voided, not left usable against the new URL.',
        );
        $this->assertNull(
            $destination->validation_last_send_status,
            'The recorded outcome describes a send to the old address and would misdescribe the new one (AC35).',
        );
        $this->assertNull($destination->validation_last_send_failure);

        SendDestinationValidationChallenge::assertPushed(1);
    }

    public function test_an_edit_that_does_not_touch_the_url_leaves_a_validated_destination_alone(): void
    {
        // AC13: configuration is not gated. Changing the method, or anything
        // else, must not cost a validated destination its state.
        Queue::fake();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destination = Destination::factory()->for($proxy)->createQuietly([
            'team_id' => $proxy->team_id,
            'url' => 'https://stable.example.com/hook',
            'http_method' => 'POST',
        ]);

        $this->actingAs($user)
            ->put(
                route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
                $this->payload([
                    'destinations' => [[
                        'id' => $destination->id,
                        'url' => 'https://stable.example.com/hook',
                        'http_method' => 'PUT',
                    ]],
                ]),
            )
            ->assertRedirect();

        $this->assertSame(
            DestinationValidationState::Validated,
            $destination->refresh()->validation_state,
        );

        SendDestinationValidationChallenge::assertNotPushed();
    }
}
