<?php

namespace App\Enums;

/**
 * Stored validation state of a destination (#18, ADR-027 decision 1). A
 * destination receives traffic only in the `Validated` case — the gate at all
 * four enforcement points is `validation_state = validated`, never a negation.
 *
 * PRD-18 AC1 names four product-facing states. Only three are stored: `Expired`
 * is derived from `Pending` plus a past `validation_challenge_expires_at`
 * (see `Destination::validationStatus()`). Storing it would need a scheduled
 * sweeper, and a sweeper that fell behind would show a dead challenge as still
 * pending. Deriving it is always correct and needs no job.
 */
enum DestinationValidationState: string
{
    case Unvalidated = 'unvalidated';
    case Pending = 'pending';
    case Validated = 'validated';
}
