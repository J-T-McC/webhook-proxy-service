<?php

namespace App\Services;

use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Models\Proxy;
use Carbon\CarbonInterval;
use RuntimeException;

/**
 * The single resolver of retry policy (ADR-015 Decision 3/4, ADR-018 Decision
 * 2; plan-06/plan-07 §Services). `RetryPolicy` is the ONLY reader of the two
 * `proxies` retry columns and of `config('retry.*')` (plan-06 binding
 * invariant, reaffirmed by ADR-018) — no other place in the codebase may read
 * either directly.
 *
 * **The mode gate (ADR-018 Decision 2, binding).** `configuredAttemptLimitFor()`/
 * `configuredStrategyFor()` establish `mode === ProxyMode::Enhanced` before
 * reading either column, returning `null` otherwise — a Simple proxy's column
 * is dormant and resolves the system default whatever it holds
 * (PRD-07 AC14(a)/AC21). `attemptLimitFor()`/`strategyFor()` route through
 * them, so every consumer of those two methods inherits the gate with no
 * branch of its own. A per-proxy override, when the gate lets it through,
 * overrides the matching config default; `attemptLimitFor` additionally
 * hard-clamps to `[1, max_attempt_limit]` after the gate, regardless of
 * column content (a column value above the cap is only reachable if the
 * config cap was lowered after the value was saved).
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
     * The per-proxy attempt-limit override in force (ADR-018 Decision 2) —
     * the column value on an Enhanced proxy, `null` on a Simple one whatever
     * the column holds. The ONLY place `retry_attempt_limit` is read to
     * decide what governs a proxy.
     */
    public function configuredAttemptLimitFor(Proxy $proxy): ?int
    {
        return $proxy->mode === ProxyMode::Enhanced ? $proxy->retry_attempt_limit : null;
    }

    /**
     * The per-proxy backoff-strategy override in force (ADR-018 Decision 2) —
     * the column value on an Enhanced proxy, `null` on a Simple one whatever
     * the column holds. The ONLY place `retry_backoff_strategy` is read to
     * decide what governs a proxy.
     */
    public function configuredStrategyFor(Proxy $proxy): ?RetryBackoffStrategy
    {
        return $proxy->mode === ProxyMode::Enhanced ? $proxy->retry_backoff_strategy : null;
    }

    /**
     * The maximum number of delivery attempts for a proxy (AC2, AC14(a)).
     * `configuredAttemptLimitFor()`'s value if the proxy is Enhanced and the
     * column is set, else `config('retry.default_attempt_limit')`, always
     * clamped to `[1, config('retry.max_attempt_limit')]` — the clamp applies
     * after the mode gate, in both modes.
     *
     * @throws RuntimeException if `default_attempt_limit`/`max_attempt_limit`
     *                          does not resolve to a positive integer.
     */
    public function attemptLimitFor(Proxy $proxy): int
    {
        $max = $this->positiveConfigInt('max_attempt_limit');
        $limit = $this->configuredAttemptLimitFor($proxy) ?? $this->positiveConfigInt('default_attempt_limit');

        return max(1, min($limit, $max));
    }

    /**
     * The backoff strategy for a proxy (AC2, AC14(a)).
     * `configuredStrategyFor()`'s value if the proxy is Enhanced and the
     * column is set, else `Exponential`.
     */
    public function strategyFor(Proxy $proxy): RetryBackoffStrategy
    {
        return $this->configuredStrategyFor($proxy) ?? RetryBackoffStrategy::Exponential;
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
