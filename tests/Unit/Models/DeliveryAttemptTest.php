<?php

namespace Tests\Unit\Models;

use App\Enums\AttemptStatus;
use App\Models\DeliveryAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_casts_to_enum(): void
    {
        $attempt = DeliveryAttempt::factory()->create();

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
}
