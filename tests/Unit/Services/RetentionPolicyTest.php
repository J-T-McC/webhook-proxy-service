<?php

namespace Tests\Unit\Services;

use App\Models\Team;
use App\Models\WebhookEvent;
use App\Services\RetentionPolicy;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetentionPolicyTest extends TestCase
{
    public function test_window_for_returns_the_configured_default_for_every_team(): void
    {
        $policy = new RetentionPolicy;
        $teamA = Team::factory()->createQuietly();
        $teamB = Team::factory()->createQuietly();

        $expected = CarbonInterval::days((int) config('retention.days'));

        $this->assertEquals($expected->totalSeconds, $policy->windowFor($teamA)->totalSeconds);
        $this->assertEquals($expected->totalSeconds, $policy->windowFor($teamB)->totalSeconds);
    }

    public function test_cutoff_for_derives_from_window_for(): void
    {
        $policy = new RetentionPolicy;
        $team = Team::factory()->createQuietly();

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00'));

        $expected = now()->sub($policy->windowFor($team));

        $this->assertTrue($expected->equalTo($policy->cutoffFor($team)));

        Carbon::setTestNow();
    }

    public function test_expires_at_derives_from_window_for_and_created_at(): void
    {
        $policy = new RetentionPolicy;
        $team = Team::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'team_id' => $team->id,
            'created_at' => Carbon::parse('2026-07-01 00:00:00'),
        ]);

        $expected = Carbon::parse('2026-07-01 00:00:00')->add($policy->windowFor($team));

        $this->assertTrue($expected->equalTo($policy->expiresAt($event->fresh())));
    }

    public function test_substituted_window_for_one_team_changes_only_that_teams_outcome(): void
    {
        $team = Team::factory()->createQuietly();
        $otherTeam = Team::factory()->createQuietly();

        $policy = new class extends RetentionPolicy
        {
            public function windowFor(Team $team): CarbonInterval
            {
                if ($team->id === $this->specialTeamId) {
                    return CarbonInterval::days(1);
                }

                return parent::windowFor($team);
            }

            public int $specialTeamId = 0;
        };
        $policy->specialTeamId = $team->id;

        $defaultCutoff = $policy->cutoffFor($otherTeam);
        $overriddenCutoff = $policy->cutoffFor($team);

        // The overridden team's cutoff (1-day window) is a MORE RECENT point in
        // time than the default team's (30-day window) — proving cutoffFor
        // composes through windowFor rather than duplicating the config read.
        $this->assertTrue($overriddenCutoff->greaterThan($defaultCutoff));
    }
}
