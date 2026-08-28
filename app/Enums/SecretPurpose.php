<?php

namespace App\Enums;

/**
 * What a `proxy_secrets` row is used for (ADR-021 Decision 2). `string(32)`,
 * not a database `enum`, so a third proxy-level secret later costs no
 * migration (plan-10 § Data Model).
 */
enum SecretPurpose: string
{
    case Verification = 'verification';
    case Signing = 'signing';
}
