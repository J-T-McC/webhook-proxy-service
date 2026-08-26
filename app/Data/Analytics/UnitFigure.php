<?php

namespace App\Data\Analytics;

/**
 * A success/failure figure for one unit (delivery- or attempt-grain) at one
 * grain (team / proxy / destination). `rate` is `null` when `total === 0`
 * (Amendment A(i); plan-11 Technical ruling 6) — never `0`. Counts are
 * always integers and always rendered, including `0`.
 */
readonly class UnitFigure
{
    public function __construct(
        public int $succeeded,
        public int $failed,
        public int $total,
        public ?float $rate,
    ) {
        //
    }
}
