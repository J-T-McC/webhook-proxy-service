<?php

namespace App\Actions;

use App\Enums\FifoDispatchStatus;
use App\Models\FifoDispatch;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The FIFO liveness net (ADR-005 (b), ADR-011 Decision 2). Scheduled every minute
 * (routes/console.php). In order:
 *  (a) Orphaned-claim reaper — resets every `claimed` row whose lease has expired
 *      (a worker died mid-event) back to `pending`, clearing claim/lease timestamps.
 *      An unexpired claim is left untouched.
 *  (b) Idle-proxy nudge — for each distinct proxy with ≥1 `pending` row and no live
 *      claim, dispatches exactly one {@see AdvanceProxyFifoQueue} to restart its line
 *      (covers a self-dispatch that was dropped by the WithoutOverlapping reducer).
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

        // (b) Nudge idle proxies: distinct proxy_id with >=1 pending row and no live
        // claim. One dispatch per proxy (not per pending row).
        $proxyIds = FifoDispatch::query()
            ->where('status', FifoDispatchStatus::Pending)
            ->whereNotIn('proxy_id', function ($query) use ($now): void {
                $query->select('proxy_id')
                    ->from('fifo_dispatches')
                    ->where('status', FifoDispatchStatus::Claimed->value)
                    ->where('lease_expires_at', '>', $now);
            })
            ->distinct()
            ->pluck('proxy_id');

        foreach ($proxyIds as $proxyId) {
            AdvanceProxyFifoQueue::dispatch((int) $proxyId);
        }
    }
}
