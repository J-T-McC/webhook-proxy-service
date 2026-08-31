<?php

namespace App\Enums;

/**
 * The four product-facing validation states of a destination (PRD-18 AC1),
 * as displayed. This is a READ shape derived by
 * `Destination::validationStatus()` — it is never persisted, and the
 * enforcement gate never consults it.
 *
 * `Expired` has no stored counterpart in `DestinationValidationState`: it is
 * `Pending` whose `validation_challenge_expires_at` has passed. Behaviourally
 * it is identical to `Unvalidated` (the destination receives nothing); the
 * distinction exists so a member can tell "nobody has been asked yet" from
 * "somebody was asked and the window closed", which are different problems
 * with different fixes.
 */
enum DestinationValidationStatus: string
{
    case Unvalidated = 'unvalidated';
    case Pending = 'pending';
    case Expired = 'expired';
    case Validated = 'validated';
}
