<?php

namespace App\Data\Analytics;

/**
 * Average and 95th-percentile duration over resolved attempts'
 * `duration_ms` (AC12, AC20; plan-11 Technical rulings 4 and 5). Both
 * `averageMs` and `p95Ms` are `null` when `sampleCount === 0` — the surface
 * reads "No data", never `0 ms`. `p95Ms` is also `null` at destination grain
 * by construction (Amendment A(ii)) — no query is issued there.
 */
readonly class LatencyFigure
{
    public function __construct(
        public ?int $averageMs,
        public ?int $p95Ms,
        public int $sampleCount,
    ) {
        //
    }
}
