<?php

namespace Tests\Feature\Analytics;

use App\Actions\PurgeExpiredPayloads;
use App\Enums\AnalyticsWindow;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\WebhookEvent;
use App\Services\DeliveryStatistics;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Proves #11's central premise end to end: statistics survive and are
 * unaffected by payload expiry, and #11's own read path writes nothing
 * (AC1-AC5; plan § Test strategy "Separation and lifecycle"). No production
 * code changes in this task — pure integration coverage over the finished
 * T2-T9 service.
 */
class AnalyticsSeparationTest extends TestCase
{
    private DeliveryStatistics $statistics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statistics = new DeliveryStatistics;
    }

    /**
     * @return array{team: Team, proxy: Proxy, destination: Destination, event: WebhookEvent}
     */
    private function makeAgedFixture(): array
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->state(['team_id' => $team->id])->createQuietly();
        $destination = Destination::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
        ])->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $team->id,
            'created_at' => now()->subDays(31),
        ]);

        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'kind' => DispatchKind::Original,
            'status' => DeliveryStatus::Succeeded,
        ]);

        DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery->id,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Failed,
            'duration_ms' => 80,
        ])->createQuietly();

        DeliveryAttempt::factory()->state([
            'delivery_id' => $delivery->id,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 2,
            'status' => AttemptStatus::Succeeded,
            'duration_ms' => 120,
        ])->createQuietly();

        return ['team' => $team, 'proxy' => $proxy, 'destination' => $destination, 'event' => $event];
    }

    /**
     * @return array{
     *     unitTeam: array{delivery: object, attempt: object},
     *     unitProxy: array{delivery: object, attempt: object},
     *     unitDestination: array{delivery: object, attempt: object},
     *     latencyTeam: object,
     *     latencyProxy: object,
     *     latencyDestination: object,
     *     series: array<int, object>,
     *     retryReplay: array{retryReplay: object, bridgeFailedAttempts: int},
     *     breakdown: array<int, object>,
     *     destinationBreakdown: array<int, object>,
     *     panel: object,
     * }
     */
    private function computeEveryFigure(Team $team, Proxy $proxy, int $destinationId): array
    {
        return [
            'unitTeam' => $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::ThirtyDays),
            'unitProxy' => $this->statistics->unitFiguresForProxy($proxy->id, AnalyticsWindow::ThirtyDays),
            'unitDestination' => $this->statistics->unitFiguresForDestination($proxy->id, $destinationId, AnalyticsWindow::ThirtyDays),
            'latencyTeam' => $this->statistics->latencyForTeam($team->id, AnalyticsWindow::ThirtyDays),
            'latencyProxy' => $this->statistics->latencyForProxy($proxy->id, AnalyticsWindow::ThirtyDays),
            'latencyDestination' => $this->statistics->latencyForDestination($proxy->id, $destinationId, AnalyticsWindow::ThirtyDays),
            'series' => $this->statistics->seriesForTeam($team->id, AnalyticsWindow::ThirtyDays),
            'retryReplay' => $this->statistics->retryReplayForTeam($team->id, AnalyticsWindow::ThirtyDays),
            'breakdown' => $this->statistics->proxyBreakdown($team->id, AnalyticsWindow::ThirtyDays),
            'destinationBreakdown' => $this->statistics->destinationBreakdown($proxy, AnalyticsWindow::ThirtyDays),
            'panel' => $this->statistics->forTeam($team->id, AnalyticsWindow::ThirtyDays),
        ];
    }

    public function test_ac2_every_figure_is_numerically_identical_before_and_after_purge_expired_payloads(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination, 'event' => $event] = $this->makeAgedFixture();

        $before = $this->computeEveryFigure($team, $proxy, $destination->id);

        PurgeExpiredPayloads::run();

        // The event really was erased — proving this is a real cleanup, not a skip.
        $this->assertNotNull(DB::table('webhook_events')->where('id', $event->id)->value('payload_cleaned_at'));

        $after = $this->computeEveryFigure($team, $proxy, $destination->id);

        $this->assertEquals($before, $after);
    }

    public function test_ac3_a_proxy_whose_events_are_all_cleaned_matches_one_whose_events_are_retained(): void
    {
        $team = Team::factory()->createQuietly();

        $cleanedProxy = Proxy::factory()->state(['team_id' => $team->id])->createQuietly();
        $cleanedDestination = Destination::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $cleanedProxy->id,
        ])->createQuietly();
        $cleanedEvent = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $cleanedProxy->id,
            'team_id' => $team->id,
            'created_at' => now()->subDays(31),
        ]);
        $cleanedDelivery = Delivery::factory()->createQuietly([
            'team_id' => $team->id,
            'proxy_id' => $cleanedProxy->id,
            'destination_id' => $cleanedDestination->id,
            'webhook_event_id' => $cleanedEvent->id,
            'kind' => DispatchKind::Original,
            'status' => DeliveryStatus::Succeeded,
        ]);
        DeliveryAttempt::factory()->state([
            'delivery_id' => $cleanedDelivery->id,
            'team_id' => $team->id,
            'proxy_id' => $cleanedProxy->id,
            'destination_id' => $cleanedDestination->id,
            'ingest_id' => $cleanedEvent->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Succeeded,
        ])->createQuietly();

        $retainedProxy = Proxy::factory()->state(['team_id' => $team->id])->createQuietly();
        $retainedDestination = Destination::factory()->state([
            'team_id' => $team->id,
            'proxy_id' => $retainedProxy->id,
        ])->createQuietly();
        $retainedEvent = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $retainedProxy->id,
            'team_id' => $team->id,
        ]);
        $retainedDelivery = Delivery::factory()->createQuietly([
            'team_id' => $team->id,
            'proxy_id' => $retainedProxy->id,
            'destination_id' => $retainedDestination->id,
            'webhook_event_id' => $retainedEvent->id,
            'kind' => DispatchKind::Original,
            'status' => DeliveryStatus::Succeeded,
        ]);
        DeliveryAttempt::factory()->state([
            'delivery_id' => $retainedDelivery->id,
            'team_id' => $team->id,
            'proxy_id' => $retainedProxy->id,
            'destination_id' => $retainedDestination->id,
            'ingest_id' => $retainedEvent->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Succeeded,
        ])->createQuietly();

        PurgeExpiredPayloads::run();

        $this->assertNotNull(DB::table('webhook_events')->where('id', $cleanedEvent->id)->value('payload_cleaned_at'));
        $this->assertNull(DB::table('webhook_events')->where('id', $retainedEvent->id)->value('payload_cleaned_at'));

        $cleanedPanel = $this->statistics->forProxy($cleanedProxy, AnalyticsWindow::ThirtyDays);
        $retainedPanel = $this->statistics->forProxy($retainedProxy, AnalyticsWindow::ThirtyDays);

        $this->assertEquals($retainedPanel->delivery, $cleanedPanel->delivery);
        $this->assertEquals($retainedPanel->attempt, $cleanedPanel->attempt);

        // Still resolves to its destination in the breakdown.
        $cleanedDestinations = collect($this->statistics->destinationBreakdown($cleanedProxy, AnalyticsWindow::ThirtyDays));
        $this->assertTrue($cleanedDestinations->contains('id', $cleanedDestination->id));
    }

    public function test_ac5_calling_every_public_method_changes_no_row_count_and_no_updated_at(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination, 'event' => $event] = $this->makeAgedFixture();

        DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
        ]);
        FifoDispatch::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
        ]);

        $snapshot = fn () => collect(['deliveries', 'delivery_attempts', 'webhook_events', 'dispatched_payloads', 'fifo_dispatches'])
            ->mapWithKeys(fn (string $table) => [
                $table => DB::table($table)->orderBy('id')->get(['id', 'updated_at'])->toArray(),
            ])
            ->all();

        $before = $snapshot();

        $this->computeEveryFigure($team, $proxy, $destination->id);
        $this->statistics->forProxy($proxy, AnalyticsWindow::ThirtyDays);
        $this->statistics->unitFiguresForTeam($team->id, AnalyticsWindow::SevenDays);
        $this->statistics->unitFiguresForProxy($proxy->id, AnalyticsWindow::TwentyFourHours);
        $this->statistics->seriesForProxy($proxy->id, AnalyticsWindow::SevenDays);
        $this->statistics->retryReplayForProxy($proxy->id, AnalyticsWindow::ThirtyDays);

        $after = $snapshot();

        $this->assertEquals($before, $after);
    }

    public function test_ac1_and_ac4_no_query_reads_webhook_events_body_or_headers_or_touches_the_table_at_all(): void
    {
        ['team' => $team, 'proxy' => $proxy, 'destination' => $destination] = $this->makeAgedFixture();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->computeEveryFigure($team, $proxy, $destination->id);

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('webhook_events', $sql);
            $this->assertStringNotContainsStringIgnoringCase('`body`', $sql);
            $this->assertStringNotContainsStringIgnoringCase('`headers`', $sql);
        }

        // No aggregate hydrates a WebhookEvent model — the class's executable code never
        // references it at all (comments/doc-blocks may still discuss the invariant in
        // prose, e.g. this very assertion's own subject, so they're stripped first).
        $reflection = new ReflectionClass(DeliveryStatistics::class);
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);

        $codeOnly = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('WebhookEvent', $codeOnly);
    }
}
