<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Models\Delivery;
use App\Models\FifoDispatch;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The FIFO single-advancer for one proxy (ADR-011 Decision 2, ADR-005 (a);
 * ADR-016 Decisions 1/3).
 *
 * Guarantees at most one in-flight event per proxy via an atomic `FOR UPDATE`
 * claim (the FIFO correctness primitive): inside a short transaction it checks for a
 * live claim OR a held (`awaiting_retry`) row, scans the lowest-`id` pending row, and
 * flips it to `claimed` under a row lock. The outbound delivery runs OUTSIDE that
 * transaction — the row lock is never held across a network send. After the claimed
 * dispatch's run completes it settles the row only when every one of the dispatch's
 * `deliveries` has reached a terminal state; otherwise it holds the line
 * (`claimed → awaiting_retry`, no lease) for the dispatch's in-progress retry
 * schedule (ADR-015) and does not self-dispatch — a `RetryDelivery` execution or the
 * sweeper's stuck-hold pass settles it and nudges the advancer instead.
 *
 * `WithoutOverlapping` job middleware is a thundering-herd reducer only — NOT the
 * ordering/dedupe guard (that is the atomic claim). Under real (async) workers a
 * redundant advancer that loses the lock is simply dropped; the self-dispatch chain
 * and the sweeper (SweepStalledFifoDispatches) keep the line live.
 */
class AdvanceProxyFifoQueue
{
    use AsAction;

    public function handle(int $proxyId): void
    {
        $claimed = $this->claimNext($proxyId);

        if ($claimed === null) {
            // No pending row, or another advancer already holds a live claim, or the
            // line is held by an awaiting_retry row.
            return;
        }

        // OUTSIDE the claim transaction (ADR-005 (a)): never hold the row lock across
        // the outbound HTTP send. The proxy is FIFO, so DeliverStep runs delivery
        // inline and this returns only once the whole event has been delivered
        // (attempt 1 of every destination's delivery).
        ProcessIngestedWebhook::run($claimed->webhookEvent->ingest_id, $claimed->dispatch_uuid);

        $this->settleOrHold($claimed, $proxyId);
    }

    /**
     * Atomically claim the lowest-`id` pending row for the proxy, or return null
     * when a live claim or a held (`awaiting_retry`) row already exists, or nothing
     * is pending. The busy check, the lowest-pending scan, and the status flip all
     * run under `lockForUpdate` inside one short transaction (ADR-011 / ADR-005 (a);
     * ADR-016 Decision 1's widened busy-gate).
     */
    private function claimNext(int $proxyId): ?FifoDispatch
    {
        return DB::transaction(function () use ($proxyId): ?FifoDispatch {
            $busy = FifoDispatch::query()
                ->where('proxy_id', $proxyId)
                ->where(function ($query): void {
                    $query
                        ->where(function ($liveClaim): void {
                            $liveClaim
                                ->where('status', FifoDispatchStatus::Claimed)
                                ->where('lease_expires_at', '>', now());
                        })
                        ->orWhere('status', FifoDispatchStatus::AwaitingRetry);
                })
                ->lockForUpdate()
                ->first();

            if ($busy !== null) {
                return null;
            }

            $next = FifoDispatch::query()
                ->where('proxy_id', $proxyId)
                ->where('status', FifoDispatchStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($next === null) {
                return null;
            }

            $next->update([
                'status' => FifoDispatchStatus::Claimed,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('ingest.fifo_lease_seconds')),
            ]);

            return $next;
        });
    }

    /**
     * The post-run completion decision (ADR-016 Decision 1): settle the row and
     * advance the line when the dispatch has no non-terminal deliveries left;
     * otherwise hold the line (`claimed → awaiting_retry`, no lease, no
     * self-dispatch) for the dispatch's in-progress retry schedule.
     */
    private function settleOrHold(FifoDispatch $claimed, int $proxyId): void
    {
        $hasNonTerminalDeliveries = Delivery::query()
            ->where('dispatch_uuid', $claimed->dispatch_uuid)
            ->whereNotIn('status', $this->terminalStatuses())
            ->exists();

        if (! $hasNonTerminalDeliveries) {
            $claimed->update([
                'status' => FifoDispatchStatus::Settled,
                'settled_at' => now(),
            ]);

            // Advance to the next pending row for this proxy.
            static::dispatch($proxyId);

            return;
        }

        FifoDispatch::query()
            ->whereKey($claimed->id)
            ->where('status', FifoDispatchStatus::Claimed)
            ->update([
                'status' => FifoDispatchStatus::AwaitingRetry,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]);
    }

    /**
     * The `DeliveryStatus` cases considered terminal, per {@see DeliveryStatus::isTerminal()}.
     *
     * @return array<int, DeliveryStatus>
     */
    private function terminalStatuses(): array
    {
        return array_values(array_filter(
            DeliveryStatus::cases(),
            fn (DeliveryStatus $status): bool => $status->isTerminal(),
        ));
    }

    /**
     * Thundering-herd reducer keyed per proxy — NOT the ordering guard (ADR-011).
     *
     * The lock is given an explicit TTL equal to the FIFO claim lease
     * (`ingest.fifo_lease_seconds`), the same value the sweeper uses to detect a
     * stalled claim. Without a TTL (framework default `expiresAfter = 0` → no
     * expiry) an ungraceful worker crash (SIGKILL/OOM) while an advancer holds the
     * lock leaks the key permanently: `SweepStalledFifoDispatches` reaps the DB claim
     * and re-dispatches the advancer, but the re-dispatched job can never reacquire
     * the leaked lock and re-queues forever — the proxy's FIFO line never advances.
     *
     * Aligning the lock TTL to the lease makes the leaked lock self-heal no later
     * than the claim it guarded: the lock is acquired a moment BEFORE the claim's
     * `lease_expires_at` is stamped, so an equal TTL expires at or before the lease,
     * i.e. always before the sweeper (which waits for the lease to expire) reaps and
     * re-drives the line. A TTL longer than the lease would re-open the deadlock
     * window; equal to the lease is the correct upper bound (ADR-011 liveness
     * guardrail (b), plan-04 §Services).
     *
     * @return array<int, object>
     */
    public function getJobMiddleware(int $proxyId): array
    {
        return [
            (new WithoutOverlapping("proxy:{$proxyId}"))
                ->expireAfter((int) config('ingest.fifo_lease_seconds')),
        ];
    }
}
