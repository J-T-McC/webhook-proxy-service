<?php

namespace App\Pipeline;

use App\Models\Proxy;

/**
 * The single mutable in-memory envelope every pipeline step reads/writes
 * in-process (ADR-001). Raw inputs are immutable; `payload` is the accumulated
 * state and starts equal to the raw body — at item #1 the delivered payload IS
 * the raw body (later NormalizeStep/MapStep overwrite it; DeliverStep only reads).
 */
final class PipelineContext
{
    /**
     * Mutable delivered payload. Initialised to the raw body at capture.
     */
    public string $payload;

    /**
     * @param  string  $ingestId  UUID correlating one webhook's fan-out set (ADR-003)
     * @param  Proxy  $proxy  resolved by token-hash (ADR-006)
     * @param  string  $method  POST|PUT as received
     * @param  array<string, list<string|null>>  $headers  raw received headers
     * @param  string  $rawBody  raw received bytes — never mutated (R2)
     * @param  string|null  $payload  defaults to the raw body when omitted
     */
    public function __construct(
        public readonly string $ingestId,
        public readonly Proxy $proxy,
        public readonly string $method,
        public readonly array $headers,
        public readonly string $rawBody,
        ?string $payload = null,
    ) {
        $this->payload = $payload ?? $rawBody;
    }
}
