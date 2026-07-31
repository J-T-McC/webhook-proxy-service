<?php

namespace App\Services;

use App\Models\Proxy;
use Illuminate\Http\Response;

/**
 * Resolves the upstream response returned to the webhook sender (ADR-004),
 * BEFORE and INDEPENDENT of delivery. At item #1 this is always `202 Accepted`
 * with a minimal body; #3 later reads proxy response columns here — the
 * IngestController is untouched by that change. No proxy columns are read at #1.
 */
class ResponseResolver
{
    public function resolve(Proxy $proxy): Response
    {
        // LATER (#3): return response($proxy->response_body, $proxy->response_status);
        return new Response('', 202);
    }
}
