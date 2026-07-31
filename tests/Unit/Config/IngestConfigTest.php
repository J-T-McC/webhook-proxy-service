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
}
