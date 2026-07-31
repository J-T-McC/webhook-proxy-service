<?php

namespace App\Enums;

/**
 * Proxy processing mode (ADR-002). At item #1 both modes compose the same
 * pipeline; `enhanced` is persistable but adds no steps yet.
 */
enum ProxyMode: string
{
    case Simple = 'simple';
    case Enhanced = 'enhanced';
}
