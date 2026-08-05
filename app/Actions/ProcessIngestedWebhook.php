<?php

namespace App\Actions;

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
 * bug, never an expiry signal.
 */
class ProcessIngestedWebhook
{
    use AsAction;

    public function __construct(private PipelineFactory $factory) {}

    public function handle(string $ingestId): void
    {
        $event = WebhookEvent::query()->where('ingest_id', $ingestId)->firstOrFail();

        if ($event->payload_cleaned_at !== null) {
            Log::info('payload.expired', ['ingest_id' => $ingestId]);

            return;
        }

        // Load the proxy trashed-inclusive: an event captured before a later
        // soft-delete of its proxy must still deliver (ADR-011 Decision 3).
        $proxy = Proxy::withTrashed()->findOrFail($event->proxy_id);

        $ctx = new PipelineContext(
            ingestId: $event->ingest_id,
            proxy: $proxy,
            method: $event->method,
            headers: $event->headers,
            rawBody: $event->body,
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
}
