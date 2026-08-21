<?php

namespace App\Enums;

/**
 * FIFO ordering-row claim lifecycle (ADR-011 Decision 2, ADR-016 Decision 3).
 * A `fifo_dispatches` row is `pending` when captured, `claimed` while an
 * advancer holds its lease, `awaiting_retry` while its dispatch has at least
 * one non-terminal delivery after the claimed run completes (no lease — held,
 * not leased), and `settled` once every delivery for its dispatch has reached
 * a terminal state.
 */
enum FifoDispatchStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Settled = 'settled';
    case AwaitingRetry = 'awaiting_retry';
}
