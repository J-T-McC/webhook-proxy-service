<?php

namespace App\Events;

use App\Models\DeliveryAttempt;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a delivery attempt row is written (before the HTTP outcome is
 * known). No listeners are registered at item #1 — this is a seam (ADR-003).
 */
class DeliveryAttempted
{
    use Dispatchable;

    public function __construct(public readonly DeliveryAttempt $attempt) {}
}
