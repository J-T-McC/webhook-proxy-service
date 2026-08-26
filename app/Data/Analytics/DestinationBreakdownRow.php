<?php

namespace App\Data\Analytics;

/**
 * One row of the proxy Show page's per-destination breakdown table (AC6,
 * AC15; plan-11 § Architecture A, `Q-11-03(2)`). Row set is the union of the
 * proxy's live destinations and every `destination_id` with activity in the
 * window.
 */
readonly class DestinationBreakdownRow
{
    public function __construct(
        public int $id,
        public string $url,
        public string $httpMethod,
        public bool $isDeleted,
        public UnitFigure $delivery,
        public UnitFigure $attempt,
        public ?int $latencyAverageMs,
    ) {
        //
    }
}
