<?php

namespace App\Services;

use App\Enums\RetryBackoffStrategy;
use App\Models\Proxy;
use Carbon\CarbonInterval;
use RuntimeException;

/**
 * The single resolver of retry policy (ADR-015 Decision 3/4, plan-06
 * §Services). `RetryPolicy` is the ONLY reader of the two `proxies` retry
 * columns and of `config('retry.*')` (plan-06 binding invariant) — no other
 * place in the codebase may read either directly.
 *
 * A per-proxy column value, when set, overrides the matching config default;
 * `attemptLimitFor` additionally hard-clamps to `[1, max_attempt_limit]`
 * regardless of column content (a column value above the cap is only
 * reachable if the config cap was lowered after the value was saved).
 *
 * Also enforces plan-06's Config sanity invariant (mirroring `RetentionPolicy`
 * / review-05 M-1 precedent): `default_attempt_limit`, `max_attempt_limit`,
 * `exponential_base_seconds`, `fixed_interval_seconds`, `exponential_multiplier`,
 * and `exponential_max_delay_seconds` must each resolve to a positive integer,
 * or the affected read fails loudly (`RuntimeException` naming the offending
 * key) rather than silently substituting a default.
 */
class RetryPolicy
{
    /**
     * The maximum number of delivery attempts for a proxy (AC2). The column
     * value if set, else `config('retry.default_attempt_limit')`, always
     * clamped to `[1, config('retry.max_attempt_limit')]`.
     *
     * @throws RuntimeException if `default_attempt_limit`/`max_attempt_limit`
     *                          does not resolve to a positive integer.
     */
    public function attemptLimitFor(Proxy $proxy): int
    {
        $max = $this->positiveConfigInt('max_attempt_limit');
        $limit = $proxy->retry_attempt_limit ?? $this->positiveConfigInt('default_attempt_limit');

        return max(1, min($limit, $max));
    }

    /**
     * The backoff strategy for a proxy (AC2). The column value if set, else
     * `Exponential`.
     */
    public function strategyFor(Proxy $proxy): RetryBackoffStrategy
    {
        return $proxy->retry_backoff_strategy ?? RetryBackoffStrategy::Exponential;
    }

    /**
     * The delay before the given attempt (attempts 2..cap; ADR-015 Decision
     * 4). Exponential: `min(base * multiplier^(N-2), cap)` seconds. Fixed: the
     * constant `fixed_interval_seconds`.
     *
     * @throws RuntimeException if the relevant curve constant does not
     *                          resolve to a positive integer.
     */
    public function delayBefore(Proxy $proxy, int $attemptNumber): CarbonInterval
    {
        if ($this->strategyFor($proxy) === RetryBackoffStrategy::Fixed) {
            return CarbonInterval::seconds($this->positiveConfigInt('fixed_interval_seconds'));
        }

        return CarbonInterval::seconds($this->exponentialDelaySeconds($attemptNumber));
    }

    /**
     * The worst-case total span across every scheduled retry delay under the
     * exponential strategy, for the maximum configurable attempt limit — the
     * AC18 guard-test seam (T20): this must stay a small fraction of
     * `RetentionPolicy::windowFor()`'s window, so a retry policy can never make
     * a payload immortal.
     *
     * @throws RuntimeException if `max_attempt_limit`/`exponential_base_seconds`
     *                          does not resolve to a positive integer.
     */
    public function worstCaseSpan(): CarbonInterval
    {
        $max = $this->positiveConfigInt('max_attempt_limit');

        $totalSeconds = 0;
        for ($attemptNumber = 2; $attemptNumber <= $max; $attemptNumber++) {
            $totalSeconds += $this->exponentialDelaySeconds($attemptNumber);
        }

        return CarbonInterval::seconds($totalSeconds);
    }

    /**
     * `min(base * multiplier^(N-2), cap)` seconds for exponential attempt N.
     *
     * @throws RuntimeException if `exponential_base_seconds`,
     *                          `exponential_multiplier`, or
     *                          `exponential_max_delay_seconds` does not
     *                          resolve to a positive integer.
     */
    private function exponentialDelaySeconds(int $attemptNumber): int
    {
        $base = $this->positiveConfigInt('exponential_base_seconds');
        $multiplier = $this->positiveConfigInt('exponential_multiplier');
        $cap = $this->positiveConfigInt('exponential_max_delay_seconds');

        $delay = $base * ($multiplier ** ($attemptNumber - 2));

        return min($delay, $cap);
    }

    /**
     * Read a `config('retry.*')` integer key, failing loudly if it does not
     * resolve to a positive integer (Config sanity, review-05 M-1 precedent).
     *
     * @throws RuntimeException
     */
    private function positiveConfigInt(string $key): int
    {
        $value = (int) config("retry.{$key}");

        if ($value < 1) {
            throw new RuntimeException(sprintf(
                "config('retry.%s') must resolve to a positive integer; got %d. Refusing to ".
                'silently substitute a default.',
                $key,
                $value,
            ));
        }

        return $value;
    }
}
