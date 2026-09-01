<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

/**
 * Concern-scoped: it crosses the team routes and both settings groups on
 * purpose, because what is under test is where the `verified` middleware
 * bites and where it deliberately does not.
 *
 * The guard only has teeth while `App\Models\User` implements
 * `MustVerifyEmail` — `EnsureEmailIsVerified` lets any other user straight
 * through, so removing that interface would silently open every route below
 * without failing a single test elsewhere. These are the tests that fail.
 */
class UnverifiedAccessTest extends TestCase
{
    public function test_an_unverified_user_is_sent_to_the_verification_notice_from_the_dashboard(): void
    {
        $user = User::factory()->unverified()->createQuietly();
        $team = $user->personalTeam();

        $this->actingAs($user)
            ->get("/{$team->slug}/dashboard")
            ->assertRedirect(route('verification.notice'));
    }

    public function test_an_unverified_user_is_sent_to_the_verification_notice_from_the_proxy_list(): void
    {
        $user = User::factory()->unverified()->createQuietly();
        $team = $user->personalTeam();

        $this->actingAs($user)
            ->get("/{$team->slug}/proxies")
            ->assertRedirect(route('verification.notice'));
    }

    public function test_an_unverified_user_is_sent_to_the_verification_notice_from_team_settings(): void
    {
        $user = User::factory()->unverified()->createQuietly();

        $this->actingAs($user)
            ->get(route('teams.index'))
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * The profile page is in the `auth`-only group, not the `verified` one, so
     * an unverified user can still reach it. That is the boundary as routed:
     * changing their email address is how someone recovers from registering
     * with a typo, and it must not be behind the verification they cannot pass.
     */
    public function test_an_unverified_user_can_still_reach_their_profile(): void
    {
        $user = User::factory()->unverified()->createQuietly();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk();
    }

    public function test_a_verified_user_reaches_the_dashboard(): void
    {
        $user = User::factory()->createQuietly();
        $team = $user->personalTeam();

        $this->actingAs($user)
            ->get("/{$team->slug}/dashboard")
            ->assertOk();
    }
}
