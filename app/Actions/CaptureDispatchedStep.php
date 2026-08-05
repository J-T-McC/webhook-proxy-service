<?php

namespace App\Actions;

use App\Models\DispatchedPayload;
use App\Models\WebhookEvent;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStep;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * **Post-clean write guard (plan §Architecture "Post-clean dispatched-output
 * write" ruling; Risk 4).** Under erase-in-place the parent `webhook_events`
 * row survives its own erasure, so an unconditioned write here could
 * create/update a `dispatched_payloads` row for an event already marked
 * cleaned. The parent row is locked (`lockForUpdate()`) and its
 * `payload_cleaned_at` re-checked inside the SAME transaction as the write —
 * a compare-and-set on the parent, not a separate read-then-write — closing
 * the race against the GC's own compare-and-set `UPDATE` (T11), which takes
 * the same row lock. If the parent is already cleaned, this step logs
 * `payload.expired` (identifiers only — never payload content) and returns
 * BEFORE calling `$next`, so `DeliverStep` never runs for a cleaned event.
 */
class CaptureDispatchedStep implements PipelineStep
{
    use AsObject;

    /**
     * @param  Closure(PipelineContext): PipelineContext  $next
     */
    public function handle(PipelineContext $ctx, Closure $next): PipelineContext
    {
        $alreadyCleaned = DB::transaction(function () use ($ctx): bool {
            $event = WebhookEvent::query()
                ->where('ingest_id', $ctx->ingestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->payload_cleaned_at !== null) {
                return true;
            }

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

            return false;
        });

        if ($alreadyCleaned) {
            Log::info('payload.expired', ['ingest_id' => $ctx->ingestId]);

            return $ctx;
        }

        return $next($ctx);
    }
}
