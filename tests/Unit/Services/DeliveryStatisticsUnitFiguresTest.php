<?php

namespace Tests\Unit\Services;

use App\Enums\AnalyticsWindow;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\DeliveryStatistics;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryStatisticsUnitFiguresTest extends TestCase
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

    public function test_the_canonical_fixture_reads_100_percent_delivery_and_33_percent_attempt_at_every_grain(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $delivery = Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();

        foreach ([
            ['attempt_number' => 1, 'status' => AttemptStatus::Failed],
            ['attempt_number' => 2, 'status' => AttemptStatus::Failed],
            ['attempt_number' => 3, 'status' => AttemptStatus::Succeeded],
        ] as $attempt) {
            DeliveryAttempt::factory()->state([
                'delivery_id' => $delivery->id,
                'team_id' => $team->id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'attempt_number' => $attempt['attempt_number'],
                'status' => $attempt['status'],
            ])->createQuietly();
        }

        foreach ([
            $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays),
            $this->statistics->unitFiguresForProxy($proxy->id, AnalyticsWindow::ThirtyDays),
            $this->statistics->unitFiguresForDestination($proxy->id, $destination->id, AnalyticsWindow::ThirtyDays),
        ] as $figures) {
            $this->assertSame(1, $figures['delivery']->succeeded);
            $this->assertSame(0, $figures['delivery']->failed);
            $this->assertSame(1, $figures['delivery']->total);
            $this->assertSame(1.0, $figures['delivery']->rate);

            $this->assertSame(1, $figures['attempt']->succeeded);
            $this->assertSame(2, $figures['attempt']->failed);
            $this->assertSame(3, $figures['attempt']->total);
            // Two of the three attempts failed (~67%), so the success rate reads ~33%
            // (1 of 3) — the task doc's "67%" names the failure share, not `rate`
            // (App\Data\Analytics\UnitFigure::$rate is a success rate throughout, matching
            // the delivery-level figure's "100% (1 of 1)" framing in the same fixture).
            $this->assertEqualsWithDelta(1 / 3, $figures['attempt']->rate, PHP_FLOAT_EPSILON);
        }
    }

    public function test_pending_and_retrying_deliveries_are_excluded_and_not_counted_as_failures(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        foreach ([DeliveryStatus::Pending, DeliveryStatus::Retrying] as $status) {
            Delivery::factory()->state([
                'team_id' => $team->id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'status' => $status,
            ])->createQuietly();
        }

        $figures = $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(0, $figures['delivery']->succeeded);
        $this->assertSame(0, $figures['delivery']->failed);
        $this->assertSame(0, $figures['delivery']->total);
        $this->assertNull($figures['delivery']->rate);
    }

    public function test_dispatched_attempts_are_absent_from_attempt_level_counts(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        DeliveryAttempt::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => AttemptStatus::Dispatched,
        ])->createQuietly();

        $figures = $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(0, $figures['attempt']->total);
        $this->assertNull($figures['attempt']->rate);
    }

    public function test_a_pre_number_6_null_delivery_id_attempt_counts_at_attempt_level_and_never_at_delivery_level(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        DeliveryAttempt::factory()->state([
            'delivery_id' => null,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => AttemptStatus::Succeeded,
        ])->createQuietly();

        $figures = $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(0, $figures['delivery']->total);
        $this->assertNull($figures['delivery']->rate);

        $this->assertSame(1, $figures['attempt']->succeeded);
        $this->assertSame(1, $figures['attempt']->total);
        $this->assertSame(1.0, $figures['attempt']->rate);
    }

    public function test_a_window_with_zero_traffic_yields_null_rate_at_both_units(): void
    {
        $team = Team::factory()->createQuietly();

        $figures = $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertNull($figures['delivery']->rate);
        $this->assertNull($figures['attempt']->rate);
        $this->assertSame(0, $figures['delivery']->total);
        $this->assertSame(0, $figures['attempt']->total);
    }

    public function test_traffic_with_zero_of_one_status_still_yields_a_numeric_rate(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createManyQuietly(2);

        DeliveryAttempt::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => AttemptStatus::Failed,
        ])->createManyQuietly(2);

        $figures = $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(2, $figures['delivery']->total);
        $this->assertSame(1.0, $figures['delivery']->rate);

        $this->assertSame(2, $figures['attempt']->total);
        $this->assertSame(0.0, $figures['attempt']->rate);
    }

    public function test_no_query_joins_deliveries_to_delivery_attempts_or_either_to_proxies_or_destinations(): void
    {
        ['team' => $team] = $this->makeTeamProxyDestination();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('join', $sql);
        }
    }
}
