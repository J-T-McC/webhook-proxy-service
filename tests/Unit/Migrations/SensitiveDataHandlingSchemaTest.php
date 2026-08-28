<?php

namespace Tests\Unit\Migrations;

use App\Models\Proxy;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SensitiveDataHandlingSchemaTest extends TestCase
{
    /**
     * @return array<string, string> column name => lowercase DATA_TYPE
     */
    private function columnTypesFor(string $table): array
    {
        return collect(DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             ORDER BY ORDINAL_POSITION',
            [$table],
        ))->mapWithKeys(fn ($row) => [
            (string) $row->COLUMN_NAME => strtolower((string) $row->DATA_TYPE).'|'.strtoupper((string) $row->IS_NULLABLE),
        ])->all();
    }

    /**
     * @return array<string, string> index name => comma-joined, ordinal-ordered column list
     */
    private function indexColumnsFor(string $table): array
    {
        return collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            [$table],
        ))->mapWithKeys(fn ($row) => [
            (string) $row->INDEX_NAME => strtolower((string) $row->cols),
        ])->all();
    }

    public function test_proxy_secrets_table_has_exactly_the_nine_columns_and_the_one_unique_index(): void
    {
        $columns = $this->columnTypesFor('proxy_secrets');

        $this->assertEqualsCanonicalizing([
            'id', 'team_id', 'proxy_id', 'purpose', 'value', 'is_current', 'expires_at', 'created_at', 'updated_at',
        ], array_keys($columns));

        $this->assertSame('bigint|NO', $columns['id']);
        $this->assertSame('bigint|NO', $columns['team_id']);
        $this->assertSame('bigint|NO', $columns['proxy_id']);
        $this->assertSame('varchar|NO', $columns['purpose']);
        $this->assertSame('text|NO', $columns['value']);
        $this->assertSame('tinyint|YES', $columns['is_current']);
        $this->assertSame('timestamp|YES', $columns['expires_at']);
        $this->assertSame('timestamp|YES', $columns['created_at']);
        $this->assertSame('timestamp|YES', $columns['updated_at']);

        $indexes = $this->indexColumnsFor('proxy_secrets');
        $this->assertSame(
            'proxy_id,purpose,is_current',
            $indexes['proxy_secrets_proxy_id_purpose_is_current_unique'] ?? null,
        );
    }

    public function test_proxies_gains_exactly_its_three_new_columns_and_every_pre_existing_index_survives(): void
    {
        $columns = $this->columnTypesFor('proxies');

        $this->assertSame('varchar|YES', $columns['verification_scheme']);
        $this->assertSame('varchar|YES', $columns['verification_header_name']);
        $this->assertSame('longtext|YES', $columns['sensitive_fields']);

        $indexes = array_values($this->indexColumnsFor('proxies'));
        $this->assertContains('ingest_token_hash', $indexes);
        $this->assertContains('team_id', $indexes);
    }

    public function test_destinations_gains_exactly_its_three_new_columns_and_every_pre_existing_index_survives(): void
    {
        $columns = $this->columnTypesFor('destinations');

        $this->assertSame('varchar|YES', $columns['credential_header_name']);
        $this->assertSame('text|YES', $columns['credential_secret']);
        $this->assertSame('timestamp|YES', $columns['credential_set_at']);

        $indexes = array_values($this->indexColumnsFor('destinations'));
        $this->assertContains('proxy_id', $indexes);
        $this->assertContains('team_id', $indexes);
    }

    public function test_unique_index_rejects_a_duplicate_current_row_and_allows_any_number_of_superseded_rows(): void
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);

        DB::table('proxy_secrets')->insert([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'purpose' => 'verification',
            'value' => 'ciphertext-a',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('proxy_secrets')->insert([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'purpose' => 'verification',
            'value' => 'ciphertext-b',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unique_index_allows_multiple_superseded_rows_with_null_is_current(): void
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);

        for ($i = 0; $i < 3; $i++) {
            DB::table('proxy_secrets')->insert([
                'team_id' => $team->id,
                'proxy_id' => $proxy->id,
                'purpose' => 'verification',
                'value' => "ciphertext-{$i}",
                'is_current' => null,
                'expires_at' => now()->addHours(24),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, DB::table('proxy_secrets')->where('proxy_id', $proxy->id)->count());
    }

    public function test_rollback_removes_exactly_proxy_secrets_and_the_six_new_columns_and_reapplying_restores_them(): void
    {
        $migration = '2026_08_27_000001_add_sensitive_data_handling_schema';

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertFalse(Schema::hasTable('proxy_secrets'));
        $this->assertFalse(Schema::hasColumn('proxies', 'verification_scheme'));
        $this->assertFalse(Schema::hasColumn('proxies', 'verification_header_name'));
        $this->assertFalse(Schema::hasColumn('proxies', 'sensitive_fields'));
        $this->assertFalse(Schema::hasColumn('destinations', 'credential_header_name'));
        $this->assertFalse(Schema::hasColumn('destinations', 'credential_secret'));
        $this->assertFalse(Schema::hasColumn('destinations', 'credential_set_at'));

        // Every pre-existing table, column and index is still present post-rollback.
        $this->assertTrue(Schema::hasTable('proxies'));
        $this->assertTrue(Schema::hasTable('destinations'));
        $indexes = array_values($this->indexColumnsFor('proxies'));
        $this->assertContains('ingest_token_hash', $indexes);

        $this->assertNotContains($migration, DB::table('migrations')->pluck('migration')->all());

        Artisan::call('migrate');

        $this->assertContains($migration, DB::table('migrations')->pluck('migration')->all());
        $this->assertTrue(Schema::hasTable('proxy_secrets'));
        $this->assertTrue(Schema::hasColumn('proxies', 'verification_scheme'));
        $this->assertTrue(Schema::hasColumn('destinations', 'credential_secret'));
    }
}
