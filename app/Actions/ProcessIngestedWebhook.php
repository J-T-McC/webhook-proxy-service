<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\ProcessingMode;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineFactory;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The pipeline-level dispatch-timing action (ADR-005/007/011). Dispatched by
 * reference (`string $ingestId`, ADR-011 Decision 3) so the queued job carries no
 * decrypted payload: it rebuilds the {@see PipelineContext} from the durable
 * {@see WebhookEvent} capture (ADR-010) and runs the WHOLE pipeline in-process via
 * the native `Illuminate\Pipeline\Pipeline`. The proxy is loaded trashed-inclusive
 * so an event accepted before a later soft-delete still delivers. The raw body and
 * token are never logged on this path.
 *
 * Guards on `payload_cleaned_at` (AC10, AC21; ADR-014 Decision 7, binding): an
 * event whose payload has already expired is never delivered — nothing is
 * dispatched, no pipeline runs. An absent row (`firstOrFail()`) is a genuine
 * bug, never an expiry signal. Any `deliveries` row a REPLAY already
 * pre-created for this dispatch is terminalized rather than left non-terminal
 * (item #15 Q-15-01(4) — see {@see terminalizeNonTerminalDeliveries()}).
 *
 * Also guards `Proxy::isPaused()` (item #15, AC3) for Async proxies only: a
 * FIFO proxy's row is never claimed while paused (`AdvanceProxyFifoQueue`'s
 * own guard), so this action is never reached for FIFO in the ordinary case.
 * Async has no separate claim step — this action IS the dispatch point — so
 * this is where Async's original-dispatch pause guard lives.
 */
class ProcessIngestedWebhook
{
    use AsAction;

    public function __construct(private PipelineFactory $factory) {}

    public function handle(string $ingestId, ?string $dispatchUuid = null): void
    {
        $dispatchUuid ??= $ingestId;

        $event = WebhookEvent::query()->where('ingest_id', $ingestId)->firstOrFail();

        if ($event->payload_cleaned_at !== null) {
            Log::info('payload.expired', ['ingest_id' => $ingestId]);
            $this->terminalizeNonTerminalDeliveries($dispatchUuid);

            return;
        }

        // Load the proxy trashed-inclusive: an event captured before a later
        // soft-delete of its proxy must still deliver (ADR-011 Decision 3).
        $proxy = Proxy::withTrashed()->findOrFail($event->proxy_id);

        // Scoped away from FIFO deliberately: were this to also fire on a rare
        // claim/pause race, returning here with zero deliveries created would
        // make `AdvanceProxyFifoQueue::settleOrHold()` read "no non-terminal
        // deliveries" and settle the row as done — silently losing the event
        // instead of leaving it to resume. FIFO's own claim guard is the
        // correct (and sufficient) place for that mode.
        if ($proxy->processing_mode !== ProcessingMode::Fifo && $proxy->isPaused()) {
            Log::info('dispatch.paused', ['ingest_id' => $ingestId, 'proxy_id' => $proxy->id]);

            return;
        }

        // Create the dispatch's ORIGINAL `deliveries` rows — one per LIVE
        // destination (ruling 2 — new selection uses live destinations only).
        // `firstOrCreate` on the (dispatch_uuid, destination_id) unique key (T3)
        // makes a redelivery for the same dispatch idempotent: no duplicate rows.
        // Gated on `$dispatchUuid === $ingestId` (T8's identifying shape of the
        // original dispatch): a replay (T24) mints its own distinct
        // `dispatch_uuid` and pre-creates its OWN `deliveries` rows for the
        // user's CHOSEN destination subset before dispatching here — this loop
        // must never widen that selection by backfilling every other live
        // destination with an extra (wrongly `kind = original`) row (AC10).
        if ($dispatchUuid === $ingestId) {
            foreach ($proxy->destinations as $destination) {
                Delivery::query()->firstOrCreate(
                    ['dispatch_uuid' => $dispatchUuid, 'destination_id' => $destination->id],
                    [
                        'team_id' => $proxy->team_id,
                        'proxy_id' => $proxy->id,
                        'webhook_event_id' => $event->id,
                        'kind' => DispatchKind::Original,
                        'status' => DeliveryStatus::Pending,
                    ],
                );
            }
        }

        $ctx = new PipelineContext(
            ingestId: $event->ingest_id,
            proxy: $proxy,
            method: $event->method,
            headers: $event->headers,
            rawBody: $event->body,
            dispatchUuid: $dispatchUuid,
        );

        $this->runPipeline($ctx);
    }

    /**
     * Run the whole pipeline over one context. Kept as a thin seam for unit tests.
     */
    private function runPipeline(PipelineContext $ctx): void
    {
        app(Pipeline::class)
            ->send($ctx)
            ->through($this->factory->stepsFor($ctx->proxy))
            ->thenReturn();
    }

    /**
     * A cleaned event's dispatch must never be left holding a non-terminal
     * `deliveries` row. For the ORIGINAL dispatch none exist yet at this point
     * (they are created further down, after this guard) — a no-op, the
     * ordinary case. A REPLAY, though, pre-creates its `deliveries` rows
     * (`ProxyEventReplayController`) before the FIFO claim or the queued job
     * ever reaches this method; if the event was cleaned in the meantime
     * (reachable once a paused proxy's backlog can expire, item #15 AC9),
     * those rows would otherwise sit non-terminal forever, parking the FIFO
     * line at `awaiting_retry` with no lease and no age escape — the failure
     * ADR-019 identified in a different form (Q-15-01(4)). Mirrors
     * {@see RetryDelivery::terminalizeCleaned()}'s compare-and-set-then-event
     * shape, applied to every non-terminal row of the dispatch rather than a
     * single delivery. No attempt is ever made, so no attempt row is ever
     * written (AC12/AC17).
     */
    private function terminalizeNonTerminalDeliveries(string $dispatchUuid): void
    {
        $nonTerminal = Delivery::query()
            ->where('dispatch_uuid', $dispatchUuid)
            ->whereNotIn('status', [DeliveryStatus::Succeeded, DeliveryStatus::Failed])
            ->get();

        foreach ($nonTerminal as $delivery) {
            $affected = Delivery::query()
                ->whereKey($delivery->id)
                ->whereNotIn('status', [DeliveryStatus::Succeeded, DeliveryStatus::Failed])
                ->update(['status' => DeliveryStatus::Failed, 'next_attempt_at' => null]) > 0;

            if (! $affected) {
                continue;
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->next_attempt_at = null;

            event(new DeliveryExhausted($delivery));
        }
    }
}
