<?php

namespace Tests\Unit\Services;

use App\Enums\RetryBackoffStrategy;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\RetentionPolicy;
use App\Services\RetryPolicy;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class RetryPolicyTest extends TestCase
{
    // --- attemptLimitFor -----------------------------------------------

    public function test_attempt_limit_for_returns_the_column_value_when_set(): void
    {
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => 3]);

        $this->assertSame(3, (new RetryPolicy)->attemptLimitFor($proxy));
    }

    public function test_attempt_limit_for_returns_the_config_default_when_the_column_is_null(): void
    {
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => null]);

        $this->assertSame(
            (int) config('retry.default_attempt_limit'),
            (new RetryPolicy)->attemptLimitFor($proxy),
        );
    }

    public function test_attempt_limit_for_clamps_a_column_value_above_the_cap(): void
    {
        Config::set('retry.max_attempt_limit', 10);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => 250]);

        $this->assertSame(10, (new RetryPolicy)->attemptLimitFor($proxy));
    }

    public function test_attempt_limit_for_clamps_the_config_default_above_the_cap(): void
    {
        Config::set('retry.default_attempt_limit', 20);
        Config::set('retry.max_attempt_limit', 10);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => null]);

        $this->assertSame(10, (new RetryPolicy)->attemptLimitFor($proxy));
    }

    // --- strategyFor ------------------------------------------------------

    public function test_strategy_for_returns_the_column_value_when_set(): void
    {
        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => RetryBackoffStrategy::Fixed]);

        $this->assertSame(RetryBackoffStrategy::Fixed, (new RetryPolicy)->strategyFor($proxy));
    }

    public function test_strategy_for_returns_exponential_when_the_column_is_null(): void
    {
        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => null]);

        $this->assertSame(RetryBackoffStrategy::Exponential, (new RetryPolicy)->strategyFor($proxy));
    }

    // --- delayBefore: exponential -----------------------------------------

    public function test_delay_before_exponential_matches_the_formula_across_the_full_attempt_range(): void
    {
        Config::set('retry.exponential_base_seconds', 60);
        Config::set('retry.exponential_multiplier', 5);
        Config::set('retry.exponential_max_delay_seconds', 21600);
        Config::set('retry.max_attempt_limit', 10);

        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => RetryBackoffStrategy::Exponential]);
        $policy = new RetryPolicy;

        // min(base * multiplier^(N-2), cap) seconds, attempts 2..10.
        $expected = [
            2 => 60,       // 60 * 5^0
            3 => 300,      // 60 * 5^1
            4 => 1500,     // 60 * 5^2
            5 => 7500,     // 60 * 5^3
            6 => 21600,    // 60 * 5^4 = 37500, capped
            7 => 21600,
            8 => 21600,
            9 => 21600,
            10 => 21600,
        ];

        foreach ($expected as $attemptNumber => $seconds) {
            $this->assertSame(
                $seconds,
                (int) $policy->delayBefore($proxy, $attemptNumber)->totalSeconds,
                "attempt {$attemptNumber}",
            );
        }
    }

    // --- delayBefore: fixed ------------------------------------------------

    public function test_delay_before_fixed_is_the_constant_interval_across_the_full_attempt_range(): void
    {
        Config::set('retry.fixed_interval_seconds', 300);

        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => RetryBackoffStrategy::Fixed]);
        $policy = new RetryPolicy;

        foreach (range(2, 10) as $attemptNumber) {
            $this->assertSame(300, (int) $policy->delayBefore($proxy, $attemptNumber)->totalSeconds);
        }
    }

    // --- worstCaseSpan ------------------------------------------------------

    public function test_worst_case_span_for_the_default_config_is_approximately_32_point_6_hours(): void
    {
        Config::set('retry.exponential_base_seconds', 60);
        Config::set('retry.exponential_multiplier', 5);
        Config::set('retry.exponential_max_delay_seconds', 21600);
        Config::set('retry.max_attempt_limit', 10);

        $span = (new RetryPolicy)->worstCaseSpan();

        // 60 + 300 + 1500 + 7500 + (21600 * 5) = 117360 seconds = 32.6 hours.
        $this->assertSame(117360, (int) $span->totalSeconds);
        $this->assertEqualsWithDelta(32.6, $span->totalHours, 0.01);
    }

    // --- AC18 guard: worstCaseSpan() bounded well inside the retention window
    // (T20; ADR-015 Decision 4) ---------------------------------------------

    public function test_worst_case_span_stays_well_inside_the_retention_window(): void
    {
        // Defaults from config/retry.php: base=60, multiplier=5, cap=21600, limit=10.
        Config::set('retry.exponential_base_seconds', 60);
        Config::set('retry.exponential_multiplier', 5);
        Config::set('retry.exponential_max_delay_seconds', 21600);
        Config::set('retry.max_attempt_limit', 10);

        $span = (new RetryPolicy)->worstCaseSpan();
        $window = (new RetentionPolicy)->windowFor(Team::factory()->createQuietly());

        // ~32.6h (T11) is a small fraction of the default 30-day retention window;
        // asserted against a fixed 3-day intermediate bound (not the window itself)
        // so a future retry-config change trips this test loudly long before it
        // could ever threaten AC18, rather than only when it reaches 30 days.
        $this->assertLessThanOrEqual(3 * 24 * 3600, $span->totalSeconds);
        $this->assertLessThanOrEqual($window->totalSeconds, 3 * 24 * 3600);
    }

    public function test_worst_case_span_guard_would_catch_a_regression_that_blows_the_bound(): void
    {
        // A deliberately mis-configured max_attempt_limit (config-side, not the
        // per-proxy column) — proves the 3-day bound actually constrains
        // something, i.e. is not a tautology: this override alone pushes
        // worstCaseSpan() past the bound the default-config test asserts.
        Config::set('retry.exponential_base_seconds', 60);
        Config::set('retry.exponential_multiplier', 5);
        Config::set('retry.exponential_max_delay_seconds', 21600);
        Config::set('retry.max_attempt_limit', 30);

        $span = (new RetryPolicy)->worstCaseSpan();

        $this->assertGreaterThan(3 * 24 * 3600, $span->totalSeconds);
    }

    public function test_worst_case_span_guard_would_catch_a_regression_that_raises_the_delay_cap(): void
    {
        // Same proof via the other named lever: a blown exponential_max_delay_seconds
        // alone, attempt limit held at the product default.
        Config::set('retry.exponential_base_seconds', 60);
        Config::set('retry.exponential_multiplier', 5);
        Config::set('retry.exponential_max_delay_seconds', 1_000_000);
        Config::set('retry.max_attempt_limit', 10);

        $span = (new RetryPolicy)->worstCaseSpan();

        $this->assertGreaterThan(3 * 24 * 3600, $span->totalSeconds);
    }

    // --- Config sanity guards (mirroring RetentionPolicyTest / review-05 M-1) --

    public function test_attempt_limit_for_throws_when_default_attempt_limit_is_zero(): void
    {
        Config::set('retry.default_attempt_limit', 0);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("config('retry.default_attempt_limit')");

        (new RetryPolicy)->attemptLimitFor($proxy);
    }

    public function test_attempt_limit_for_throws_when_default_attempt_limit_is_negative(): void
    {
        Config::set('retry.default_attempt_limit', -1);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => null]);

        $this->expectException(RuntimeException::class);

        (new RetryPolicy)->attemptLimitFor($proxy);
    }

    public function test_attempt_limit_for_throws_when_default_attempt_limit_env_value_is_blank(): void
    {
        // Reproduces review-05 finding 1(a)'s pattern: a blank env value casts to 0.
        putenv('RETRY_DEFAULT_ATTEMPT_LIMIT=');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_DEFAULT_ATTEMPT_LIMIT');
        }

        Config::set('retry.default_attempt_limit', $resolved['default_attempt_limit']);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => null]);

        $this->expectException(RuntimeException::class);

        (new RetryPolicy)->attemptLimitFor($proxy);
    }

    public function test_attempt_limit_for_throws_when_default_attempt_limit_env_value_is_non_numeric(): void
    {
        putenv('RETRY_DEFAULT_ATTEMPT_LIMIT=not-a-number');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_DEFAULT_ATTEMPT_LIMIT');
        }

        Config::set('retry.default_attempt_limit', $resolved['default_attempt_limit']);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => null]);

        $this->expectException(RuntimeException::class);

        (new RetryPolicy)->attemptLimitFor($proxy);
    }

    public function test_attempt_limit_for_throws_when_max_attempt_limit_is_zero(): void
    {
        Config::set('retry.max_attempt_limit', 0);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => 3]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("config('retry.max_attempt_limit')");

        (new RetryPolicy)->attemptLimitFor($proxy);
    }

    public function test_attempt_limit_for_throws_when_max_attempt_limit_is_negative(): void
    {
        Config::set('retry.max_attempt_limit', -5);
        $proxy = Proxy::factory()->createQuietly(['retry_attempt_limit' => 3]);

        $this->expectException(RuntimeException::class);

        (new RetryPolicy)->attemptLimitFor($proxy);
    }

    public function test_delay_before_throws_when_exponential_base_seconds_is_zero(): void
    {
        Config::set('retry.exponential_base_seconds', 0);
        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => RetryBackoffStrategy::Exponential]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("config('retry.exponential_base_seconds')");

        (new RetryPolicy)->delayBefore($proxy, 2);
    }

    public function test_delay_before_throws_when_exponential_base_seconds_is_negative(): void
    {
        Config::set('retry.exponential_base_seconds', -60);
        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => RetryBackoffStrategy::Exponential]);

        $this->expectException(RuntimeException::class);

        (new RetryPolicy)->delayBefore($proxy, 2);
    }

    public function test_delay_before_throws_when_fixed_interval_seconds_is_zero(): void
    {
        Config::set('retry.fixed_interval_seconds', 0);
        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => RetryBackoffStrategy::Fixed]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("config('retry.fixed_interval_seconds')");

        (new RetryPolicy)->delayBefore($proxy, 2);
    }

    public function test_delay_before_throws_when_fixed_interval_seconds_is_negative(): void
    {
        Config::set('retry.fixed_interval_seconds', -300);
        $proxy = Proxy::factory()->createQuietly(['retry_backoff_strategy' => RetryBackoffStrategy::Fixed]);

        $this->expectException(RuntimeException::class);

        (new RetryPolicy)->delayBefore($proxy, 2);
    }

    public function test_worst_case_span_throws_when_max_attempt_limit_is_zero(): void
    {
        Config::set('retry.max_attempt_limit', 0);

        $this->expectException(RuntimeException::class);

        (new RetryPolicy)->worstCaseSpan();
    }
}
