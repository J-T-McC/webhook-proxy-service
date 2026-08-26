<?php

namespace Tests\Unit\Services;

use App\Enums\AnalyticsWindow;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\DeliveryStatistics;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DeliveryStatisticsSeriesTest extends TestCase
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

    public function test_a_day_with_no_traffic_is_a_real_densified_point_with_zero_counts_and_null_rate(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $this->travelTo(CarbonImmutable::now()->subDays(2));
        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();
        $this->travelBack();

        $series = $this->statistics->seriesForTeam($team->id, AnalyticsWindow::SevenDays);

        $this->assertCount(7, $series);

        $twoDaysAgo = collect($series)->firstWhere('date', CarbonImmutable::now()->subDays(2)->format('Y-m-d'));
        $this->assertNotNull($twoDaysAgo);
        $this->assertSame(1, $twoDaysAgo->delivery->succeeded);
        $this->assertSame(1, $twoDaysAgo->delivery->total);

        $today = collect($series)->firstWhere('date', CarbonImmutable::now()->format('Y-m-d'));
        $this->assertNotNull($today);
        $this->assertSame(0, $today->delivery->total);
        $this->assertNull($today->delivery->rate);
    }

    public function test_series_length_equals_the_window_day_count_for_seven_and_thirty_days(): void
    {
        $team = Team::factory()->createQuietly();

        $this->assertCount(7, $this->statistics->seriesForTeam($team->id, AnalyticsWindow::SevenDays));
        $this->assertCount(30, $this->statistics->seriesForTeam($team->id, AnalyticsWindow::ThirtyDays));
    }

    public function test_a_sparse_group_by_result_never_shortens_the_series(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        // A single day's traffic, 29 days ago — the other 29 days of a 30-day window
        // have no rows at all in the raw GROUP BY result.
        $this->travelTo(CarbonImmutable::now()->subDays(29));
        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();
        $this->travelBack();

        $series = $this->statistics->seriesForProxy($proxy->id, AnalyticsWindow::ThirtyDays);

        $this->assertCount(30, $series);
    }

    public function test_an_all_empty_window_series_has_every_point_zeroed(): void
    {
        $team = Team::factory()->createQuietly();

        $series = $this->statistics->seriesForTeam($team->id, AnalyticsWindow::SevenDays);

        $this->assertCount(7, $series);
        foreach ($series as $point) {
            $this->assertSame(0, $point->delivery->total);
            $this->assertNull($point->delivery->rate);
            $this->assertSame(0, $point->attempt->total);
            $this->assertNull($point->attempt->rate);
        }
    }
}
