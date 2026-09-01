<?php

namespace App\Enums;

/**
 * Why the most recent validation challenge never reached its destination
 * (#18 AC35). Keys, never member-facing text: design-18 fixes the wording and
 * `resources/js/data/destinationValidationStates.ts` holds it, so an exception
 * message is never stored or shown.
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
