<?php

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsIndexesTest extends TestCase
{
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

    public function test_the_four_new_indexes_exist_with_exact_column_order(): void
    {
        $deliveryAttemptsIndexes = $this->indexColumnsFor('delivery_attempts');
        $deliveriesIndexes = $this->indexColumnsFor('deliveries');

        $this->assertSame(
            'team_id,status,updated_at',
            $deliveryAttemptsIndexes['delivery_attempts_team_id_status_updated_at_index'] ?? null,
        );
        $this->assertSame(
            'proxy_id,status,updated_at',
            $deliveryAttemptsIndexes['delivery_attempts_proxy_id_status_updated_at_index'] ?? null,
        );
        $this->assertSame(
            'team_id,status,updated_at',
            $deliveriesIndexes['deliveries_team_id_status_updated_at_index'] ?? null,
        );
        $this->assertSame(
            'proxy_id,status,updated_at',
            $deliveriesIndexes['deliveries_proxy_id_status_updated_at_index'] ?? null,
        );
    }

    public function test_every_pre_existing_index_survives_the_migration(): void
    {
        $deliveryAttemptsIndexes = array_values($this->indexColumnsFor('delivery_attempts'));
        $deliveriesIndexes = array_values($this->indexColumnsFor('deliveries'));

        // delivery_attempts (pre-#11): UNIQUE(delivery_id, attempt_number), ingest_id,
        // (team_id, created_at), (proxy_id, status) — the last one kept even though it is
        // now a strict prefix of the new (proxy_id, status, updated_at) index (plan-11 §
        // Data Model — reclaiming it is a separate, later decision with its own gate).
        $this->assertContains('delivery_id,attempt_number', $deliveryAttemptsIndexes);
        $this->assertContains('ingest_id', $deliveryAttemptsIndexes);
        $this->assertContains('team_id,created_at', $deliveryAttemptsIndexes);
        $this->assertContains('proxy_id,status', $deliveryAttemptsIndexes);

        // deliveries (pre-#11): UNIQUE(dispatch_uuid, destination_id),
        // (webhook_event_id, status), (status, next_attempt_at).
        $this->assertContains('dispatch_uuid,destination_id', $deliveriesIndexes);
        $this->assertContains('webhook_event_id,status', $deliveriesIndexes);
        $this->assertContains('status,next_attempt_at', $deliveriesIndexes);
    }

    public function test_rollback_removes_exactly_the_four_new_indexes_and_reapplying_restores_them(): void
    {
        $migration = '2026_08_26_000001_add_analytics_indexes_to_delivery_tables';

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $deliveryAttemptsIndexes = array_values($this->indexColumnsFor('delivery_attempts'));
        $deliveriesIndexes = array_values($this->indexColumnsFor('deliveries'));

        // The four new indexes are gone.
        $this->assertNotContains('team_id,status,updated_at', $deliveryAttemptsIndexes);
        $this->assertNotContains('proxy_id,status,updated_at', $deliveryAttemptsIndexes);
        $this->assertNotContains('team_id,status,updated_at', $deliveriesIndexes);
        $this->assertNotContains('proxy_id,status,updated_at', $deliveriesIndexes);

        // Every pre-existing index is still present after rollback.
        $this->assertContains('delivery_id,attempt_number', $deliveryAttemptsIndexes);
        $this->assertContains('ingest_id', $deliveryAttemptsIndexes);
        $this->assertContains('team_id,created_at', $deliveryAttemptsIndexes);
        $this->assertContains('proxy_id,status', $deliveryAttemptsIndexes);
        $this->assertContains('dispatch_uuid,destination_id', $deliveriesIndexes);
        $this->assertContains('webhook_event_id,status', $deliveriesIndexes);
        $this->assertContains('status,next_attempt_at', $deliveriesIndexes);

        $this->assertNotContains($migration, DB::table('migrations')->pluck('migration')->all());

        Artisan::call('migrate');

        $this->assertContains($migration, DB::table('migrations')->pluck('migration')->all());

        $deliveryAttemptsIndexes = $this->indexColumnsFor('delivery_attempts');
        $deliveriesIndexes = $this->indexColumnsFor('deliveries');

        $this->assertSame(
            'team_id,status,updated_at',
            $deliveryAttemptsIndexes['delivery_attempts_team_id_status_updated_at_index'] ?? null,
        );
        $this->assertSame(
            'proxy_id,status,updated_at',
            $deliveryAttemptsIndexes['delivery_attempts_proxy_id_status_updated_at_index'] ?? null,
        );
        $this->assertSame(
            'team_id,status,updated_at',
            $deliveriesIndexes['deliveries_team_id_status_updated_at_index'] ?? null,
        );
        $this->assertSame(
            'proxy_id,status,updated_at',
            $deliveriesIndexes['deliveries_proxy_id_status_updated_at_index'] ?? null,
        );
    }
}
