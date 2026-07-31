<?php

namespace App\Events;

use App\Models\DeliveryAttempt;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a delivery attempt completes with a 2xx response (ADR-003).
 * No listeners are registered at item #1 — this is a seam.
 */
class DeliverySucceeded
{
    use Dispatchable;

    public function __construct(public readonly DeliveryAttempt $attempt) {}
}
