<?php

namespace App\Data\Analytics;

/**
 * One densified day in the daily trend series (AC16; plan-11 § Windowing).
 * Every calendar day in the window gets a point — a day with no traffic
 * carries zero counts and a `null` rate (via each `UnitFigure`), never a gap.
 */
readonly class SeriesPoint
{
    public function __construct(
        public string $date,
        public UnitFigure $delivery,
        public UnitFigure $attempt,
    ) {
        //
    }
}
