<?php

namespace App\Data\Analytics;

/**
 * One densified bucket in the trend series (AC16; plan-11 § Windowing;
 * Amendment B(i)) — hourly on the `24h` window, daily on `7d`/`30d`. A
 * bucket with no traffic carries zero counts and a `null` rate (via each
 * `UnitFigure`), never a gap.
 *
 * `bucketStart` and `date` have two different jobs and are deliberately not
 * merged (plan-11 § API):
 * - `bucketStart` names the period the point covers (AC8) and is present at
 *   both bucket sizes — the single display anchor for the trend table's
 *   first column, the chart's axis label, and the row key.
 * - `date` is the day-drill-through query parameter value and nothing else
 *   (plan-11 Technical ruling 10) — the point's own `Y-m-d` string at a day
 *   bucket, `null` at an hourly bucket. Its nullability is how the hourly
 *   drill-through is suppressed (Technical ruling 13; Amendment B(ii)): a
 *   row builds a link when and only when it has a `date`.
 */
readonly class SeriesPoint
{
    public function __construct(
        public string $bucketStart,
        public ?string $date,
        public UnitFigure $delivery,
        public UnitFigure $attempt,
    ) {
        //
    }
}
