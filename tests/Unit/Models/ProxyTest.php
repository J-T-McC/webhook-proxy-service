<?php

namespace Tests\Unit\Models;

use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProxyTest extends TestCase
{
    public function test_ingest_token_round_trips_through_the_encrypted_cast(): void
    {
        $proxy = Proxy::factory()->createQuietly(['ingest_token' => 'plain-secret-token']);

        // Decrypted via the cast on read.
        $this->assertSame('plain-secret-token', $proxy->fresh()->ingest_token);

        // Stored ciphertext at rest is not the plaintext.
        $raw = DB::table('proxies')->where('id', $proxy->id)->value('ingest_token');
        $this->assertNotSame('plain-secret-token', $raw);
    }

    public function test_mode_casts_to_enum_and_defaults_to_simple(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $this->assertInstanceOf(ProxyMode::class, $proxy->mode);

        // DB-level default: insert omitting mode.
        $team = Team::factory()->createQuietly();
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
        Proxy::factory()->createQuietly(['ingest_token_hash' => $hash]);

        $this->expectException(QueryException::class);
        Proxy::factory()->createQuietly(['ingest_token_hash' => $hash]);
    }

    public function test_created_by_is_nullable_and_fk_to_users_with_set_null_on_delete(): void
    {
        $column = DB::selectOne(
            'SELECT IS_NULLABLE, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['proxies', 'created_by'],
        );

        $this->assertNotNull($column, 'Expected a created_by column on proxies.');
        $this->assertSame('YES', strtoupper((string) $column->IS_NULLABLE), 'created_by must be nullable.');

        $fk = DB::selectOne(
            'SELECT k.REFERENCED_TABLE_NAME AS ref_table, r.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
             WHERE k.TABLE_NAME = ? AND k.COLUMN_NAME = ? AND k.TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            ['proxies', 'created_by'],
        );

        $this->assertNotNull($fk, 'Expected a foreign key on proxies.created_by.');
        $this->assertSame('users', strtolower((string) $fk->ref_table), 'created_by must reference users.');
        $this->assertSame('SET NULL', strtoupper((string) $fk->delete_rule), 'created_by FK must be ON DELETE SET NULL, not cascade.');
    }

    public function test_creator_is_nulled_when_the_creating_user_is_deleted(): void
    {
        $user = User::factory()->createQuietly();
        $this->actingAs($user);

        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'created_by' => $user->id,
        ]);

        $user->delete();

        // nullOnDelete: the proxy survives, its creator falls back to null.
        $this->assertNull($proxy->fresh()->created_by);
    }

    public function test_delete_soft_deletes_and_hides_from_default_queries(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $proxy->delete();

        $this->assertSoftDeleted($proxy);
        $this->assertNull(Proxy::find($proxy->id));
    }

    public function test_response_config_columns_are_nullable_with_no_schema_default(): void
    {
        foreach (['response_status', 'response_body'] as $name) {
            $column = DB::selectOne(
                'SELECT IS_NULLABLE, COLUMN_DEFAULT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
                ['proxies', $name],
            );

            $this->assertNotNull($column, "Expected a {$name} column on proxies.");
            $this->assertSame('YES', strtoupper((string) $column->IS_NULLABLE), "{$name} must be nullable.");
            $this->assertNull($column->COLUMN_DEFAULT, "{$name} must have no schema default (202 is owned by ResponseResolver).");
        }
    }

    public function test_existing_proxy_row_has_null_response_config(): void
    {
        // A factory-made proxy (no response config supplied) simulates a pre-#3 row:
        // the migration writes no value, so both columns stay NULL (AC3, no backfill).
        $proxy = Proxy::factory()->createQuietly();

        $this->assertNull($proxy->response_status);
        $this->assertNull($proxy->response_body);
    }

    public function test_response_status_round_trips_through_the_integer_cast(): void
    {
        $proxy = Proxy::factory()->createQuietly([
            'response_status' => 201,
            'response_body' => 'ok',
        ]);

        $fresh = $proxy->fresh();
        $this->assertSame(201, $fresh->response_status);
        $this->assertSame('ok', $fresh->response_body);

        // Unset stays null through the cast.
        $unset = Proxy::factory()->createQuietly();
        $this->assertNull($unset->fresh()->response_status);
    }

    public function test_processing_mode_is_not_null_with_schema_default_async(): void
    {
        $column = DB::selectOne(
            'SELECT IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['proxies', 'processing_mode'],
        );

        $this->assertNotNull($column, 'Expected a processing_mode column on proxies.');
        $this->assertSame('NO', strtoupper((string) $column->IS_NULLABLE), 'processing_mode must be NOT NULL.');
        $this->assertSame('async', (string) $column->COLUMN_DEFAULT, 'processing_mode must default to async.');
    }

    public function test_existing_proxy_row_reads_async_with_no_backfill(): void
    {
        // A factory-made proxy that supplies no processing_mode simulates a pre-#4
        // (#1/#3) row: the schema default applies, so it reads Async with no backfill.
        $team = Team::factory()->createQuietly();
        $token = random_bytes(8);
        $id = DB::table('proxies')->insertGetId([
            'team_id' => $team->id,
            'name' => 'pre-existing',
            'ingest_token' => 'x',
            'ingest_token_hash' => hash('sha256', $token, binary: true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(ProcessingMode::Async, Proxy::findOrFail($id)->processing_mode);
    }

    public function test_processing_mode_round_trips_through_the_enum_cast(): void
    {
        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);

        $fresh = $proxy->fresh();
        $this->assertInstanceOf(ProcessingMode::class, $fresh->processing_mode);
        $this->assertSame(ProcessingMode::Fifo, $fresh->processing_mode);
    }
}
