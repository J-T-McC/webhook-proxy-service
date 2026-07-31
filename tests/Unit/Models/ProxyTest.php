<?php

namespace Tests\Unit\Models;

use App\Enums\ProxyMode;
use App\Models\Proxy;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProxyTest extends TestCase
{
    public function test_ingest_token_round_trips_through_the_encrypted_cast(): void
    {
        $proxy = Proxy::factory()->create(['ingest_token' => 'plain-secret-token']);

        // Decrypted via the cast on read.
        $this->assertSame('plain-secret-token', $proxy->fresh()->ingest_token);

        // Stored ciphertext at rest is not the plaintext.
        $raw = DB::table('proxies')->where('id', $proxy->id)->value('ingest_token');
        $this->assertNotSame('plain-secret-token', $raw);
    }

    public function test_mode_casts_to_enum_and_defaults_to_simple(): void
    {
        $proxy = Proxy::factory()->create();
        $this->assertInstanceOf(ProxyMode::class, $proxy->mode);

        // DB-level default: insert omitting mode.
        $team = Team::factory()->create();
        $token = random_bytes(8);
        $id = DB::table('proxies')->insertGetId([
            'team_id' => $team->id,
            'name' => 'defaulted',
            'ingest_token' => 'x',
            'ingest_token_hash' => hash('sha256', $token, binary: true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(ProxyMode::Simple, Proxy::findOrFail($id)->mode);
    }

    public function test_ingest_token_hash_is_binary_32_with_single_column_unique_index(): void
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH AS len
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['proxies', 'ingest_token_hash'],
        );

        $this->assertSame('binary', strtolower((string) $column->DATA_TYPE));
        $this->assertSame(32, (int) $column->len);

        // Single-column UNIQUE index on ingest_token_hash (not composite with deleted_at).
        $indexes = DB::select(
            'SELECT INDEX_NAME, COUNT(*) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME',
            ['proxies'],
        );

        $hashIndex = collect($indexes)->first(
            fn ($i) => str_contains(strtolower($i->INDEX_NAME), 'ingest_token_hash'),
        );

        $this->assertNotNull($hashIndex, 'Expected a unique index on ingest_token_hash.');
        $this->assertSame(1, (int) $hashIndex->cols, 'Unique index must be single-column.');
    }

    public function test_duplicate_ingest_token_hash_is_rejected(): void
    {
        $hash = hash('sha256', 'dup', binary: true);
        Proxy::factory()->create(['ingest_token_hash' => $hash]);

        $this->expectException(QueryException::class);
        Proxy::factory()->create(['ingest_token_hash' => $hash]);
    }

    public function test_delete_soft_deletes_and_hides_from_default_queries(): void
    {
        $proxy = Proxy::factory()->create();
        $proxy->delete();

        $this->assertSoftDeleted($proxy);
        $this->assertNull(Proxy::find($proxy->id));
    }
}
