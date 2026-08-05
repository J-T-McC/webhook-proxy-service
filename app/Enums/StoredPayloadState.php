<?php

namespace App\Enums;

/**
 * The three states a stored payload can be in for a given `ingest_id` (AC21,
 * ADR-014 Decision 4). Named once here so every consumer (starting with
 * `StoredPayloadLookup`) reads the same three values instead of re-deriving
 * them. Signalled explicitly via `payload_cleaned_at` — never inferred from
 * `body === null`, a failed lookup, or the presence of `delivery_attempts`.
 */
enum StoredPayloadState: string
{
    /** Payload content is present and retrievable within its retention window. */
    case Retained = 'retained';

    /** The event was captured and its payload content has since been erased on expiry. */
    case Cleaned = 'cleaned';

    /** No payload content was ever held for the referenced identifier. */
    case NeverCaptured = 'never_captured';
}
