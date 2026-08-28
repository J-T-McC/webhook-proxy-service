<?php

namespace App\Exceptions;

use App\Enums\SecretPurpose;
use RuntimeException;

/**
 * AC11 / ADR-021 Decision 7: a stored secret that cannot be decrypted fails
 * the operation loudly rather than being silently dropped from the live set
 * — a partial signature list would be indistinguishable, to us and to the
 * receiver, from a completed rotation. The message is fixed and value-free:
 * it names which secret (by purpose) failed and nothing else — never a
 * proxy/team identifier, a ciphertext fragment, or any part of the secret
 * itself. Callers that need identifiers for a report attach them separately
 * (Technical ruling 8's "identifiers only" report wrap).
 */
class SecretUnavailableException extends RuntimeException
{
    public function __construct(SecretPurpose $purpose)
    {
        parent::__construct("The {$purpose->value} secret could not be decrypted.");
    }
}
