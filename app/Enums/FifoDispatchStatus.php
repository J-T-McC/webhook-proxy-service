<?php

namespace App\Enums;

/**
 * FIFO ordering-row claim lifecycle (ADR-011 Decision 2). A `fifo_dispatches`
 * row is `pending` when captured, `claimed` while an advancer holds its lease,
 * and `settled` once its event has been fully delivered. `dead_lettered` is a
 * #6 (retry/replay) addition and is deliberately NOT modelled here.
 */
enum FifoDispatchStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Settled = 'settled';
}
