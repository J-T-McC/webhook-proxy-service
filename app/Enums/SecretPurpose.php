<?php

namespace App\Enums;

/**
 * What a `proxy_secrets` row is used for (ADR-021 Decision 2). `string(32)`,
 * not a database `enum`, so a later proxy-level secret purpose still costs
 * no migration (plan-10 § Data Model) — a single-case backed enum is the
 * correct shape here for exactly that reason (ADR-026 Decision 3), even
 * though `Signing` is the only purpose remaining after inbound verification
 * was removed.
 */
enum SecretPurpose: string
{
    case Signing = 'signing';
}
