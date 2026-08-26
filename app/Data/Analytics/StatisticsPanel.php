<?php

namespace App\Data\Analytics;

use App\Enums\AnalyticsWindow;
use App\Enums\SeriesBucket;

/**
 * The full figure set for one grain (team or proxy) over one window
 * (plan-11 § API "Prop shapes"). Assembled by
 * `App\Services\DeliveryStatistics::forTeam()`/`forProxy()`.
 */
readonly class StatisticsPanel
{
    /**
     * `bucket` is `$window->bucket()`'s value (Technical ruling 11) — it
     * exists for labelling and axis formatting only. It is **not**, and
     * must not become, the hourly-link gate; two signals for one decision
     * is how they drift apart, and `SeriesPoint.date` is the only signal a
     * consumer may read for that decision (Technical ruling 13).
     *
     * @param  list<SeriesPoint>  $series
     */
    public function __construct(
        public AnalyticsWindow $window,
        public SeriesBucket $bucket,
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
