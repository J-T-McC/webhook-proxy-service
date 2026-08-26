<?php

namespace App\Data\Analytics;

use App\Enums\AnalyticsWindow;

/**
 * The full figure set for one grain (team or proxy) over one window
 * (plan-11 § API "Prop shapes"). Assembled by
 * `App\Services\DeliveryStatistics::forTeam()`/`forProxy()`.
 */
readonly class StatisticsPanel
{
    /**
     * @param  list<SeriesPoint>  $series
     */
    public function __construct(
        public AnalyticsWindow $window,
        public UnitFigure $delivery,
        public UnitFigure $attempt,
        public int $bridgeFailedAttempts,
        public RetryReplayFigures $retryReplay,
        public LatencyFigure $latency,
        public array $series,
        public bool $hasTraffic,
    ) {
        //
    }
}
