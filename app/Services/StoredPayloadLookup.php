<?php

namespace App\Services;

use App\Enums\StoredPayloadState;
use App\Models\WebhookEvent;

/**
 * The single resolver of a stored payload's state for a given `ingest_id`
 * (AC10, AC21; ADR-012 Decision 3, ADR-013 Decision 3, ADR-014 Decision 4).
 * Reads `webhook_events.payload_cleaned_at` only — NEVER infers "cleaned"
 * from `body === null`, a failed lookup, or the presence of
 * `delivery_attempts` rows (ADR-014 Decision 7, binding). This class is also
 * the only place `dispatched_payloads.body IS NULL` may ever be interpreted
 * (ADR-013 Decision 3), even though nothing consumes that interpretation at
 * #5.
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
}
