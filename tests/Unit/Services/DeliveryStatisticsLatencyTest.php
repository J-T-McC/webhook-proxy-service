<?php

namespace Tests\Unit\Services;

use App\Enums\AnalyticsWindow;
use App\Enums\AttemptStatus;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\DeliveryStatistics;
use Tests\TestCase;

class DeliveryStatisticsLatencyTest extends TestCase
{
    private DeliveryStatistics $statistics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statistics = new DeliveryStatistics;
    }

    /**
     * @return array{team: Team, proxy: Proxy, destination: Destination}
     */
    private function makeTeamProxyDestination(): array
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->state(['team_id' => $team->id])->createQuietly();
        $destination = Destination::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();

        return ['team' => $team, 'proxy' => $proxy, 'destination' => $destination];
    }

    /**
     * @param  list<int>  $durationsMs
     */
    private function makeAttemptsWithDurations(Team $team, Proxy $proxy, Destination $destination, array $durationsMs): void
    {
        foreach ($durationsMs as $index => $durationMs) {
            DeliveryAttempt::factory()->state([
                'team_id' => $team->id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'attempt_number' => $index + 1,
                'status' => AttemptStatus::Succeeded,
                'duration_ms' => $durationMs,
            ])->createQuietly();
        }
    }

    public function test_the_exact_percentile_at_boundary_n_equals_1(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $this->makeAttemptsWithDurations($team, $proxy, $destination, [100]);

        $latency = $this->statistics->latencyForTeam($team->id, AnalyticsWindow::ThirtyDays);

        // n = 1, ordinal = CEIL(0.95 * 1) = 1 -> the only value.
        $this->assertSame(100, $latency->p95Ms);
        $this->assertSame(1, $latency->sampleCount);
    }

    public function test_the_exact_percentile_at_boundary_n_equals_2(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $this->makeAttemptsWithDurations($team, $proxy, $destination, [100, 200]);

        $latency = $this->statistics->latencyForTeam($team->id, AnalyticsWindow::ThirtyDays);

        // n = 2, ordinal = CEIL(0.95 * 2) = 2 -> the 2nd-smallest value.
        $this->assertSame(200, $latency->p95Ms);
        $this->assertSame(2, $latency->sampleCount);
    }

    public function test_the_exact_percentile_at_boundary_n_equals_20(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        // Durations 10, 20, ..., 200 ms — the 19th-smallest (190ms) is CEIL(0.95 * 20) = 19th ordinal.
        $durations = array_map(fn (int $i) => $i * 10, range(1, 20));
        $this->makeAttemptsWithDurations($team, $proxy, $destination, $durations);

        $latency = $this->statistics->latencyForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(190, $latency->p95Ms);
        $this->assertSame(20, $latency->sampleCount);
    }

    public function test_average_and_percentile_share_the_same_not_null_guarded_population(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        // Two attempts with a recorded duration, one dispatched (no duration_ms at all,
        // and excluded from the population anyway by status).
        $this->makeAttemptsWithDurations($team, $proxy, $destination, [100, 300]);
        DeliveryAttempt::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => AttemptStatus::Dispatched,
            'duration_ms' => null,
        ])->createQuietly();

        $latency = $this->statistics->latencyForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(2, $latency->sampleCount);
        $this->assertSame(200, $latency->averageMs);
    }

    public function test_a_window_with_no_resolved_attempts_yields_all_null_and_zero(): void
    {
        $team = Team::factory()->createQuietly();

        $latency = $this->statistics->latencyForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertNull($latency->averageMs);
        $this->assertNull($latency->p95Ms);
        $this->assertSame(0, $latency->sampleCount);
    }

    public function test_p95_is_present_at_team_and_proxy_grain_but_null_at_destination_grain(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $this->makeAttemptsWithDurations($team, $proxy, $destination, [100, 200, 300]);

        $team_latency = $this->statistics->latencyForTeam($team->id, AnalyticsWindow::ThirtyDays);
        $proxy_latency = $this->statistics->latencyForProxy($proxy->id, AnalyticsWindow::ThirtyDays);
        $destination_latency = $this->statistics->latencyForDestination($proxy->id, $destination->id, AnalyticsWindow::ThirtyDays);

        $this->assertNotNull($team_latency->p95Ms);
        $this->assertNotNull($proxy_latency->p95Ms);
        $this->assertNull($destination_latency->p95Ms);
        // But the destination-grain average is still populated.
        $this->assertNotNull($destination_latency->averageMs);
    }
}
