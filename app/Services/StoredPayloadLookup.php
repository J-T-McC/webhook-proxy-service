<?php

namespace App\Services;

use App\Enums\StoredPayloadState;
use App\Models\DispatchedPayload;
use App\Models\WebhookEvent;

/**
 * The single resolver of a stored payload's state for a given `ingest_id`
 * (AC10, AC21; ADR-012 Decision 3, ADR-013 Decision 3, ADR-014 Decision 4).
 * Reads `webhook_events.payload_cleaned_at` only — NEVER infers "cleaned"
 * from `body === null`, a failed lookup, or the presence of
 * `delivery_attempts` rows (ADR-014 Decision 7, binding). This class is also
 * the only place `dispatched_payloads.body IS NULL` may ever be interpreted
 * (ADR-013 Decision 3) — both here (#5) and in `dispatchedBytesFor()` (#6).
 */
class StoredPayloadLookup
{
    /**
     * The stored payload state for the given `ingest_id`.
     */
    public function for(string $ingestId): StoredPayloadState
    {
        $event = WebhookEvent::query()->where('ingest_id', $ingestId)->first();

        if ($event === null) {
            return StoredPayloadState::NeverCaptured;
        }

        return $event->payload_cleaned_at === null
            ? StoredPayloadState::Retained
            : StoredPayloadState::Cleaned;
    }

    /**
     * The retry-source resolution (AC13; ADR-013 Decision 3, ADR-015 Decision
     * 1): the bytes a retry must re-send — `dispatched_payloads.body` when it
     * diverged from the raw capture, else the raw `webhook_events.body` (the
     * identical-payload case, ADR-013 Decision 2, and the no-row case).
     *
     * Callable ONLY for a retained event — the caller must have already
     * guarded `payload_cleaned_at` (ADR-014 Decision 7, binding); this method
     * does NOT re-guard, keeping "the only place `dispatched_payloads.body IS
     * NULL` is interpreted" true and undivided within this one class.
     */
    public function dispatchedBytesFor(WebhookEvent $event): string
    {
        $dispatched = DispatchedPayload::query()
            ->where('webhook_event_id', $event->id)
            ->first();

        if ($dispatched !== null && $dispatched->body !== null) {
            return $dispatched->body;
        }

        // No row, or a NULL body (the identical-payload case) — the raw
        // capture IS the dispatched output. `$event->body` is guaranteed
        // non-null here: this method is callable only for a retained event
        // (guarded by the caller, never here).
        /** @var string $body */
        $body = $event->body;

        return $body;
    }
}
