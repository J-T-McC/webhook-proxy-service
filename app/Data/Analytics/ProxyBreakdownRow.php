<?php

namespace App\Data\Analytics;

/**
 * One row of the Dashboard's per-proxy breakdown table (AC6, AC15; plan-11
 * § Architecture A, `Q-11-03(2)`/`Q-11-03(9)`). `canDrillThrough` is `false`
 * iff the proxy is soft-deleted — a fact about the route, not a permission.
 */
readonly class ProxyBreakdownRow
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDeleted,
        public UnitFigure $delivery,
        public UnitFigure $attempt,
        public int $terminalFailures,
        public bool $canDrillThrough,
    ) {
        //
    }
}
