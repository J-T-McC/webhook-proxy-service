<?php

namespace Tests\Unit\Services;

use App\Models\Proxy;
use App\Services\IngestTokenService;
use Mockery;
use Tests\TestCase;

class IngestTokenServiceTest extends TestCase
{
    public function test_generated_token_is_256_bit_and_url_safe(): void
    {
        $service = new IngestTokenService;

        $token = $service->generate();

        // URL-safe base64url alphabet only (no +, /, or = padding).
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);

        // Decodes back to exactly 32 bytes (256 bits) of entropy.
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen((string) $decoded));
    }

    public function test_two_generations_do_not_collide(): void
    {
        $service = new IngestTokenService;

        $this->assertNotSame($service->generate(), $service->generate());
    }

    public function test_assign_sets_encrypted_token_and_binary_hash_that_round_trips(): void
    {
        $service = new IngestTokenService;
        $proxy = Proxy::factory()->make();

        $service->assignTo($proxy);
        $plain = $proxy->ingest_token;
        $proxy->save();

        // Hash is SHA-256 (32 raw bytes) of the plaintext token.
        $this->assertSame(hash('sha256', $plain, binary: true), $proxy->ingest_token_hash);
        $this->assertSame(32, strlen($proxy->ingest_token_hash));

        // Decrypts back to the plaintext for display (AC12d).
        $this->assertSame($plain, $proxy->fresh()->ingest_token);
    }

    public function test_ingest_url_is_built_from_config_not_the_request_host(): void
    {
        config()->set('ingest.url', 'https://ingest.example.test/');

        $proxy = Proxy::factory()->create(['ingest_token' => 'the-token']);

        $this->assertSame('https://ingest.example.test/ingest/the-token', $proxy->ingestUrl());
    }

    public function test_hash_collision_regenerates_the_token(): void
    {
        // Seed an existing proxy occupying the hash of 'collide'.
        Proxy::factory()->create([
            'ingest_token' => 'collide',
            'ingest_token_hash' => hash('sha256', 'collide', binary: true),
        ]);

        // Force generate() to first return the colliding value, then a fresh one.
        $service = Mockery::mock(IngestTokenService::class)->makePartial();
        $service->shouldReceive('generate')->andReturn('collide', 'fresh-token');

        $proxy = Proxy::factory()->make();
        $service->assignTo($proxy);

        $this->assertSame('fresh-token', $proxy->ingest_token);
        $this->assertSame(hash('sha256', 'fresh-token', binary: true), $proxy->ingest_token_hash);
    }
}
