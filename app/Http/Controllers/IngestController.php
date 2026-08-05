<?php

namespace App\Http\Controllers;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Services\ResponseResolver;
use App\Services\WebhookEventCapture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Public, token-authenticated ingest entry point (ADR-004/006).
 *
 * No session auth, CSRF-exempt (registered outside the web group). The presented
 * token IS the authenticator. Team scoping is no longer a global model scope, so
 * this lookup is naturally unscoped by team; the SoftDeletes scope still applies,
 * so a soft-deleted proxy 404s and no longer ingests (never `withTrashed()`). The
 * plaintext token is never logged.
 */
class IngestController extends Controller
{
    public function __construct(
        private ResponseResolver $responseResolver,
        private WebhookEventCapture $capture,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        // Resolve by SHA-256 token hash on the BINARY(32) UNIQUE index. Not team
        // scoped (no global scope; this route is outside the team group), but
        // SoftDeletes still applies. Unknown/soft-deleted -> 404, no existence
        // disclosure (AC12c).
        $proxy = Proxy::query()
            ->where('ingest_token_hash', hash('sha256', $token, binary: true))
            ->first();

        abort_if($proxy === null, Response::HTTP_NOT_FOUND);

        // Read the raw request facts up front and mint the single ingest_id — the one
        // correlator shared by the capture row and the fan-out delivery_attempts
        // (ADR-003). Do not introduce a second key.
        $ingestId = (string) Str::uuid();
        $method = $request->method();
        $headers = $request->headers->all();
        $rawBody = $request->getContent();

        $isFifo = $proxy->processing_mode === ProcessingMode::Fifo;

        // ADR-010/011: durably capture the raw payload SYNCHRONOUSLY — before the
        // response is resolved and before any dispatch — unconditionally on mode
        // (AC5/AC7, R2 override). For FIFO, the `fifo_dispatches` ordering row is
        // committed in the SAME transaction as capture (ADR-011), so a captured FIFO
        // event always has its ordering key. A capture-write failure returns HTTP 500,
        // rolls back, and dispatches nothing (AC6): success is never acknowledged for
        // an uncaptured event. Report the exception, never log the raw body or token.
        try {
            DB::transaction(function () use ($proxy, $ingestId, $method, $headers, $rawBody, $isFifo): void {
                $event = $this->capture->capture($proxy, $ingestId, $method, $headers, $rawBody);

                if ($isFifo) {
                    FifoDispatch::create([
                        'team_id' => $proxy->team_id,
                        'proxy_id' => $proxy->id,
                        'webhook_event_id' => $event->id,
                        'status' => FifoDispatchStatus::Pending,
                    ]);
                }
            });
        } catch (Throwable $e) {
            report($e);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Resolve the upstream response BEFORE and INDEPENDENT of delivery (ADR-004).
        // Only reachable after a committed capture (AC5).
        $response = $this->responseResolver->resolve($proxy);

        // ADR-005/011 dispatch seam — dispatched afterCommit, by reference, never
        // blocking the response. FIFO advances the proxy's ordered line; Async
        // processes the event's fan-out on its own. Both rebuild context from the
        // durable capture (ADR-011 Decision 3).
        if ($isFifo) {
            AdvanceProxyFifoQueue::dispatch($proxy->id)->afterCommit();
        } else {
            ProcessIngestedWebhook::dispatch($ingestId)->afterCommit();
        }

        return $response;
    }
}
