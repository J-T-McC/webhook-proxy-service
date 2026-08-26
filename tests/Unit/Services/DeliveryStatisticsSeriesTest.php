<?php

namespace Tests\Unit\Services;

use App\Enums\AnalyticsWindow;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\SeriesBucket;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\DeliveryStatistics;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The densified trend series (AC16; Amendment B(i); plan-11 Technical
 * rulings 11, 12) — bucket size per window, densification, the half-open
 * bucket boundary at both sizes, the partition property tying the series
 * to the headline figure it sits beside, and the timezone agreement the
 * hourly bucket depends on.
 */
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
        $this->assertSame($twoDaysAgo->date.'T00:00:00', $twoDaysAgo->bucketStart);

        $today = collect($series)->firstWhere('date', CarbonImmutable::now()->format('Y-m-d'));
        $this->assertNotNull($today);
        $this->assertSame(0, $today->delivery->total);
        $this->assertNull($today->delivery->rate);
    }

    public function test_series_length_equals_the_bucket_count_for_all_three_windows(): void
    {
        $team = Team::factory()->createQuietly();

        $this->assertCount(24, $this->statistics->seriesForTeam($team->id, AnalyticsWindow::TwentyFourHours));
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

    /**
     * `StatisticsPanel.bucket` reads `Hour` on the `24h` window and `Day`
     * on `7d`/`30d`, at both team and proxy grain (Technical ruling 11).
     */
    public function test_statistics_panel_bucket_reflects_the_window_at_team_and_proxy_grain(): void
    {
        ['team' => $team, 'proxy' => $proxy] = $this->makeTeamProxyDestination();

        $this->assertSame(SeriesBucket::Hour, $this->statistics->forTeam($team->id, AnalyticsWindow::TwentyFourHours)->bucket);
        $this->assertSame(SeriesBucket::Day, $this->statistics->forTeam($team->id, AnalyticsWindow::SevenDays)->bucket);
        $this->assertSame(SeriesBucket::Day, $this->statistics->forTeam($team->id, AnalyticsWindow::ThirtyDays)->bucket);

        $this->assertSame(SeriesBucket::Hour, $this->statistics->forProxy($proxy, AnalyticsWindow::TwentyFourHours)->bucket);
        $this->assertSame(SeriesBucket::Day, $this->statistics->forProxy($proxy, AnalyticsWindow::SevenDays)->bucket);
        $this->assertSame(SeriesBucket::Day, $this->statistics->forProxy($proxy, AnalyticsWindow::ThirtyDays)->bucket);
    }

    /**
     * AC16/Amendment B(i): `24h` densifies to 24 hourly points, `7d`/`30d`
     * to 7/30 daily ones — asserted at both grains the series is obliged
     * at (team, proxy) — with distinct, consecutive, ascending
     * `bucketStart` values and `date` populated only at the daily grain.
     */
    public function test_bucket_count_and_bucket_starts_are_correct_per_window_at_team_and_proxy_grain(): void
    {
        ['team' => $team, 'proxy' => $proxy] = $this->makeTeamProxyDestination();

        $cases = [
            [AnalyticsWindow::TwentyFourHours, 24, false],
            [AnalyticsWindow::SevenDays, 7, true],
            [AnalyticsWindow::ThirtyDays, 30, true],
        ];

        foreach ($cases as [$window, $count, $dateExpected]) {
            foreach ([
                $this->statistics->seriesForTeam($team->id, $window),
                $this->statistics->seriesForProxy($proxy->id, $window),
            ] as $series) {
                $this->assertCount($count, $series, "wrong point count on {$window->value}");

                $bucketStarts = collect($series)->pluck('bucketStart');
                $this->assertSame(
                    $bucketStarts->unique()->count(),
                    $bucketStarts->count(),
                    "bucketStart values must be distinct on {$window->value}",
                );
                $this->assertSame(
                    $bucketStarts->sort()->values()->all(),
                    $bucketStarts->all(),
                    "bucketStart values must be ascending on {$window->value}",
                );

                foreach ($series as $point) {
                    if ($dateExpected) {
                        $this->assertSame(substr($point->bucketStart, 0, 10), $point->date);
                    } else {
                        $this->assertNull($point->date, "date must be null at hourly grain on {$window->value}");
                    }
                }
            }
        }
    }

    /**
     * Technical ruling 11: boundaries are half-open at the hour bucket size
     * — a record at exactly the top of the hour falls in that bucket, one
     * at the last instant before it falls in the previous bucket.
     */
    public function test_hourly_bucket_boundary_is_half_open(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $fixedNow = CarbonImmutable::now()->startOfHour()->addMinutes(45);
        $this->travelTo($fixedNow);

        $windowStart = AnalyticsWindow::TwentyFourHours->start($fixedNow);
        // A bucket boundary comfortably inside the window (not the first or last bucket).
        $boundary = $windowStart->addHours(10);

        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
            'updated_at' => $boundary,
        ])->createQuietly();

        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
            'updated_at' => $boundary->subSecond(),
        ])->createQuietly();

        $series = $this->statistics->seriesForProxy($proxy->id, AnalyticsWindow::TwentyFourHours);

        $atBoundary = collect($series)->firstWhere('bucketStart', $boundary->format('Y-m-d\TH:i:s'));
        $beforeBoundary = collect($series)->firstWhere('bucketStart', $boundary->subHour()->format('Y-m-d\TH:i:s'));

        $this->assertNotNull($atBoundary);
        $this->assertNotNull($beforeBoundary);
        $this->assertSame(1, $atBoundary->delivery->total, 'the instant at H:00:00 must fall in bucket H, not H-1');
        $this->assertSame(1, $beforeBoundary->delivery->total, 'the instant just before H:00:00 must fall in bucket H-1, not H');

        $this->travelBack();
    }

    /**
     * The same half-open-boundary assertion re-checked at the day bucket —
     * ruling 10's existing `date`-drill-through partition, reasserted here
     * against the bucket that produces it (ruling 11).
     */
    public function test_daily_bucket_boundary_is_half_open(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $fixedNow = CarbonImmutable::now();
        $this->travelTo($fixedNow);

        $windowStart = AnalyticsWindow::SevenDays->start($fixedNow);
        $boundary = $windowStart->addDays(3);

        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
            'updated_at' => $boundary,
        ])->createQuietly();

        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
            'updated_at' => $boundary->subSecond(),
        ])->createQuietly();

        $series = $this->statistics->seriesForProxy($proxy->id, AnalyticsWindow::SevenDays);

        $atBoundary = collect($series)->firstWhere('bucketStart', $boundary->format('Y-m-d\TH:i:s'));
        $beforeBoundary = collect($series)->firstWhere('bucketStart', $boundary->subDay()->format('Y-m-d\TH:i:s'));

        $this->assertNotNull($atBoundary);
        $this->assertNotNull($beforeBoundary);
        $this->assertSame(1, $atBoundary->delivery->total, 'midnight itself must fall in the new day, not the previous one');
        $this->assertSame(1, $beforeBoundary->delivery->total, 'the instant just before midnight must fall in the previous day');

        $this->travelBack();
    }

    /**
     * Amendment B(i)'s own stated property, and the test that would have
     * caught the calendar-vs-rolling divergence ruling 12 closes: summing
     * the series' per-bucket counts, at each unit, equals the window's own
     * single-number figure for the same unit, subject and window — on all
     * three windows.
     */
    public function test_the_series_partitions_the_window_matching_the_headline_figure_on_all_three_windows(): void
    {
        foreach ([AnalyticsWindow::TwentyFourHours, AnalyticsWindow::SevenDays, AnalyticsWindow::ThirtyDays] as $window) {
            ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

            $fixedNow = CarbonImmutable::now();
            $this->travelTo($fixedNow);

            $start = $window->start($fixedNow);
            $step = $window->bucket() === SeriesBucket::Hour ? 3 : 2;

            foreach ([0, $step, $step * 2] as $i => $offset) {
                $instant = $window->bucket() === SeriesBucket::Hour
                    ? $start->addHours($offset)
                    : $start->addDays($offset);

                $deliveryStatus = $i % 2 === 0 ? DeliveryStatus::Succeeded : DeliveryStatus::Failed;
                $attemptStatus = $i % 2 === 0 ? AttemptStatus::Succeeded : AttemptStatus::Failed;

                Delivery::factory()->state([
                    'team_id' => $team->id,
                    'proxy_id' => $proxy->id,
                    'destination_id' => $destination->id,
                    'status' => $deliveryStatus,
                    'updated_at' => $instant,
                ])->createQuietly();

                DeliveryAttempt::factory()->state([
                    'team_id' => $team->id,
                    'proxy_id' => $proxy->id,
                    'destination_id' => $destination->id,
                    'attempt_number' => 1,
                    'status' => $attemptStatus,
                    'updated_at' => $instant,
                ])->createQuietly();
            }

            $panel = $this->statistics->forProxy($proxy, $window);

            $this->assertSame(
                $panel->delivery->total,
                collect($panel->series)->sum(fn ($point) => $point->delivery->total),
                "delivery total does not partition on {$window->value}",
            );
            $this->assertSame(
                $panel->delivery->succeeded,
                collect($panel->series)->sum(fn ($point) => $point->delivery->succeeded),
                "delivery succeeded does not partition on {$window->value}",
            );
            $this->assertSame(
                $panel->attempt->total,
                collect($panel->series)->sum(fn ($point) => $point->attempt->total),
                "attempt total does not partition on {$window->value}",
            );

            $this->travelBack();
        }
    }

    /**
     * Technical ruling 9 (as amended): with a known application timezone, a
     * record written at a known instant lands in the bucket that instant
     * belongs to, checked at hourly grain and through the database — an
     * `updated_at` written via Eloquent (which stores/reads through the
     * connection under `config('app.timezone')`) buckets, via the SQL
     * `SUBSTRING` expression, into exactly the hour `CarbonImmutable`
     * computes for the same instant.
     */
    public function test_the_bucket_expression_agrees_with_carbon_on_the_application_timezone(): void
    {
        $this->assertSame('UTC', config('app.timezone'));

        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $knownInstant = CarbonImmutable::create(2026, 8, 26, 14, 30, 0, 'UTC');
        $this->travelTo($knownInstant->addHours(2));

        Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
            'updated_at' => $knownInstant,
        ])->createQuietly();

        $series = $this->statistics->seriesForProxy($proxy->id, AnalyticsWindow::TwentyFourHours);

        $expectedBucketStart = $knownInstant->startOfHour()->format('Y-m-d\TH:i:s');
        $point = collect($series)->firstWhere('bucketStart', $expectedBucketStart);

        $this->assertNotNull($point, "the known instant's hour bucket ({$expectedBucketStart}) must exist in the densified series");
        $this->assertSame(1, $point->delivery->total);
        $this->assertSame(1, $point->delivery->succeeded);

        $this->travelBack();
    }

    /**
     * The bucket keys the grouped SQL query produces match the keys
     * densification generates, exactly, with no rows falling through to a
     * bucket that was never emitted — one record in every one of the 24
     * hourly buckets, none dropped and none doubled up. Runs against
     * whichever engine this suite is configured against (MySQL 8.4 under
     * Sail); see T30's completion note for the separate SQLite-side check
     * of the `SUBSTRING` expression itself.
     */
    public function test_no_hourly_bucket_key_falls_through_between_sql_grouping_and_densification(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        // Frozen at a fixed 45 minutes past the hour so the last bucket's
        // record (below, at +15 minutes) is always comfortably before `now`
        // — otherwise this test's own pass/fail would depend on the wall-clock
        // minute it happened to run at.
        $fixedNow = CarbonImmutable::now()->startOfHour()->addMinutes(45);
        $this->travelTo($fixedNow);

        $start = AnalyticsWindow::TwentyFourHours->start($fixedNow);

        for ($i = 0; $i < 24; $i++) {
            Delivery::factory()->state([
                'team_id' => $team->id,
                'proxy_id' => $proxy->id,
                'destination_id' => $destination->id,
                'status' => DeliveryStatus::Succeeded,
                'updated_at' => $start->addHours($i)->addMinutes(15),
            ])->createQuietly();
        }

        $series = $this->statistics->seriesForProxy($proxy->id, AnalyticsWindow::TwentyFourHours);

        $this->assertCount(24, $series);
        foreach ($series as $point) {
            $this->assertSame(
                1,
                $point->delivery->total,
                "bucket {$point->bucketStart} did not receive its record — a bucket-key mismatch between SQL and densification",
            );
        }

        $this->travelBack();
    }
}
