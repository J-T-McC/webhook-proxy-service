<?php

namespace App\Data\Analytics;

/**
 * The four AC19 retry/replay figures plus the bridge-sentence count (AC19,
 * plan-11 § Architecture A/B). All plain counts — always integers, always
 * rendered, `0` in an empty window, never replaced by "no data".
 */
readonly class RetryReplayFigures
{
    public function __construct(
        public int $eventualSuccess,
        public int $terminalFailure,
        public int $retryVolume,
        public int $live,
        public int $replay,
    ) {
        //
    }
}
