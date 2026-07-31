<?php

namespace App\Http\Controllers;

use App\Actions\ProcessIngestedWebhook;
use App\Models\Proxy;
use App\Pipeline\PipelineContext;
use App\Services\ResponseResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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
    public function __construct(private ResponseResolver $responseResolver) {}

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

        $rawBody = $request->getContent();

        $ctx = new PipelineContext(
            ingestId: (string) Str::uuid(),
            proxy: $proxy,
            method: $request->method(),
            headers: $request->headers->all(),
            rawBody: $rawBody,
            payload: $rawBody,
        );

        // Resolve the upstream response BEFORE and INDEPENDENT of delivery (ADR-004).
        $response = $this->responseResolver->resolve($proxy);

        // ADR-005 timing seam (PIPELINE level): ::run inline at #1.
        ProcessIngestedWebhook::run($ctx);

        return $response;
    }
}
