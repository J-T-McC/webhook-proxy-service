<?php

namespace Tests\Unit\Services;

use App\Enums\AnalyticsWindow;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\DeliveryStatistics;
use Tests\TestCase;

class DeliveryStatisticsScopingTest extends TestCase
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
    private function makeTeamProxyDestination(array $proxyState = []): array
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->state(['team_id' => $team->id, ...$proxyState])->createQuietly();
        $destination = Destination::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();

        return ['team' => $team, 'proxy' => $proxy, 'destination' => $destination];
    }

    private function makeRetriedSuccess(Team $team, Proxy $proxy, Destination $destination): void
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
            'status' => AttemptStatus::Failed,
        ])->createQuietly();

        DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery->id,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'attempt_number' => 2,
            'status' => AttemptStatus::Succeeded,
        ])->createQuietly();
    }

    public function test_forteam_ignores_a_second_teams_identical_traffic(): void
    {
        ['team' => $teamA, 'proxy' => $proxyA, 'destination' => $destinationA] = $this->makeTeamProxyDestination();
        ['team' => $teamB, 'proxy' => $proxyB, 'destination' => $destinationB] = $this->makeTeamProxyDestination();

        $this->makeRetriedSuccess($teamA, $proxyA, $destinationA);
        $this->makeRetriedSuccess($teamB, $proxyB, $destinationB);

        $panel = $this->statistics->forTeam($teamA->id, AnalyticsWindow::ThirtyDays);

        $this->assertSame(1, $panel->delivery->total);
        $this->assertSame(2, $panel->attempt->total);
    }

    public function test_forproxy_ignores_a_second_teams_identical_traffic(): void
    {
        ['team' => $teamA, 'proxy' => $proxyA, 'destination' => $destinationA] = $this->makeTeamProxyDestination();
        ['team' => $teamB, 'proxy' => $proxyB, 'destination' => $destinationB] = $this->makeTeamProxyDestination();

        $this->makeRetriedSuccess($teamA, $proxyA, $destinationA);
        $this->makeRetriedSuccess($teamB, $proxyB, $destinationB);

        $panel = $this->statistics->forProxy($proxyA, AnalyticsWindow::ThirtyDays);

        $this->assertSame(1, $panel->delivery->total);
        $this->assertSame(2, $panel->attempt->total);
    }

    public function test_proxy_breakdown_ignores_a_second_teams_identical_traffic(): void
    {
        ['team' => $teamA, 'proxy' => $proxyA, 'destination' => $destinationA] = $this->makeTeamProxyDestination();
        ['team' => $teamB, 'proxy' => $proxyB, 'destination' => $destinationB] = $this->makeTeamProxyDestination();

        $this->makeRetriedSuccess($teamA, $proxyA, $destinationA);
        $this->makeRetriedSuccess($teamB, $proxyB, $destinationB);

        $rows = $this->statistics->proxyBreakdown($teamA->id, AnalyticsWindow::ThirtyDays);

        $this->assertCount(1, $rows);
        $this->assertSame($proxyA->id, $rows[0]->id);
        $this->assertSame(1, $rows[0]->delivery->total);
    }

    public function test_destination_breakdown_ignores_a_second_teams_identical_traffic(): void
    {
        ['team' => $teamA, 'proxy' => $proxyA, 'destination' => $destinationA] = $this->makeTeamProxyDestination();
        ['team' => $teamB, 'proxy' => $proxyB, 'destination' => $destinationB] = $this->makeTeamProxyDestination();

        $this->makeRetriedSuccess($teamA, $proxyA, $destinationA);
        $this->makeRetriedSuccess($teamB, $proxyB, $destinationB);

        $rows = $this->statistics->destinationBreakdown($proxyA, AnalyticsWindow::ThirtyDays);

        $this->assertCount(1, $rows);
        $this->assertSame($destinationA->id, $rows[0]->id);
        $this->assertSame(1, $rows[0]->delivery->total);
    }

    public function test_a_simple_proxys_retry_figures_are_counted_not_gated_out(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeTeamProxyDestination([
            'mode' => ProxyMode::Simple,
        ]);

        $this->makeRetriedSuccess($team, $proxy, $destination);

        $panel = $this->statistics->forProxy($proxy, AnalyticsWindow::ThirtyDays);

        $this->assertSame(1, $panel->retryReplay->eventualSuccess);
        $this->assertSame(1, $panel->retryReplay->retryVolume);
    }

    public function test_fifo_and_async_proxies_produce_figures_through_the_identical_path(): void
    {
        ['team' => $asyncTeam, 'proxy' => $asyncProxy, 'destination' => $asyncDestination] = $this->makeTeamProxyDestination([
            'processing_mode' => ProcessingMode::Async,
        ]);
        ['team' => $fifoTeam, 'proxy' => $fifoProxy, 'destination' => $fifoDestination] = $this->makeTeamProxyDestination([
            'processing_mode' => ProcessingMode::Fifo,
        ]);

        $this->makeRetriedSuccess($asyncTeam, $asyncProxy, $asyncDestination);
        $this->makeRetriedSuccess($fifoTeam, $fifoProxy, $fifoDestination);

        $asyncPanel = $this->statistics->forProxy($asyncProxy, AnalyticsWindow::ThirtyDays);
        $fifoPanel = $this->statistics->forProxy($fifoProxy, AnalyticsWindow::ThirtyDays);

        $this->assertSame($asyncPanel->delivery->total, $fifoPanel->delivery->total);
        $this->assertSame($asyncPanel->attempt->total, $fifoPanel->attempt->total);
        $this->assertSame($asyncPanel->retryReplay->eventualSuccess, $fifoPanel->retryReplay->eventualSuccess);
    }

    public function test_the_service_never_reads_the_mode_or_processing_mode_columns(): void
    {
        $source = file_get_contents(app_path('Services/DeliveryStatistics.php'));
        $this->assertIsString($source);

        // Strip comments and doc-blocks (which may discuss the invariant in prose, e.g.
        // this very test's own name) so only executable code is checked.
        $codeOnly = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString("'mode'", $codeOnly);
        $this->assertStringNotContainsString('"mode"', $codeOnly);
        $this->assertStringNotContainsString('->mode', $codeOnly);
        $this->assertStringNotContainsString('processing_mode', $codeOnly);
    }
}
