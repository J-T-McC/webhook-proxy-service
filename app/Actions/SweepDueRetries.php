<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The retry liveness net (ADR-015 Decision 5) — the sweeper half of the same
 * belt/suspenders/dedupe pattern already proven by
 * {@see SweepStalledFifoDispatches} against {@see AdvanceProxyFifoQueue}.
 * Scheduled every minute (`routes/console.php`), beside the FIFO sweeper.
 *
 * Re-dispatches {@see RetryDelivery} for every `retrying` delivery whose
 * `next_attempt_at` passed more than `config('retry.sweep_grace_seconds')`
 * ago — i.e. its delayed job was dropped or lost. The next attempt number is
 * derived fresh from that delivery's own attempt rows (`MAX(attempt_number) +
 * 1`), never assumed from `next_attempt_at`'s own scheduling context. A
 * double-fire against a still-live delayed job for the same `(delivery,
 * attempt_number)` is arbitrated by the `UNIQUE(delivery_id,
 * attempt_number)` create-or-resume key (T5/T10) inside
 * `DeliverToDestination`, exactly as the FIFO sweeper's nudge is arbitrated
 * by `AdvanceProxyFifoQueue`'s atomic claim — no dedupe logic lives here.
 */
class SweepDueRetries
{
    use AsAction;

    public function handle(): void
    {
        $cutoff = now()->subSeconds((int) config('retry.sweep_grace_seconds'));

        $overdue = Delivery::query()
            ->where('status', DeliveryStatus::Retrying)
            ->where('next_attempt_at', '<', $cutoff)
            ->get();

        foreach ($overdue as $delivery) {
            $nextAttemptNumber = (DeliveryAttempt::query()
                ->where('delivery_id', $delivery->id)
                ->max('attempt_number') ?? 0) + 1;

            RetryDelivery::dispatch($delivery->id, $nextAttemptNumber);
        }
    }
}
