<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class IngestConfigTest extends TestCase
{
    public function test_ingest_url_falls_back_to_app_url_when_env_not_set(): void
    {
        // INGEST_URL is unset in the testing environment, so the ingest host
        // must mirror the application URL (config('app.url')).
        $this->assertSame(config('app.url'), config('ingest.url'));
    }

    public function test_ingest_url_uses_env_override_when_set(): void
    {
        putenv('INGEST_URL=https://ingest.example.test');

        try {
            $resolved = require base_path('config/ingest.php');
        } finally {
            putenv('INGEST_URL');
        }

        $this->assertSame('https://ingest.example.test', $resolved['url']);
    }

    public function test_response_body_max_bytes_defaults_to_8_kib_when_env_not_set(): void
    {
        // INGEST_RESPONSE_BODY_MAX_BYTES is unset in the testing environment,
        // so the response-body cap must fall back to the 8 KiB default.
        $this->assertSame(8192, config('ingest.response_body_max_bytes'));
    }

    public function test_response_body_max_bytes_uses_env_override_when_set(): void
    {
        putenv('INGEST_RESPONSE_BODY_MAX_BYTES=16384');

        try {
            $resolved = require base_path('config/ingest.php');
        } finally {
            putenv('INGEST_RESPONSE_BODY_MAX_BYTES');
        }

        $this->assertSame(16384, $resolved['response_body_max_bytes']);
    }

    public function test_fifo_lease_seconds_defaults_to_90_when_env_not_set(): void
    {
        $this->assertSame(90, config('ingest.fifo_lease_seconds'));
    }

    public function test_fifo_lease_seconds_uses_env_override_when_set(): void
    {
        putenv('INGEST_FIFO_LEASE_SECONDS=120');

        try {
            $resolved = require base_path('config/ingest.php');
        } finally {
            putenv('INGEST_FIFO_LEASE_SECONDS');
        }

        $this->assertSame(120, $resolved['fifo_lease_seconds']);
    }

    public function test_webhooks_queue_defaults_to_webhooks_when_env_not_set(): void
    {
        $this->assertSame('webhooks', config('ingest.webhooks_queue'));
    }

    public function test_webhooks_queue_uses_env_override_when_set(): void
    {
        putenv('INGEST_WEBHOOKS_QUEUE=deliveries');

        try {
            $resolved = require base_path('config/ingest.php');
        } finally {
            putenv('INGEST_WEBHOOKS_QUEUE');
        }

        $this->assertSame('deliveries', $resolved['webhooks_queue']);
    }
}
