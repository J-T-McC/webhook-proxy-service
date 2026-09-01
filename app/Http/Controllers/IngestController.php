<?php

namespace App\Http\Controllers;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Services\ProxyLookup;
use App\Services\ResponseResolver;
use App\Services\WebhookEventCapture;
use App\Support\HopCount;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
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
        private ProxyLookup $proxies,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        // Resolve by SHA-256 token hash on the BINARY(32) UNIQUE index. Not team
        // scoped (no global scope; this route is outside the team group), but
        // SoftDeletes still applies. Unknown/soft-deleted -> 404, no existence
        // disclosure (AC12c). Cached per token hash and invalidated on write by
        // ProxyObserver, so a pause or a delete still takes effect at once.
        $proxy = $this->proxies->byTokenHash(hash('sha256', $token, binary: true));

        abort_if($proxy === null, Response::HTTP_NOT_FOUND);

        // Read the raw request facts up front and mint the single ingest_id — the one
        // correlator shared by the capture row and the fan-out delivery_attempts
        // (ADR-003). Do not introduce a second key. `$rawBody` is read exactly ONCE
        // here and reused by `WebhookEventCapture` — no second `$request->getContent()`
        // call anywhere.
        $ingestId = (string) Str::uuid();
        $method = $request->method();
        $headers = $request->headers->all();
        $rawBody = $request->getContent();

        // Delivery-loop guard (docs/briefs/delivery-loop-guard.md): the
        // indirect-cycle bound. `WebhookProxy-Hops` is stamped on every
        // outbound delivery by `OutboundHeaders::build()` (inbound + 1); an
        // inbound value at or above the configured limit means this request
        // has already looped through this many hops and is rejected before
        // capture — no `webhook_event` row, no dispatch. The rejection
        // reaches back to the delivering side as this response, which
        // settles as an ordinary failed attempt (non-2xx) through the
        // existing retry policy (AC/decision 2) — no separate handling
        // needed here.
        abort_if(
            HopCount::inboundFrom($headers) >= (int) config('ingest.max_hops'),
            Response::HTTP_LOOP_DETECTED,
            'Delivery loop detected.',
        );

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
                        'dispatch_uuid' => $ingestId,
                        'status' => FifoDispatchStatus::Pending,
                    ]);
                }
            });
        } catch (Throwable $e) {
            $this->reportCaptureFailure($e, $ingestId, $proxy);
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

    /**
     * Report a capture-transaction failure without letting
     * `QueryException::formatMessage()`'s interpolated bindings reach the log
     * (R5; plan Technical ruling 8). Those bindings are always ciphertext today —
     * `encrypted` casts run at attribute-set time, before `performInsert()` binds
     * `$this->getAttributes()` — but an encrypted copy of payload content (or a
     * secret column, if the failing write were `proxy_secrets`/
     * `destinations.credential_secret`) in a log file is still a copy AC3's
     * enumeration does not include and no retention pass touches.
     *
     * Reports a fresh, unchained exception carrying only `ingest_id`, the proxy
     * id, and the SQLSTATE (when the failure is a `QueryException`) — never the
     * original exception's message, and never set as `previous`, so nothing about
     * the interpolated statement can resurface through exception-chain formatting
     * either. This is table-agnostic: whichever write inside the wrapped
     * transaction fails, the same sanitized shape is reported.
     */
    private function reportCaptureFailure(Throwable $e, string $ingestId, Proxy $proxy): void
    {
        $sqlState = $e instanceof QueryException ? $e->getCode() : null;

        report(new RuntimeException(sprintf(
            'Webhook capture failed for ingest_id=%s proxy_id=%d%s',
            $ingestId,
            $proxy->id,
            $sqlState !== null ? sprintf(' sqlstate=%s', $sqlState) : '',
        )));
    }
}
