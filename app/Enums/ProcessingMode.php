<?php

namespace App\Enums;

/**
 * Per-proxy processing mode (ADR-011 Decision 1). `async` fans out delivery to
 * a dedicated queue in parallel; `fifo` settles each received event fully, in
 * receive order, before advancing the proxy's line. Defaults to `async`.
 */
enum ProcessingMode: string
{
    case Async = 'async';
    case Fifo = 'fifo';
}
