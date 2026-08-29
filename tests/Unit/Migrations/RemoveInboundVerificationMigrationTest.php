<?php

namespace Tests\Unit\Migrations;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T54 (ADR-026 Decision 4) — `2026_08_28_000001_remove_inbound_verification`.
 * The test suite runs with every migration already applied (T1, then this
 * one), so several tests here roll back exactly this migration in isolation
 * to exercise its own `up()`/`down()` independently of the rest of the
 * schema — the pre-existing-column-survival assertions prove the boundary
 * this migration promises not to cross.
 */
class RemoveInboundVerificationMigrationTest extends TestCase
{
    private const MIGRATION = '2026_08_28_000001_remove_inbound_verification';

    /**
     * Roll back exactly {@see MIGRATION}, however many later migrations now
     * sit on top of it (item #15's `add_paused_at_to_proxies_table` is one).
     * `--step` alone rolls back the N most-recently-run migrations by
     * position, not by name, so a bare `--step=1` silently rolls back
     * whatever migration is newest instead of this one once anything ships
     * after it. Combining a wide-enough `--step` with `--path` restricted to
     * this migration's own file rolls back only this file: `rollbackMigrations`
     * skips (leaves applied) any selected migration whose file isn't in the
     * given path, so every later migration is left untouched.
     */
    private function rollbackThisMigrationOnly(): void
    {
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--step' => $this->stepsToReach(self::MIGRATION),
        ]);
    }

    /**
     * How many of the most-recently-run migrations (by position) must be
     * selected for rollback to reach `$migration`, counting from the top.
     */
    private function stepsToReach(string $migration): int
    {
        $position = DB::table('migrations')
            ->orderByDesc('id')
            ->pluck('migration')
            ->search($migration);

        return $position === false ? 1 : $position + 1;
    }

    /**
     * @return array<string, string> column name => lowercase DATA_TYPE|IS_NULLABLE
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

    public function test_proxies_ends_up_with_exactly_one_of_t1s_three_added_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('proxies', 'verification_scheme'));
        $this->assertFalse(Schema::hasColumn('proxies', 'verification_header_name'));
        $this->assertTrue(Schema::hasColumn('proxies', 'sensitive_fields'));
    }

    public function test_it_deletes_every_verification_purpose_secret_and_leaves_signing_untouched(): void
    {
        // Roll back exactly this migration (it is the latest applied), so
        // the two `proxies` columns exist again and rows of purpose
        // `verification` can be seeded — `SecretPurpose::Verification` no
        // longer exists as an enum case, so these are inserted directly
        // through the query builder, the same way a pre-#54 database's
        // rows would already exist on disk.
        // DDL (the two Artisan migrate calls) implicitly commits on MySQL,
        // escaping RefreshDatabase's per-test transaction sandbox — any row
        // created between them survives this test regardless of pass/fail
        // and would otherwise leak into every later test in this worker's
        // database. `finally` deletes it explicitly rather than relying on
        // transactional rollback, which does not apply once DDL has run.
        $this->rollbackThisMigrationOnly();

        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);

        try {
            DB::table('proxy_secrets')->insert([
                [
                    'team_id' => $team->id,
                    'proxy_id' => $proxy->id,
                    'purpose' => 'verification',
                    'value' => 'current-verification-ciphertext',
                    'is_current' => true,
                    'expires_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'team_id' => $team->id,
                    'proxy_id' => $proxy->id,
                    'purpose' => 'verification',
                    'value' => 'superseded-verification-ciphertext',
                    'is_current' => null,
                    'expires_at' => now()->addHours(24),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'team_id' => $team->id,
                    'proxy_id' => $proxy->id,
                    'purpose' => 'signing',
                    'value' => 'current-signing-ciphertext',
                    'is_current' => true,
                    'expires_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            Artisan::call('migrate');

            $this->assertSame(
                0,
                DB::table('proxy_secrets')->where('proxy_id', $proxy->id)->where('purpose', 'verification')->count(),
            );
            $this->assertSame(
                1,
                DB::table('proxy_secrets')->where('proxy_id', $proxy->id)->where('purpose', 'signing')->count(),
            );
        } finally {
            DB::table('proxy_secrets')->where('proxy_id', $proxy->id)->delete();
            DB::table('proxies')->where('id', $proxy->id)->delete();
            DB::table('teams')->where('id', $team->id)->delete();
        }
    }

    public function test_down_restores_exactly_the_two_columns_matching_their_original_nullable_definitions(): void
    {
        $this->rollbackThisMigrationOnly();

        $columns = $this->columnTypesFor('proxies');

        $this->assertSame('varchar|YES', $columns['verification_scheme']);
        $this->assertSame('varchar|YES', $columns['verification_header_name']);

        Artisan::call('migrate');
    }

    public function test_rollback_round_trip_leaves_proxy_secrets_sensitive_fields_and_destination_credential_columns_untouched(): void
    {
        $this->rollbackThisMigrationOnly();

        // T1's own table/columns are untouched by rolling back only this
        // later migration — they belong to capabilities that survive
        // (ADR-026 Decision 4).
        $this->assertTrue(Schema::hasTable('proxy_secrets'));
        $this->assertTrue(Schema::hasColumn('proxies', 'sensitive_fields'));
        $this->assertTrue(Schema::hasColumn('destinations', 'credential_header_name'));
        $this->assertTrue(Schema::hasColumn('destinations', 'credential_secret'));
        $this->assertTrue(Schema::hasColumn('destinations', 'credential_set_at'));

        $this->assertNotContains(self::MIGRATION, DB::table('migrations')->pluck('migration')->all());

        Artisan::call('migrate');

        $this->assertContains(self::MIGRATION, DB::table('migrations')->pluck('migration')->all());
        $this->assertFalse(Schema::hasColumn('proxies', 'verification_scheme'));
        $this->assertFalse(Schema::hasColumn('proxies', 'verification_header_name'));
    }

    public function test_secret_purpose_has_exactly_one_case_and_a_signing_row_still_hydrates(): void
    {
        $this->assertSame([SecretPurpose::Signing], SecretPurpose::cases());

        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);
        $id = DB::table('proxy_secrets')->insertGetId([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'purpose' => 'signing',
            'value' => encrypt('whsec_test'),
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secret = ProxySecret::query()->findOrFail($id);

        $this->assertSame(SecretPurpose::Signing, $secret->purpose);
    }
}
