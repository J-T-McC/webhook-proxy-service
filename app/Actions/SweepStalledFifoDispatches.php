<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Models\FifoDispatch;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The FIFO liveness net (ADR-005 (b), ADR-011 Decision 2; ADR-016 Decision 4).
 * Scheduled every minute (routes/console.php). In order:
 *  (a) Orphaned-claim reaper — resets every `claimed` row whose lease has expired
 *      (a worker died mid-event) back to `pending`, clearing claim/lease timestamps.
 *      An unexpired claim is left untouched. An `awaiting_retry` row has no lease
 *      and is structurally invisible to this pass — never reaped.
 *  (b) Idle-proxy nudge — for each distinct proxy with ≥1 `pending` row, no live
 *      claim, no `awaiting_retry` row (held, not idle), AND not paused (item #15,
 *      Q-15-01(1) — a paused proxy is held by the pause, not idle), dispatches
 *      exactly one {@see AdvanceProxyFifoQueue} to restart its line (covers a
 *      self-dispatch that was dropped by the WithoutOverlapping reducer).
 *  (c) Stuck-hold release — an `awaiting_retry` row whose dispatch has zero
 *      non-terminal `deliveries` left (the crash window between a
 *      `DeliverToDestination` execution — attempt 1 or a retry, ADR-020 Decision
 *      1/2 — settling the last open delivery and the fifo-row transition it
 *      would normally trigger, T17) is compare-and-set to `settled` and the
 *      advancer nudged, closing that window.
 *
 * Correctness still rests on the atomic claim in AdvanceProxyFifoQueue — this only
 * guarantees liveness, never ordering.
 */
class SweepStalledFifoDispatches
{
    use AsAction;

    public function handle(): void
    {
        $now = now();

        // (a) Reap orphaned claims: a claimed row past its lease -> pending.
        FifoDispatch::query()
            ->where('status', FifoDispatchStatus::Claimed)
            ->where('lease_expires_at', '<', $now)
            ->update([
                'status' => FifoDispatchStatus::Pending,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]);

        // (b) Nudge idle proxies: distinct proxy_id with >=1 pending row, no live
        // claim, and no held (awaiting_retry) row. One dispatch per proxy (not per
        // pending row). Item #15, Q-15-01(1): a paused proxy sits in exactly this
        // shape (pending, no claim, no hold) and must NOT be treated as idle —
        // without this exclusion the nudge would silently lift the pause within
        // one tick.
        $proxyIds = FifoDispatch::query()
            ->where('status', FifoDispatchStatus::Pending)
            ->whereNotIn('proxy_id', function ($query): void {
                $query->select('id')->from('proxies')->whereNotNull('paused_at');
            })
            ->whereNotIn('proxy_id', function ($query) use ($now): void {
                $query->select('proxy_id')
                    ->from('fifo_dispatches')
                    ->where(function ($busy) use ($now): void {
                        $busy
                            ->where(function ($liveClaim) use ($now): void {
                                $liveClaim
                                    ->where('status', FifoDispatchStatus::Claimed->value)
                                    ->where('lease_expires_at', '>', $now);
                            })
                            ->orWhere('status', FifoDispatchStatus::AwaitingRetry->value);
                    });
            })
            ->distinct()
            ->pluck('proxy_id');

        foreach ($proxyIds as $proxyId) {
            AdvanceProxyFifoQueue::dispatch((int) $proxyId);
        }

        // (c) Release stuck holds: an awaiting_retry row whose dispatch's deliveries
        // are all terminal — the settler crashed after settling the last delivery
        // but before transitioning the fifo row (T17's normal completion path).
        $stuckHolds = FifoDispatch::query()
            ->where('status', FifoDispatchStatus::AwaitingRetry)
            ->whereNotIn('dispatch_uuid', function ($query): void {
                $query->select('dispatch_uuid')
                    ->from('deliveries')
                    ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Retrying->value]);
            })
            ->get(['id', 'proxy_id', 'dispatch_uuid']);

        foreach ($stuckHolds as $stuckHold) {
            $affected = FifoDispatch::query()
                ->whereKey($stuckHold->id)
                ->where('status', FifoDispatchStatus::AwaitingRetry)
                ->update(['status' => FifoDispatchStatus::Settled, 'settled_at' => $now]);

            if ($affected > 0) {
                AdvanceProxyFifoQueue::dispatch($stuckHold->proxy_id);
            }
        }
    }
}
