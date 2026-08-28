<?php

namespace App\Enums;

/**
 * `InboundVerifier::verify()`'s tri-state outcome (ADR-022 Decision 1). Not
 * a backed enum — nothing needs to serialize or persist it, and it never
 * carries a reason code (ADR-022 Decision 5's reason codes are computed
 * separately, by `InboundVerifier::reasonFor()`, only when this is `Failed`).
 */
enum VerificationResult
{
    case NotRequired;
    case Verified;
    case Failed;
}
