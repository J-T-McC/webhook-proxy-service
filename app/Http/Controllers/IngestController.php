<?php

namespace App\Http\Controllers;

use App\Actions\ProcessIngestedWebhook;
use App\Models\Proxy;
use App\Models\Scopes\TeamScope;
use App\Pipeline\PipelineContext;
use App\Services\ResponseResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, token-authenticated ingest entry point (ADR-004/006).
 *
 * No session auth, CSRF-exempt (registered outside the web group). The presented
 * token IS the authenticator, so the proxy lookup strips ONLY the team global
 * scope — the SoftDeletes scope is kept, so a soft-deleted proxy 404s and no
 * longer ingests (never `withTrashed()`). The plaintext token is never logged.
 */
class IngestController extends Controller
{
    public function __construct(private ResponseResolver $responseResolver) {}

    public function __invoke(Request $request, string $token): Response
    {
        // Resolve by SHA-256 token hash on the BINARY(32) UNIQUE index. Strip only
        // the team scope; keep SoftDeletes. Unknown/soft-deleted -> 404, no
        // existence disclosure (AC12c).
        $proxy = Proxy::withoutGlobalScope(TeamScope::class)
            ->where('ingest_token_hash', hash('sha256', $token, binary: true))
            ->first();

        abort_if($proxy === null, 404);

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
