<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Models\Delivery;
use App\Models\FifoDispatch;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

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
        // the outbound HTTP send. This runs the pipeline in-process, but DeliverStep's
        // terminal step only DISPATCHES each destination's delivery by reference onto
        // the webhooks queue (ADR-020 Decision 1) — it does not deliver inline. So this
        // call returns once the event's deliveries have been created and enqueued, not
        // once they have settled. Settlement is decided below by `settleOrHold()`,
        // which is what actually determines whether the line is free to advance.
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
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
            ]);

            return $next;
        });
    }

    /**
     * The post-run completion decision (ADR-016 Decision 1, ADR-020 Decision 3):
     * settle the row and advance the line when the dispatch has no non-terminal
     * deliveries left; otherwise hold the line for the dispatch's in-progress
     * retry schedule.
     *
     * Race-safe under parallel fan-out (ADR-020 Decision 3): the hold is
     * published BEFORE the re-check. `settleFifoLineIfComplete()`
     * (`DeliverToDestination`) only ever settles a row it finds in
     * `awaiting_retry`, so a delivery that settles while this row is still
     * `claimed` cannot advance the line — the re-check below is what covers that
     * instant. Publishing the hold first is what makes the window airtight
     * rather than merely narrower; the two steps are not interchangeable in the
     * other order. The settle path itself is a compare-and-set keyed on the
     * expected prior status (never a blind update by primary key), and the
     * advance is dispatched only if the compare-and-set affected a row — so a
     * stale advancer can neither settle a row another advancer holds nor
     * double-advance the line.
     */
    private function settleOrHold(FifoDispatch $claimed, int $proxyId): void
    {
        if (! $this->hasNonTerminalDeliveries($claimed->dispatch_uuid)) {
            $this->settleAndAdvance($claimed->id, FifoDispatchStatus::Claimed, $proxyId);

            return;
        }

        // Publish the hold BEFORE re-checking. `settleFifoLineIfComplete` settles
        // only a row it finds in `awaiting_retry`, so a delivery that settles while
        // this row is still `claimed` cannot advance the line — the re-check below
        // is what covers that instant. Ordering the two the other way round leaves
        // the same gap it is meant to close.
        $held = FifoDispatch::query()
            ->whereKey($claimed->id)
            ->where('status', FifoDispatchStatus::Claimed)
            ->update([
                'status' => FifoDispatchStatus::AwaitingRetry,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]) > 0;

        if (! $held) {
            return;
        }

        if (! $this->hasNonTerminalDeliveries($claimed->dispatch_uuid)) {
            $this->settleAndAdvance($claimed->id, FifoDispatchStatus::AwaitingRetry, $proxyId);
        }
    }

    /**
     * Whether the dispatch identified by `$dispatchUuid` still has a non-terminal
     * delivery. Queried fresh on each call — deliberately impure: the whole point
     * of `settleOrHold()`'s re-check (ADR-020 Decision 3) is that this can change
     * between the two calls, as another delivery settles concurrently.
     *
     * @phpstan-impure
     */
    private function hasNonTerminalDeliveries(string $dispatchUuid): bool
    {
        return Delivery::query()
            ->where('dispatch_uuid', $dispatchUuid)
            ->whereNotIn('status', $this->terminalStatuses())
            ->exists();
    }

    /**
     * Settle `$id` by compare-and-set keyed on `$from`, and dispatch the next
     * advance only if the compare-and-set affected a row — so a stale advancer
     * (one whose row has already moved on under it) can neither settle nor
     * double-advance the line.
     */
    private function settleAndAdvance(int $id, FifoDispatchStatus $from, int $proxyId): void
    {
        $affected = FifoDispatch::query()
            ->whereKey($id)
            ->where('status', $from)
            ->update(['status' => FifoDispatchStatus::Settled, 'settled_at' => now()]);

        if ($affected > 0) {
            static::dispatch($proxyId);
        }
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
     * `->dontRelease()` (ADR-020 Decision 6): without it, `$releaseAfter` defaults
     * to `0`, so a redundant advancer that loses the lock is released back onto
     * the queue immediately and then fails `MaxAttemptsExceeded` under the
     * supervisor's `tries => 1`, landing in `failed_jobs`. No payload is exposed
     * either way (this job carries only an integer proxy id) and liveness is
     * unaffected — the self-dispatch chain and the sweeper keep the line live
     * regardless — but this is what makes "a redundant advancer that loses the
     * lock is simply dropped" (this docblock, and `config/horizon.php`'s
     * production comment) actually true rather than aspirational.
     *
     * @return array<int, object>
     */
    public function getJobMiddleware(int $proxyId): array
    {
        return [
            (new WithoutOverlapping("proxy:{$proxyId}"))
                ->expireAfter($this->leaseSeconds())
                ->dontRelease(),
        ];
    }

    /**
     * The fail-loud reader for `ingest.fifo_lease_seconds` (ADR-020 Decision 5,
     * following `RetryPolicy::positiveConfigInt()`) — the ONLY place this key is
     * read. A blank, zero, negative or non-numeric value is uniquely destructive
     * here: it would make `lease_expires_at` resolve to `now()` (every claim
     * instantly reapable) AND `WithoutOverlapping::expireAfter(0)` mean no lock
     * expiry at all (`Illuminate\Cache\RedisLock`), leaking the per-proxy lock
     * permanently on an ungraceful worker crash — exactly the deadlock this
     * class's own docblock warns about. Refuses to silently substitute a
     * default.
     *
     * @throws RuntimeException
     */
    private function leaseSeconds(): int
    {
        $value = (int) config('ingest.fifo_lease_seconds');

        if ($value < 1) {
            throw new RuntimeException(sprintf(
                "config('ingest.fifo_lease_seconds') must resolve to a positive integer; got %d. Refusing to ".
                'silently substitute a default.',
                $value,
            ));
        }

        return $value;
    }
}
