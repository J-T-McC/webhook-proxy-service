<?php

namespace App\Enums;

/**
 * Lifecycle status of a delivery attempt (ADR-003). `dispatched` is written
 * before the HTTP call (crash safety), then resolved to `succeeded`/`failed`.
 */
enum AttemptStatus: string
{
    case Dispatched = 'dispatched';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
