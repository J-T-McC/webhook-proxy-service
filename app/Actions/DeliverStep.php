<?php

namespace App\Actions;

use App\Enums\ProcessingMode;
use App\Models\Delivery;
use App\Pipeline\DeliveryUnit;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStep;
use Closure;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The terminal fan-out step (ADR-001/011/015). Iterates the dispatch's `deliveries`
 * rows (`dispatch_uuid = $ctx->dispatchUuid`, one per destination — created ahead of
 * the pipeline run, T8) instead of `$proxy->destinations` directly, and builds one
 * {@see DeliveryUnit} per row, carrying that row's id. The destination relation is
 * loaded `withTrashed()`: a destination soft-deleted after its delivery row was
 * created still receives its attempt (ruling 2) — trashed-exclusion now happens at
 * *delivery-row creation* (T8), not here. Each unit is then dispatched per the
 * proxy's processing mode (ADR-011):
 *  - **Async** — `DeliverToDestination::dispatch(...)` onto the dedicated webhooks
 *    queue, `afterCommit()`, so destinations fan out in parallel.
 *  - **FIFO** — `DeliverToDestination::run(...)` inline, so the advancing job settles
 *    the whole event before advancing the proxy's line.
 *
 * One destination failing/erroring never aborts the loop in either mode (AC10) —
 * DeliverToDestination catches its own transport errors, and a dispatch is fire-and-
 * forget. This step only READS `$ctx->payload`.
 */
class DeliverStep implements PipelineStep
{
    use AsObject;

    /**
     * @param  Closure(PipelineContext): PipelineContext  $next
     */
    public function handle(PipelineContext $ctx, Closure $next): PipelineContext
    {
        $proxy = $ctx->proxy;
        $async = $proxy->processing_mode === ProcessingMode::Async;

        $deliveries = Delivery::query()
            ->where('dispatch_uuid', $ctx->dispatchUuid)
            ->with(['destination' => fn ($query) => $query->withTrashed()])
            ->get();

        $deliveries->each(function (Delivery $delivery) use ($ctx, $proxy, $async): void {
            $unit = new DeliveryUnit(
                ingestId: $ctx->ingestId,
                teamId: $proxy->team_id,
                proxyId: $proxy->id,
                destination: $delivery->destination,
                method: $delivery->destination->http_method->value,
                headers: $ctx->headers,
                payload: $ctx->payload,
                deliveryId: $delivery->id,
                attemptNumber: 1,
            );

            if ($async) {
                // Parallel, queued fan-out on the dedicated webhooks queue (ADR-011).
                DeliverToDestination::dispatch($unit)
                    ->onQueue(config('ingest.webhooks_queue'))
                    ->afterCommit();

                return;
            }

            // FIFO: inline, so the advancer settles the whole event before advancing.
            DeliverToDestination::run($unit);
        });

        return $next($ctx);
    }
}
