<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class RetryConfigTest extends TestCase
{
    public function test_default_attempt_limit_defaults_to_5_when_env_not_set(): void
    {
        $this->assertSame(5, config('retry.default_attempt_limit'));
    }

    public function test_default_attempt_limit_uses_env_override_when_set(): void
    {
        putenv('RETRY_DEFAULT_ATTEMPT_LIMIT=3');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_DEFAULT_ATTEMPT_LIMIT');
        }

        $this->assertSame(3, $resolved['default_attempt_limit']);
    }

    public function test_max_attempt_limit_defaults_to_10_when_env_not_set(): void
    {
        $this->assertSame(10, config('retry.max_attempt_limit'));
    }

    public function test_max_attempt_limit_uses_env_override_when_set(): void
    {
        putenv('RETRY_MAX_ATTEMPT_LIMIT=8');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_MAX_ATTEMPT_LIMIT');
        }

        $this->assertSame(8, $resolved['max_attempt_limit']);
    }

    public function test_exponential_base_seconds_defaults_to_60_when_env_not_set(): void
    {
        $this->assertSame(60, config('retry.exponential_base_seconds'));
    }

    public function test_exponential_base_seconds_uses_env_override_when_set(): void
    {
        putenv('RETRY_EXPONENTIAL_BASE_SECONDS=90');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_EXPONENTIAL_BASE_SECONDS');
        }

        $this->assertSame(90, $resolved['exponential_base_seconds']);
    }

    public function test_exponential_multiplier_defaults_to_5_when_env_not_set(): void
    {
        $this->assertSame(5, config('retry.exponential_multiplier'));
    }

    public function test_exponential_multiplier_uses_env_override_when_set(): void
    {
        putenv('RETRY_EXPONENTIAL_MULTIPLIER=3');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_EXPONENTIAL_MULTIPLIER');
        }

        $this->assertSame(3, $resolved['exponential_multiplier']);
    }

    public function test_exponential_max_delay_seconds_defaults_to_21600_when_env_not_set(): void
    {
        $this->assertSame(21600, config('retry.exponential_max_delay_seconds'));
    }

    public function test_exponential_max_delay_seconds_uses_env_override_when_set(): void
    {
        putenv('RETRY_EXPONENTIAL_MAX_DELAY_SECONDS=7200');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_EXPONENTIAL_MAX_DELAY_SECONDS');
        }

        $this->assertSame(7200, $resolved['exponential_max_delay_seconds']);
    }

    public function test_fixed_interval_seconds_defaults_to_300_when_env_not_set(): void
    {
        $this->assertSame(300, config('retry.fixed_interval_seconds'));
    }

    public function test_fixed_interval_seconds_uses_env_override_when_set(): void
    {
        putenv('RETRY_FIXED_INTERVAL_SECONDS=600');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_FIXED_INTERVAL_SECONDS');
        }

        $this->assertSame(600, $resolved['fixed_interval_seconds']);
    }

    public function test_sweep_grace_seconds_defaults_to_120_when_env_not_set(): void
    {
        $this->assertSame(120, config('retry.sweep_grace_seconds'));
    }

    public function test_sweep_grace_seconds_uses_env_override_when_set(): void
    {
        putenv('RETRY_SWEEP_GRACE_SECONDS=30');

        try {
            $resolved = require base_path('config/retry.php');
        } finally {
            putenv('RETRY_SWEEP_GRACE_SECONDS');
        }

        $this->assertSame(30, $resolved['sweep_grace_seconds']);
    }
}
