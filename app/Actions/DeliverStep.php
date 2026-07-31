<?php

namespace App\Actions;

use App\Models\Destination;
use App\Pipeline\DeliveryUnit;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStep;
use Closure;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The terminal fan-out step (ADR-001). Iterates the proxy's LIVE destinations
 * (the relation carries the SoftDeletes scope, so trashed destinations are never
 * delivered to), builds one {@see DeliveryUnit} per destination and runs
 * {@see DeliverToDestination} inline for each, then continues the chain.
 *
 * One destination failing does not abort the loop (AC9) — DeliverToDestination
 * catches its own transport errors. This step only READS `$ctx->payload`.
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

        $proxy->destinations->each(function (Destination $destination) use ($ctx, $proxy): void {
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

            DeliverToDestination::run($unit);
        });

        return $next($ctx);
    }
}
