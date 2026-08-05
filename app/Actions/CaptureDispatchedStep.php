<?php

namespace App\Actions;

use App\Models\DispatchedPayload;
use App\Models\WebhookEvent;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStep;
use Closure;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The dispatched-output capture step (AC12, AC13, AC19; ADR-013 Decisions 1, 2,
 * 4, 5). Records the payload actually dispatched downstream, but only when it
 * diverges from the raw capture — `body` stays NULL when `$ctx->payload ===
 * $ctx->rawBody` (ADR-013 Decision 2), the pre-#9 default. `webhook_event_id`
 * is UNIQUE, so re-invocation (queue redelivery) updates the existing row
 * rather than duplicating it. Only READS `$ctx->payload`/`$ctx->rawBody` —
 * never mutates either.
 *
 * The post-clean write guard (plan §Architecture, Risk 4) is deliberately OUT
 * OF SCOPE here — see the dedicated guard added on top of this step.
 */
class CaptureDispatchedStep implements PipelineStep
{
    use AsObject;

    /**
     * @param  Closure(PipelineContext): PipelineContext  $next
     */
    public function handle(PipelineContext $ctx, Closure $next): PipelineContext
    {
        $event = WebhookEvent::query()->where('ingest_id', $ctx->ingestId)->firstOrFail();

        $diverged = $ctx->payload !== $ctx->rawBody;

        DispatchedPayload::query()->updateOrCreate(
            ['webhook_event_id' => $event->id],
            [
                'team_id' => $ctx->proxy->team_id,
                'proxy_id' => $ctx->proxy->id,
                'body' => $diverged ? $ctx->payload : null,
                'byte_size' => strlen($ctx->payload),
                'dispatched_at' => now(),
            ],
        );

        return $next($ctx);
    }
}
