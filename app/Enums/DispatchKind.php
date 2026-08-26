<?php

namespace App\Enums;

/**
 * Origin of a `deliveries` row (ADR-015 Decision 1 / ADR-017). `Original` is
 * the pipeline's first send of a captured event; `Replay` re-processes the
 * raw payload through the pipeline again on demand (AC10+). Retries of either
 * kind re-send the recorded dispatched output — never conflated with replay.
 */
enum DispatchKind: string
{
    case Original = 'original';
    case Replay = 'replay';
}
