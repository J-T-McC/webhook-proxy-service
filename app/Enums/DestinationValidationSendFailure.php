<?php

namespace App\Enums;

/**
 * Why the most recent validation challenge failed to reach a destination
 * (#18 AC35). Stored in `destinations.validation_last_send_failure`, and set
 * only when the send never arrived — a destination that answered, with any
 * status, records its status instead (AC18).
 *
 * These are keys, never member-facing text. design-18 fixes the wording for
 * each one and forbids implementation jargon in it, so the phrasing lives with
 * the rest of the validation copy in
 * `resources/js/data/destinationValidationStates.ts` and the exception message
 * behind a failure is never stored or shown.
 *
 * The three cases are the three failure exits of
 * `App\Actions\SendDestinationValidationChallenge` and nothing else. A send
 * refused before it is attempted — the destination is already validated, or a
 * rate limiter is tripped — is not a send and records no outcome at all.
 */
enum DestinationValidationSendFailure: string
{
    /** The address was refused before any request left (AC20). */
    case AddressRefused = 'address_refused';

    /** No connection could be made: DNS failure, refused connection, timeout. */
    case Unreachable = 'unreachable';

    /** A redirect was returned, which a validation send never follows (AC19). */
    case Redirected = 'redirected';
}
