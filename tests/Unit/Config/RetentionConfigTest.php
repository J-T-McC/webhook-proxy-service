<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class RetentionConfigTest extends TestCase
{
    public function test_days_defaults_to_30_when_env_not_set(): void
    {
        $this->assertSame(30, config('retention.days'));
    }

    public function test_days_uses_env_override_when_set(): void
    {
        putenv('RETENTION_DAYS=45');

        try {
            $resolved = require base_path('config/retention.php');
        } finally {
            putenv('RETENTION_DAYS');
        }

        $this->assertSame(45, $resolved['days']);
    }

    public function test_purge_batch_defaults_to_500_when_env_not_set(): void
    {
        $this->assertSame(500, config('retention.purge_batch'));
    }

    public function test_purge_batch_uses_env_override_when_set(): void
    {
        putenv('RETENTION_PURGE_BATCH=250');

        try {
            $resolved = require base_path('config/retention.php');
        } finally {
            putenv('RETENTION_PURGE_BATCH');
        }

        $this->assertSame(250, $resolved['purge_batch']);
    }

    public function test_dispatch_horizon_minutes_defaults_to_60_when_env_not_set(): void
    {
        $this->assertSame(60, config('retention.dispatch_horizon_minutes'));
    }

    public function test_dispatch_horizon_minutes_uses_env_override_when_set(): void
    {
        putenv('RETENTION_DISPATCH_HORIZON_MINUTES=15');

        try {
            $resolved = require base_path('config/retention.php');
        } finally {
            putenv('RETENTION_DISPATCH_HORIZON_MINUTES');
        }

        $this->assertSame(15, $resolved['dispatch_horizon_minutes']);
    }
}
