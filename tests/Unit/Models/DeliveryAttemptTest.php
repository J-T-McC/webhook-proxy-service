<?php

namespace Tests\Unit\Models;

use App\Enums\AttemptStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryAttemptTest extends TestCase
{
    public function test_status_casts_to_enum(): void
    {
        $attempt = DeliveryAttempt::factory()->createQuietly();

        $this->assertInstanceOf(AttemptStatus::class, $attempt->fresh()->status);
    }

    public function test_table_has_no_payload_or_deleted_at_columns(): void
    {
        $columns = array_map('strtolower', Schema::getColumnListing('delivery_attempts'));

        $this->assertNotContains('deleted_at', $columns, 'delivery_attempts must not be soft-deletable.');

        foreach (['payload', 'body', 'request_body', 'response_body'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "delivery_attempts must not store a {$forbidden}.");
        }
    }

    public function test_the_three_expected_indexes_exist(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            ['delivery_attempts'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        $this->assertContains('team_id,created_at', $indexColumns);
        $this->assertContains('proxy_id,status', $indexColumns);
        $this->assertContains('ingest_id', $indexColumns);
    }

    public function test_delivery_id_attempt_number_unique_index_exists_and_the_old_idempotency_index_is_gone(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            ['delivery_attempts'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        // The new idempotency key (ADR-015 Decision 2 / ADR-016 P3), added before the
        // old one was dropped (T5's load-bearing ordering).
        $this->assertContains('delivery_id,attempt_number', $indexColumns);

        // The superseded ADR-011 key must no longer exist.
        $this->assertNotContains('ingest_id,destination_id,attempt_number', $indexColumns);

        // The three pre-existing indexes are unaffected.
        $this->assertContains('team_id,created_at', $indexColumns);
        $this->assertContains('proxy_id,status', $indexColumns);
        $this->assertContains('ingest_id', $indexColumns);
    }

    public function test_delivery_id_is_nullable_with_a_restrict_foreign_key_to_deliveries(): void
    {
        $column = DB::selectOne(
            'SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['delivery_attempts', 'delivery_id'],
        );

        $this->assertNotNull($column, 'Expected a delivery_id column on delivery_attempts.');
        $this->assertSame('YES', strtoupper((string) $column->IS_NULLABLE), 'delivery_id must be nullable (pre-#6 rows).');

        $fk = DB::selectOne(
            'SELECT k.REFERENCED_TABLE_NAME AS ref_table, r.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
             WHERE k.TABLE_NAME = ? AND k.COLUMN_NAME = ? AND k.TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            ['delivery_attempts', 'delivery_id'],
        );

        $this->assertNotNull($fk, 'Expected a foreign key on delivery_attempts.delivery_id.');
        $this->assertSame('deliveries', strtolower((string) $fk->ref_table));
        // Default constrained() behaviour (MySQL reports it as NO ACTION); the point is
        // it must not cascade or null out on delete.
        $this->assertNotSame('CASCADE', strtoupper((string) $fk->delete_rule), 'delivery_id FK must restrict, not cascade.');
        $this->assertNotSame('SET NULL', strtoupper((string) $fk->delete_rule), 'delivery_id FK must restrict, not null out.');
    }

    public function test_a_second_null_delivery_id_row_is_not_rejected_by_the_new_unique_index(): void
    {
        // Pre-#6 rows all carry delivery_id = NULL (no backfill); MySQL unique-NULL
        // semantics mean two NULLs never collide.
        $first = DeliveryAttempt::factory()->createQuietly(['delivery_id' => null, 'attempt_number' => 1]);
        $second = DeliveryAttempt::factory()->createQuietly(['delivery_id' => null, 'attempt_number' => 1]);

        $this->assertNull($first->fresh()->delivery_id);
        $this->assertNull($second->fresh()->delivery_id);
    }

    public function test_duplicate_delivery_id_attempt_number_pair_is_rejected(): void
    {
        $delivery = Delivery::factory()->create();
        DeliveryAttempt::factory()->createQuietly([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
        ]);

        $this->expectException(QueryException::class);
        DeliveryAttempt::factory()->createQuietly([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
        ]);
    }

    public function test_belongs_to_delivery_resolves(): void
    {
        $delivery = Delivery::factory()->create();
        $attempt = DeliveryAttempt::factory()->createQuietly(['delivery_id' => $delivery->id]);

        $this->assertTrue($attempt->delivery->is($delivery));
    }

    public function test_delivery_id_is_null_by_default_and_delivery_relation_resolves_to_null(): void
    {
        $attempt = DeliveryAttempt::factory()->createQuietly();

        $this->assertNull($attempt->delivery_id);
        $this->assertNull($attempt->delivery);
    }

    public function test_delivery_has_many_delivery_attempts_resolves(): void
    {
        $delivery = Delivery::factory()->create();
        $attempt = DeliveryAttempt::factory()->createQuietly([
            'delivery_id' => $delivery->id,
            'proxy_id' => $delivery->proxy_id,
            'team_id' => $delivery->team_id,
            'destination_id' => $delivery->destination_id,
        ]);

        $this->assertTrue($delivery->deliveryAttempts->contains($attempt));
    }
}
