<?php

namespace App\Actions;

use App\Models\Delivery;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStep;
use Closure;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The terminal fan-out step (ADR-001/011/015/020). Iterates the dispatch's
 * `deliveries` rows (`dispatch_uuid = $ctx->dispatchUuid`, one per destination —
 * created ahead of the pipeline run, T8) instead of `$proxy->destinations`
 * directly.
 *
 * Every delivery, in both Async and FIFO modes, is dispatched **by reference**
 * onto the dedicated webhooks queue (ADR-020 Decision 1/7): only the delivery's
 * `id` and attempt number 1 travel in the job's arguments — no payload bytes, no
 * headers, no destination model. `DeliverToDestination` resolves everything else
 * on the worker via the shared `DeliveryUnitResolver` (ADR-013's divergence-gated
 * dispatched-output store makes that resolution total). This is what makes an
 * `AdvanceProxyFifoQueue` job bounded by local database/CPU work rather than by
 * N remote HTTP sends (ADR-020 §Question) — FIFO ordering is unaffected because
 * it is enforced between events, never between destinations within one event
 * (ADR-020's guarantee, points 1–2).
 *
 * One destination failing/erroring never aborts the loop (AC10) — a dispatch is
 * fire-and-forget, and `DeliverToDestination` catches its own transport errors.
 * This step only READS `$ctx->payload` indirectly, via `$ctx->dispatchUuid`; it
 * builds no `DeliveryUnit`s itself.
 */
class DeliverStep implements PipelineStep
{
    use AsObject;

    /**
     * @param  Closure(PipelineContext): PipelineContext  $next
     */
    public function handle(PipelineContext $ctx, Closure $next): PipelineContext
    {
        Delivery::query()
            ->where('dispatch_uuid', $ctx->dispatchUuid)
            ->pluck('id')
            ->each(function (int $deliveryId): void {
                DeliverToDestination::dispatch($deliveryId, 1)
                    ->onQueue(config('ingest.webhooks_queue'))
                    ->afterCommit();
            });

        return $next($ctx);
    }
}
