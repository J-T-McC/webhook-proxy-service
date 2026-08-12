<?php

namespace App\Enums;

/**
 * Lifecycle status of a `deliveries` row (ADR-015 Decision 1). Transitions
 * only by compare-and-set, keyed on the prior status — a zero-row CAS means
 * another settler already won. `Succeeded`/`Failed` are terminal; `Failed` is
 * reached only once `attempt_number` has reached the resolved retry limit.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Whether this status is terminal (no further attempts will be made).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            self::Pending, self::Retrying => false,
        };
    }
}
