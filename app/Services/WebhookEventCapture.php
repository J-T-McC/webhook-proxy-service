<?php

namespace App\Services;

use App\Models\Proxy;
use App\Models\WebhookEvent;

/**
 * Durably captures one incoming webhook's raw payload (ADR-010, AC5/AC7–AC9).
 *
 * A Service, NOT an Action, deliberately — it must never be `::dispatch`ed. Capture
 * is inherently synchronous and pre-dispatch: the row is committed before the
 * upstream response is returned (the IngestController owns that ordering, T11). The
 * ingest path is team-unscoped (no current team), so `team_id`/`proxy_id` are set
 * explicitly from the resolved proxy, mirroring `DeliverToDestination` — never from
 * an authenticated user.
 */
class WebhookEventCapture
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function capture(Proxy $proxy, string $ingestId, string $method, array $headers, string $rawBody): WebhookEvent
    {
        return WebhookEvent::create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'ingest_id' => $ingestId,
            'method' => $method,
            'headers' => $headers,
            'content_type' => $this->contentTypeFrom($headers),
            'body' => $rawBody,
            // Plaintext received size, recorded before the `encrypted` cast expands it.
            'byte_size' => strlen($rawBody),
            'received_at' => now(),
        ]);
    }

    /**
     * Case-insensitively derive the inbound Content-Type from the header bag.
     * Returns null when the header is absent.
     *
     * @param  array<string, mixed>  $headers
     */
    private function contentTypeFrom(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'content-type') {
                continue;
            }

            $scalar = is_array($value) ? ($value[0] ?? null) : $value;

            return is_scalar($scalar) ? (string) $scalar : null;
        }

        return null;
    }
}
