<?php

namespace App\Actions;

use App\Enums\ProcessingMode;
use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStep;
use Closure;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The terminal fan-out step (ADR-001/011). Iterates the proxy's LIVE destinations
 * (the relation carries the SoftDeletes scope, so trashed destinations are never
 * delivered to) and builds one {@see DeliveryUnit} per destination, then dispatches
 * each per the proxy's processing mode (ADR-011):
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

        $proxy->destinations->each(function (Destination $destination) use ($ctx, $proxy, $async): void {
            $unit = new DeliveryUnit(
                ingestId: $ctx->ingestId,
                teamId: $proxy->team_id,
                proxyId: $proxy->id,
                destination: $destination,
                method: $destination->http_method->value,
                headers: $ctx->headers,
                payload: $ctx->payload,
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
