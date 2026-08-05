<?php

namespace App\Services;

use App\Models\Proxy;
use Illuminate\Http\Response;

/**
 * Resolves the upstream response returned to the webhook sender (ADR-004),
 * BEFORE and INDEPENDENT of delivery. Reads ONLY proxy configuration columns —
 * never delivery outcome or `delivery_attempts` (ADR-004 invariant, AC2). An
 * unconfigured proxy (NULL columns) inherits `202 Accepted` with an empty body;
 * the `202` default lives here, not in the schema (single source, AC3). A `204`
 * status carries NO body (204 = No Content, AC12), distinct from 200/202 which
 * return the configured body.
 */
class ResponseResolver
{
    public function resolve(Proxy $proxy): Response
    {
        $status = $proxy->response_status ?? Response::HTTP_ACCEPTED;

        // 204 No Content never carries a body (AC12); validation already forbids a
        // body for 204, but resolving it to empty here makes the coupling explicit.
        $body = $status === Response::HTTP_NO_CONTENT
            ? ''
            : ($proxy->response_body ?? '');

        $headers = $body === ''
            ? []
            : ['Content-Type' => 'text/plain; charset=utf-8'];

        return new Response($body, $status, $headers);
    }
}
