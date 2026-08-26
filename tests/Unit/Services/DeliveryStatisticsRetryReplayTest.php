<?php

namespace Tests\Unit\Services;

use App\Enums\AnalyticsWindow;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\DeliveryStatistics;
use Tests\TestCase;

class DeliveryStatisticsRetryReplayTest extends TestCase
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

    private function makeDelivery(Team $team, Proxy $proxy, Destination $destination, DeliveryStatus $status, DispatchKind $kind = DispatchKind::Original): Delivery
    {
        return Delivery::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'status' => $status,
            'kind' => $kind,
        ])->createQuietly();
    }

    private function makeAttempt(Team $team, Proxy $proxy, Destination $destination, ?Delivery $delivery, int $attemptNumber, AttemptStatus $status): DeliveryAttempt
    {
        return DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery?->id,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'attempt_number' => $attemptNumber,
            'status' => $status,
        ])->createQuietly();
    }

    public function test_eventual_success_counts_only_deliveries_that_took_two_or_more_attempts(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        // Took two attempts to succeed — counted.
        $retried = $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Succeeded);
        $this->makeAttempt($team, $proxy, $destination, $retried, 1, AttemptStatus::Failed);
        $this->makeAttempt($team, $proxy, $destination, $retried, 2, AttemptStatus::Succeeded);

        // Succeeded on the first attempt — not counted.
        $firstTry = $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Succeeded);
        $this->makeAttempt($team, $proxy, $destination, $firstTry, 1, AttemptStatus::Succeeded);

        foreach ([
            $this->statistics->retryReplayForTeam($team->id, AnalyticsWindow::ThirtyDays),
            $this->statistics->retryReplayForProxy($proxy->id, AnalyticsWindow::ThirtyDays),
        ] as $result) {
            $this->assertSame(1, $result['retryReplay']->eventualSuccess);
        }
    }

    public function test_terminal_failure_counts_failed_deliveries(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Failed);
        $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Failed);
        $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Succeeded);

        $result = $this->statistics->retryReplayForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(2, $result['retryReplay']->terminalFailure);
    }

    public function test_retry_volume_sums_attempts_numbered_two_or_higher_excluding_dispatched(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $delivery = $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Succeeded);
        $this->makeAttempt($team, $proxy, $destination, $delivery, 1, AttemptStatus::Failed);
        $this->makeAttempt($team, $proxy, $destination, $delivery, 2, AttemptStatus::Failed);
        $this->makeAttempt($team, $proxy, $destination, $delivery, 3, AttemptStatus::Succeeded);

        // A dispatched (unresolved) retry attempt must not count.
        $this->makeAttempt($team, $proxy, $destination, null, 2, AttemptStatus::Dispatched);

        $result = $this->statistics->retryReplayForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(2, $result['retryReplay']->retryVolume);
    }

    public function test_live_vs_replay_split_by_kind_never_inflates_or_deflates_the_live_count(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Succeeded, DispatchKind::Original);
        $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Succeeded, DispatchKind::Original);
        $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Failed, DispatchKind::Replay);

        $result = $this->statistics->retryReplayForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(2, $result['retryReplay']->live);
        $this->assertSame(1, $result['retryReplay']->replay);
    }

    public function test_bridge_count_matches_the_canonical_fixtures_two_failed_attempts(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination();

        $delivery = $this->makeDelivery($team, $proxy, $destination, DeliveryStatus::Succeeded);
        $this->makeAttempt($team, $proxy, $destination, $delivery, 1, AttemptStatus::Failed);
        $this->makeAttempt($team, $proxy, $destination, $delivery, 2, AttemptStatus::Failed);
        $this->makeAttempt($team, $proxy, $destination, $delivery, 3, AttemptStatus::Succeeded);

        foreach ([
            $this->statistics->retryReplayForTeam($team->id, AnalyticsWindow::ThirtyDays),
            $this->statistics->retryReplayForProxy($proxy->id, AnalyticsWindow::ThirtyDays),
        ] as $result) {
            $this->assertSame(2, $result['bridgeFailedAttempts']);
        }
    }

    public function test_an_empty_window_yields_all_five_figures_as_zero_not_null_and_not_omitted(): void
    {
        $team = Team::factory()->createQuietly();

        $result = $this->statistics->retryReplayForTeam($team->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(0, $result['retryReplay']->eventualSuccess);
        $this->assertSame(0, $result['retryReplay']->terminalFailure);
        $this->assertSame(0, $result['retryReplay']->retryVolume);
        $this->assertSame(0, $result['retryReplay']->live);
        $this->assertSame(0, $result['retryReplay']->replay);
        $this->assertSame(0, $result['bridgeFailedAttempts']);
    }
}
