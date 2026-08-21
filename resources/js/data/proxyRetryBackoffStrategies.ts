import type { DataOption } from '@/types/data';

/**
 * Single source of truth for the closed set of per-proxy retry backoff
 * strategies — shared by the proxy form (select options) and the proxy
 * detail page (Retry policy card label), so the same strategy reads
 * identically in both.
 *
 * Values MUST stay in sync with the PHP `RetryBackoffStrategy` enum
 * (`app/Enums/RetryBackoffStrategy.php`); the backend is authoritative (do
 * not add a value here without adding the enum case first).
 */
export const PROXY_RETRY_BACKOFF_STRATEGIES = [
    { value: 'exponential', label: 'Exponential' },
    { value: 'fixed', label: 'Fixed interval' },
] as const satisfies readonly DataOption<string>[];

/**
 * Per-proxy retry backoff strategy — the value union derived from
 * {@link PROXY_RETRY_BACKOFF_STRATEGIES} (currently `'exponential' |
 * 'fixed'`). null (elsewhere) = unconfigured; `RetryPolicy` resolves the
 * exponential default.
 */
export type RetryBackoffStrategy =
    (typeof PROXY_RETRY_BACKOFF_STRATEGIES)[number]['value'];

/**
 * Sentinel select-item value for the unconfigured ("use the system default")
 * state, mirroring `PROXY_RESPONSE_STATUSES`' `STATUS_DEFAULT` idiom — a
 * `Select` cannot hold an empty-string item value.
 */
export const RETRY_STRATEGY_DEFAULT = 'default';

/**
 * The system default attempt limit (`config('retry.default_attempt_limit')`
 * / `App\Services\RetryPolicy`) — mirrored here as a display literal for the
 * proxy form's help text and the Show-page Retry policy card's `(default)`
 * annotation. MUST stay in sync with `config/retry.php`'s
 * `default_attempt_limit`.
 */
export const RETRY_DEFAULT_ATTEMPT_LIMIT = 5;

/**
 * Label for the unconfigured (default) backoff-strategy state, shown as the
 * sentinel select option on the form. MUST stay in sync with
 * `RetryPolicy::strategyFor()`'s default (`RetryBackoffStrategy::Exponential`).
 */
export const RETRY_STRATEGY_DEFAULT_LABEL = 'Default (Exponential)';

/**
 * The label for a stored backoff strategy: null (unconfigured) → the default
 * option's label; a known strategy → its option label; anything else → the
 * default option's label.
 */
export function proxyRetryBackoffStrategyLabel(
    strategy: RetryBackoffStrategy | null,
): string {
    return (
        PROXY_RETRY_BACKOFF_STRATEGIES.find(
            (option) => option.value === strategy,
        )?.label ?? 'Exponential'
    );
}

/**
 * The Show-page display value for a proxy's effective attempt limit — the
 * configured number, or the system default annotated `(default)` when
 * unconfigured (design-06 Screen 1 States table).
 */
export function proxyRetryAttemptLimitDisplay(
    attemptLimit: number | null,
): string {
    return attemptLimit === null
        ? `${RETRY_DEFAULT_ATTEMPT_LIMIT} (default)`
        : `${attemptLimit}`;
}

/**
 * The Show-page display value for a proxy's effective backoff strategy — the
 * configured strategy's label, or the default strategy's label annotated
 * `(default)` when unconfigured (design-06 Screen 1 States table).
 */
export function proxyRetryBackoffStrategyDisplay(
    strategy: RetryBackoffStrategy | null,
): string {
    return strategy === null
        ? `${proxyRetryBackoffStrategyLabel(null)} (default)`
        : proxyRetryBackoffStrategyLabel(strategy);
}
