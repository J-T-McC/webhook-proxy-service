<?php

namespace Tests\Feature\Ingest;

use App\Models\Proxy;
use App\Services\IngestTokenService;
use App\Services\ProxyLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The ingest proxy lookup cache and, mostly, its invalidation.
 *
 * The cache itself is a one-liner; what has to hold is that a write takes
 * effect at once. A proxy row carries `paused_at` and `deleted_at`, so a stale
 * entry means a paused or deleted proxy keeps ingesting — which is why these
 * tests weigh more than the hit-count one.
 */
class ProxyLookupCacheTest extends TestCase
{
    use RefreshDatabase;

    private function hashFor(Proxy $proxy): string
    {
        return app(IngestTokenService::class)->hash($proxy->ingest_token);
    }

    public function test_a_second_lookup_of_the_same_token_issues_no_query(): void
    {
        $proxy = Proxy::factory()->create();
        $hash = $this->hashFor($proxy);
        $lookup = app(ProxyLookup::class);

        $lookup->byTokenHash($hash);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $found = $lookup->byTokenHash($hash);

        $this->assertSame($proxy->id, $found?->id);
        $this->assertSame(0, $queries, 'The cached lookup still queried the database.');
    }

    public function test_pausing_a_proxy_takes_effect_immediately(): void
    {
        $proxy = Proxy::factory()->create();
        $hash = $this->hashFor($proxy);
        $lookup = app(ProxyLookup::class);

        $this->assertNull($lookup->byTokenHash($hash)?->paused_at);

        // The same call the pause controller makes.
        $proxy->forceFill(['paused_at' => now()])->save();

        $this->assertNotNull(
            $lookup->byTokenHash($hash)?->paused_at,
            'A paused proxy was still served from cache as unpaused.'
        );
    }

    public function test_soft_deleting_a_proxy_takes_effect_immediately(): void
    {
        $proxy = Proxy::factory()->create();
        $hash = $this->hashFor($proxy);
        $lookup = app(ProxyLookup::class);

        $lookup->byTokenHash($hash);
        $proxy->delete();

        $this->assertNull(
            $lookup->byTokenHash($hash),
            'A soft-deleted proxy was still served from cache.'
        );
    }

    public function test_rotating_the_token_stops_the_old_one_working(): void
    {
        $proxy = Proxy::factory()->create();
        $oldHash = $this->hashFor($proxy);
        $lookup = app(ProxyLookup::class);

        $lookup->byTokenHash($oldHash);

        app(IngestTokenService::class)->rotate($proxy);

        $this->assertNull(
            $lookup->byTokenHash($oldHash),
            'The superseded token still resolved from cache after a rotation.'
        );
        $this->assertSame($proxy->id, $lookup->byTokenHash($this->hashFor($proxy->fresh()))?->id);
    }

    public function test_an_unknown_token_is_not_cached(): void
    {
        $lookup = app(ProxyLookup::class);
        $unknown = hash('sha256', 'nobody', binary: true);

        $this->assertNull($lookup->byTokenHash($unknown));

        // Caching misses would let anyone probing the endpoint fill the cache
        // with keys that can never be hit.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $lookup->byTokenHash($unknown);

        $this->assertGreaterThan(0, $queries, 'A miss was cached.');
    }

    /**
     * The `database` cache driver stores its value in a utf8mb4 text column, and
     * `proxies.ingest_token_hash` is BINARY(32). Caching raw attributes therefore
     * worked on Redis and threw "Incorrect string value" on the driver
     * `config/cache.php` actually defaults to — a 500 on every ingest, caught by
     * the end-to-end suite rather than by these tests, which ran on the
     * binary-safe array store.
     */
    public function test_the_row_caches_on_a_driver_whose_column_rejects_raw_binary(): void
    {
        config(['cache.default' => 'database']);

        $proxy = Proxy::factory()->create();
        $hash = $this->hashFor($proxy);
        $lookup = app(ProxyLookup::class);

        $this->assertSame($proxy->id, $lookup->byTokenHash($hash)?->id);
        $this->assertSame($proxy->id, $lookup->byTokenHash($hash)?->id, 'The cached read failed.');
        $this->assertSame($proxy->id, $lookup->byIdWithTrashed($proxy->id)?->id);
        $this->assertSame($proxy->id, $lookup->byIdWithTrashed($proxy->id)?->id);
    }

    public function test_the_ingest_endpoint_rejects_a_paused_proxy_without_waiting_for_expiry(): void
    {
        $proxy = Proxy::factory()->create();

        $this->postJson("/ingest/{$proxy->ingest_token}", ['a' => 1], ['X-Forwarded-Proto' => 'https'])
            ->assertAccepted();

        $proxy->forceFill(['paused_at' => now()])->save();

        // Still accepted — pause holds dispatch rather than refusing capture
        // (#15) — but the proxy the request resolves must be the paused one.
        $this->postJson("/ingest/{$proxy->ingest_token}", ['a' => 1], ['X-Forwarded-Proto' => 'https'])
            ->assertAccepted();

        $this->assertNotNull($proxy->fresh()?->paused_at);
    }
}
