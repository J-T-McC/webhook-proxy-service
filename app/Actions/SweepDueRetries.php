<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Services\RetryPolicy;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The retry liveness net (ADR-015 Decision 5) — the sweeper half of the same
 * belt/suspenders/dedupe pattern already proven by
 * {@see SweepStalledFifoDispatches} against {@see AdvanceProxyFifoQueue}.
 * Scheduled every minute (`routes/console.php`), beside the FIFO sweeper.
 *
 * Re-dispatches {@see RetryDelivery} for every `retrying` delivery whose
 * `next_attempt_at` passed more than {@see RetryPolicy::sweepGraceSeconds()}
 * ago — i.e. its delayed job was dropped or lost. The next attempt number is
 * derived fresh from that delivery's own attempt rows (`MAX(attempt_number) +
 * 1`), never assumed from `next_attempt_at`'s own scheduling context. A
 * double-fire against a still-live delayed job for the same `(delivery,
 * attempt_number)` is arbitrated by the `UNIQUE(delivery_id,
 * attempt_number)` create-or-resume key (T5/T10) inside
 * `DeliverToDestination`, exactly as the FIFO sweeper's nudge is arbitrated
 * by `AdvanceProxyFifoQueue`'s atomic claim — no dedupe logic lives here.
 *
 * Item #15 (pause and resume dispatch), Q-15-01(3): a retry is a dispatch, so
 * a paused proxy's overdue retries are excluded from the per-minute sweep —
 * they must not spend an attempt they did not make (PRD-15 AC19). Retry
 * counts/schedules/limits are untouched; the retry simply does not fire while
 * paused and is picked up again once resumed, either by the next sweep or by
 * {@see forProxy()} called immediately on resume (AC4 — no waiting for the
 * next tick).
 */
class SweepDueRetries
{
    use AsAction;

    public function handle(): void
    {
        $cutoff = now()->subSeconds(app(RetryPolicy::class)->sweepGraceSeconds());

        $this->dispatchOverdue(
            $this->overdueQuery()
                ->where('next_attempt_at', '<', $cutoff)
                ->whereNotIn('proxy_id', function ($query): void {
                    $query->select('id')->from('proxies')->whereNotNull('paused_at');
                }),
        );
    }

    /**
     * Immediate, proxy-scoped counterpart to {@see handle()}: on resume,
     * dispatch this proxy's already-overdue retries right away rather than
     * waiting for the next per-minute sweep (AC4 parity with the FIFO
     * advancer's immediate re-dispatch). No `paused_at` filter needed — the
     * caller only invokes this once the proxy is no longer paused.
     */
    public function forProxy(int $proxyId): void
    {
        $this->dispatchOverdue(
            $this->overdueQuery()
                ->where('proxy_id', $proxyId)
                ->where('next_attempt_at', '<=', now()),
        );
    }

    /**
     * @return Builder<Delivery>
     */
    private function overdueQuery(): Builder
    {
        // Item #18: no validation filter here, deliberately (review-18
        // finding 9). A now-unvalidated destination's overdue row must be
        // PICKED UP so the worker's dispatch-gate can resolve it as terminal
        // `Skipped` (AC10 — skipped, not held): excluding it would park the
        // row as `Retrying` forever, and deliver it later if the destination
        // is ever re-validated — events from while it was unvalidated, which
        // AC10 forbids. Nothing reaches the network either way; the gate in
        // `DeliverToDestination` is what refuses the send.
        return Delivery::query()
            ->where('status', DeliveryStatus::Retrying);
    }

    /**
     * @param  Builder<Delivery>  $query
     */
    private function dispatchOverdue(Builder $query): void
    {
        foreach ($query->get() as $delivery) {
            $nextAttemptNumber = (DeliveryAttempt::query()
                ->where('delivery_id', $delivery->id)
                ->max('attempt_number') ?? 0) + 1;

            RetryDelivery::dispatch($delivery->id, $nextAttemptNumber);
        }
    }
}
