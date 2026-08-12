<?php

namespace App\Enums;

/**
 * Per-proxy retry backoff strategy (ADR-015 Decision 3). Persists as the
 * `proxies.retry_backoff_strategy` column (NULL means "system default").
 * `App\Services\RetryPolicy` is the only reader of the column and of the
 * matching `config('retry.*')` curve constants.
 */
enum RetryBackoffStrategy: string
{
    case Exponential = 'exponential';
    case Fixed = 'fixed';
}
