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

class DeliveryStatisticsBreakdownTest extends TestCase
{
    private DeliveryStatistics $statistics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statistics = new DeliveryStatistics;
    }

    private function makeProxy(Team $team): Proxy
    {
        return Proxy::factory()->state(['team_id' => $team->id])->createQuietly();
    }

    private function makeDestination(Team $team, Proxy $proxy): Destination
    {
        return Destination::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();
    }

    private function makeTraffic(Team $team, Proxy $proxy, Destination $destination): void
    {
        $delivery = Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Succeeded,
        ])->createQuietly();

        DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery->id,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Succeeded,
            'duration_ms' => 120,
        ])->createQuietly();
    }

    public function test_soft_deleting_a_proxy_and_destination_with_activity_leaves_figures_identical_and_flags_deleted(): void
    {
        $team = Team::factory()->createQuietly();
        $proxy = $this->makeProxy($team);
        $destination = $this->makeDestination($team, $proxy);
        $this->makeTraffic($team, $proxy, $destination);

        $before = $this->statistics->proxyBreakdown($team->id, AnalyticsWindow::ThirtyDays);
        $destinationsBefore = $this->statistics->destinationBreakdown($proxy, AnalyticsWindow::ThirtyDays);

        $proxy->delete();
        $destination->delete();

        $after = $this->statistics->proxyBreakdown($team->id, AnalyticsWindow::ThirtyDays);
        $destinationsAfter = $this->statistics->destinationBreakdown(
            Proxy::withTrashed()->findOrFail($proxy->id),
            AnalyticsWindow::ThirtyDays,
        );

        $this->assertCount(1, $before);
        $this->assertCount(1, $after);
        $this->assertFalse($before[0]->isDeleted);
        $this->assertTrue($after[0]->isDeleted);
        $this->assertSame($before[0]->delivery->succeeded, $after[0]->delivery->succeeded);
        $this->assertSame($before[0]->delivery->total, $after[0]->delivery->total);
        $this->assertSame($before[0]->attempt->succeeded, $after[0]->attempt->succeeded);

        $this->assertCount(1, $destinationsBefore);
        $this->assertCount(1, $destinationsAfter);
        $this->assertFalse($destinationsBefore[0]->isDeleted);
        $this->assertTrue($destinationsAfter[0]->isDeleted);
        $this->assertSame($destinationsBefore[0]->delivery->succeeded, $destinationsAfter[0]->delivery->succeeded);
    }

    public function test_proxy_breakdown_query_count_does_not_grow_with_the_number_of_proxies(): void
    {
        $team = Team::factory()->createQuietly();
        $this->makeProxy($team);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->statistics->proxyBreakdown($team->id, AnalyticsWindow::ThirtyDays);
        $countAtOne = count($queries);

        $teamTen = Team::factory()->createQuietly();
        for ($i = 0; $i < 10; $i++) {
            $this->makeProxy($teamTen);
        }

        $queries = [];
        $this->statistics->proxyBreakdown($teamTen->id, AnalyticsWindow::ThirtyDays);
        $countAtTen = count($queries);

        $this->assertSame($countAtOne, $countAtTen);
    }

    public function test_destination_breakdown_query_count_does_not_grow_with_the_number_of_destinations(): void
    {
        $team = Team::factory()->createQuietly();
        $proxyOne = $this->makeProxy($team);
        $this->makeDestination($team, $proxyOne);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->statistics->destinationBreakdown($proxyOne, AnalyticsWindow::ThirtyDays);
        $countAtOne = count($queries);

        $proxyTen = $this->makeProxy($team);
        for ($i = 0; $i < 10; $i++) {
            $this->makeDestination($team, $proxyTen);
        }

        $queries = [];
        $this->statistics->destinationBreakdown($proxyTen, AnalyticsWindow::ThirtyDays);
        $countAtTen = count($queries);

        $this->assertSame($countAtOne, $countAtTen);
    }

    public function test_can_drill_through_is_false_for_a_deleted_proxy_and_true_for_a_live_one(): void
    {
        $team = Team::factory()->createQuietly();
        $live = $this->makeProxy($team);
        $deleted = $this->makeProxy($team);
        $deleted->delete();

        $rows = collect($this->statistics->proxyBreakdown($team->id, AnalyticsWindow::ThirtyDays))
            ->keyBy('id');

        $this->assertTrue($rows[$live->id]->canDrillThrough);
        $this->assertFalse($rows[$deleted->id]->canDrillThrough);
    }

    public function test_destination_breakdown_includes_a_live_no_traffic_destination_and_a_deleted_with_traffic_one_and_excludes_neither(): void
    {
        $team = Team::factory()->createQuietly();
        $proxy = $this->makeProxy($team);

        $liveNoTraffic = $this->makeDestination($team, $proxy);

        $deletedWithTraffic = $this->makeDestination($team, $proxy);
        $this->makeTraffic($team, $proxy, $deletedWithTraffic);
        $deletedWithTraffic->delete();

        $rows = collect($this->statistics->destinationBreakdown($proxy, AnalyticsWindow::ThirtyDays))
            ->keyBy('id');

        $this->assertCount(2, $rows);

        $this->assertFalse($rows[$liveNoTraffic->id]->isDeleted);
        $this->assertSame(0, $rows[$liveNoTraffic->id]->delivery->total);
        $this->assertNull($rows[$liveNoTraffic->id]->delivery->rate);

        $this->assertTrue($rows[$deletedWithTraffic->id]->isDeleted);
        $this->assertSame(1, $rows[$deletedWithTraffic->id]->delivery->total);
    }
}
