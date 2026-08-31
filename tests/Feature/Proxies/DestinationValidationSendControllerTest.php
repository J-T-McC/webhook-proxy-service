<?php

namespace Tests\Feature\Proxies;

use App\Enums\DestinationValidationState;
use App\Enums\TeamRole;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Services\OutboundAddressGuard;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The member-facing Validate action (#18 T16; AC14, AC21, AC44) —
 * `POST proxies/{proxy}/destinations/{destination}/validate`, gated by the
 * existing update-destination permission, rate limits surfaced as the
 * `send_blocked` fact on the `security` prop rather than a dead button.
 */
class DestinationValidationSendControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);

        // Every destination URL here is a fake host; the guard is driven with
        // a fixed public answer, the same idiom as
        // SendDestinationValidationChallengeTest.
        $this->app->bind(
            OutboundAddressGuard::class,
            fn () => new OutboundAddressGuard(fn (string $host) => ['93.184.216.34']),
        );
    }

    private function member(Team $team, TeamRole $role): User
    {
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        return $user;
    }

    /**
     * @return array{0: User, 1: Proxy, 2: Destination}
     */
    private function memberWithOwnUnvalidatedDestination(): array
    {
        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'created_by' => $member->id,
        ]);
        $destination = Destination::factory()->for($proxy)->unvalidated()->createQuietly();

        return [$member, $proxy, $destination];
    }

    private function sendRoute(User $actor, Proxy $proxy, Destination $destination): string
    {
        return route('proxies.destinations.validate.store', [
            'current_team' => $actor->currentTeam->slug,
            'proxy' => $proxy->id,
            'destination' => $destination->id,
        ]);
    }

    public function test_a_member_may_validate_their_own_destination_and_it_moves_to_pending(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        [$member, $proxy, $destination] = $this->memberWithOwnUnvalidatedDestination();

        $this->actingAs($member)
            ->post($this->sendRoute($member, $proxy, $destination))
            ->assertRedirect(route('proxies.show', [
                'current_team' => $member->currentTeam->slug,
                'proxy' => $proxy->id,
            ]));

        $destination->refresh();
        $this->assertSame(DestinationValidationState::Pending, $destination->validation_state);
        $this->assertNotNull($destination->validation_challenge_sent_at);
        Http::assertSentCount(1);
    }

    public function test_a_member_without_update_permission_is_forbidden_and_nothing_is_sent(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $team = Team::factory()->createQuietly();
        $creator = $this->member($team, TeamRole::Member);
        $otherMember = $this->member($team, TeamRole::Member);
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'created_by' => $creator->id,
        ]);
        $destination = Destination::factory()->for($proxy)->unvalidated()->createQuietly();

        $this->actingAs($otherMember)
            ->post($this->sendRoute($otherMember, $proxy, $destination))
            ->assertForbidden();

        $destination->refresh();
        $this->assertSame(DestinationValidationState::Unvalidated, $destination->validation_state);
        Http::assertNothingSent();
    }

    public function test_a_second_click_inside_five_minutes_sends_nothing_and_reports_when_to_retry(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        [$member, $proxy, $destination] = $this->memberWithOwnUnvalidatedDestination();

        $this->actingAs($member)
            ->post($this->sendRoute($member, $proxy, $destination))
            ->assertRedirect();

        // The once-per-5-minutes destination limit is now spent; the second
        // click sends nothing and redirects back, and the refreshed page
        // carries the blocked fact — which limit, in plain language, and the
        // absolute time it clears (AC21, design-18 Flow D).
        $this->actingAs($member)
            ->post($this->sendRoute($member, $proxy, $destination))
            ->assertRedirect();

        Http::assertSentCount(1);

        $this->actingAs($member)
            ->get(route('proxies.show', [
                'current_team' => $member->currentTeam->slug,
                'proxy' => $proxy->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    "security.destinations.{$destination->id}.validation.send_blocked.description",
                    'the once-per-5-minutes limit for this destination',
                )
                ->has("security.destinations.{$destination->id}.validation.send_blocked.until")
            );
    }

    public function test_a_validated_destination_is_refused_and_stays_validated(): void
    {
        // AC6/AC14 (review-18 finding 4): the UI hides the button on a
        // Validated row, but the server is the enforcement surface — a
        // hand-crafted POST must not move the destination back to Pending.
        Http::fake(['*' => Http::response('ok', 200)]);

        $team = Team::factory()->createQuietly();
        $member = $this->member($team, TeamRole::Member);
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'created_by' => $member->id,
        ]);
        $destination = Destination::factory()->for($proxy)->validated()->createQuietly();

        $this->actingAs($member)
            ->post($this->sendRoute($member, $proxy, $destination))
            ->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(DestinationValidationState::Validated, $destination->refresh()->validation_state);
        $this->assertNotNull($destination->validated_at);
    }

    public function test_an_unblocked_destination_reports_no_send_block(): void
    {
        [$member, $proxy, $destination] = $this->memberWithOwnUnvalidatedDestination();

        $this->actingAs($member)
            ->get(route('proxies.show', [
                'current_team' => $member->currentTeam->slug,
                'proxy' => $proxy->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("security.destinations.{$destination->id}.validation.send_blocked", null)
            );
    }
}
