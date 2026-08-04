<?php

namespace App\Services;

use App\Models\Proxy;
use Illuminate\Http\Response;

/**
 * Resolves the upstream response returned to the webhook sender (ADR-004),
 * BEFORE and INDEPENDENT of delivery. Reads ONLY proxy configuration columns —
 * never delivery outcome or `delivery_attempts` (ADR-004 invariant, AC2). An
 * unconfigured proxy (NULL columns) inherits `202 Accepted` with an empty body;
 * the `202` default lives here, not in the schema (single source, AC3).
 */
class ResponseResolver
{
    public function resolve(Proxy $proxy): Response
    {
        $status = $proxy->response_status ?? Response::HTTP_ACCEPTED;
        $body = $proxy->response_body ?? '';

        $headers = $body === ''
            ? []
            : ['Content-Type' => 'text/plain; charset=utf-8'];

        return new Response($body, $status, $headers);
    }
}
