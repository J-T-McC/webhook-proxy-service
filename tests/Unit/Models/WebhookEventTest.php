<?php

namespace Tests\Unit\Models;

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebhookEventTest extends TestCase
{
    public function test_body_column_is_longblob(): void
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['webhook_events', 'body'],
        );

        $this->assertNotNull($column, 'Expected a body column on webhook_events.');
        $this->assertSame('longblob', strtolower((string) $column->DATA_TYPE));
    }

    public function test_ingest_id_has_a_single_column_unique_index(): void
    {
        $indexes = DB::select(
            'SELECT INDEX_NAME, COUNT(*) AS cols, GROUP_CONCAT(COLUMN_NAME) AS names
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME',
            ['webhook_events'],
        );

        $ingestIndex = collect($indexes)->first(
            fn ($i) => str_contains(strtolower((string) $i->names), 'ingest_id'),
        );

        $this->assertNotNull($ingestIndex, 'Expected a unique index on ingest_id.');
        $this->assertSame(1, (int) $ingestIndex->cols, 'Unique index on ingest_id must be single-column.');
    }

    public function test_the_two_composite_indexes_exist(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            ['webhook_events'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        $this->assertContains('team_id,created_at', $indexColumns);
        $this->assertContains('proxy_id,created_at', $indexColumns);
    }

    public function test_table_is_raw_only_with_no_soft_delete_or_dispatched_output_columns(): void
    {
        $columns = array_map('strtolower', Schema::getColumnListing('webhook_events'));

        $this->assertNotContains('deleted_at', $columns, 'webhook_events must be immutable (no soft delete).');

        foreach (['dispatched_body', 'dispatched_payload', 'dispatched_output', 'output', 'response', 'response_body'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "webhook_events is raw-only and must not store a {$forbidden}.");
        }
    }

    public function test_body_round_trips_through_the_encrypted_cast(): void
    {
        $raw = "binary\x00bytes-\xff-payload";
        $event = WebhookEvent::factory()->createQuietly(['body' => $raw]);

        // Decrypts back to the exact original bytes on read.
        $this->assertSame($raw, $event->fresh()->body);

        // Ciphertext at rest is not the plaintext.
        $stored = DB::table('webhook_events')->where('id', $event->id)->value('body');
        $this->assertNotSame($raw, $stored);
    }

    public function test_headers_round_trip_as_an_array(): void
    {
        $headers = ['content-type' => ['application/json'], 'x-signature' => ['abc123']];
        $event = WebhookEvent::factory()->createQuietly(['headers' => $headers]);

        // assertEquals (not assertSame): MySQL JSON does not preserve object key order.
        $this->assertEquals($headers, $event->fresh()->headers);
    }

    public function test_byte_size_casts_to_integer(): void
    {
        $event = WebhookEvent::factory()->createQuietly(['byte_size' => 4096]);

        $this->assertSame(4096, $event->fresh()->byte_size);
    }
}
