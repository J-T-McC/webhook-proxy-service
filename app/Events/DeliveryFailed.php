<?php

namespace App\Events;

use App\Models\DeliveryAttempt;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a delivery attempt fails (non-2xx or thrown transport error).
 * No listeners are registered at item #1 — this is a seam for #6 retry / #13
 * notifications (ADR-003).
 */
class DeliveryFailed
{
    use Dispatchable;

    public function __construct(public readonly DeliveryAttempt $attempt) {}
}
