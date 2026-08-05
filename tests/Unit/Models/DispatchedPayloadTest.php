<?php

namespace Tests\Unit\Models;

use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DispatchedPayloadTest extends TestCase
{
    public function test_body_column_is_longblob_and_nullable(): void
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['dispatched_payloads', 'body'],
        );

        $this->assertNotNull($column, 'Expected a body column on dispatched_payloads.');
        $this->assertSame('longblob', strtolower((string) $column->DATA_TYPE));
        $this->assertSame('YES', $column->IS_NULLABLE);
    }

    public function test_byte_size_and_dispatched_at_columns_exist_with_expected_types(): void
    {
        $columns = collect(DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['dispatched_payloads'],
        ))->keyBy(fn ($c) => strtolower((string) $c->COLUMN_NAME));

        $this->assertSame('int', strtolower((string) $columns->get('byte_size')->DATA_TYPE));
        $this->assertSame('NO', $columns->get('byte_size')->IS_NULLABLE);

        $this->assertSame('timestamp', strtolower((string) $columns->get('dispatched_at')->DATA_TYPE));
        $this->assertSame('NO', $columns->get('dispatched_at')->IS_NULLABLE);
    }

    public function test_webhook_event_id_has_a_single_column_unique_index(): void
    {
        $indexes = DB::select(
            'SELECT INDEX_NAME, COUNT(*) AS cols, GROUP_CONCAT(COLUMN_NAME) AS names
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME',
            ['dispatched_payloads'],
        );

        $eventIndex = collect($indexes)->first(
            fn ($i) => str_contains(strtolower((string) $i->names), 'webhook_event_id'),
        );

        $this->assertNotNull($eventIndex, 'Expected a unique index on webhook_event_id.');
        $this->assertSame(1, (int) $eventIndex->cols, 'Unique index on webhook_event_id must be single-column.');
    }

    public function test_the_team_created_at_composite_index_exists(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            ['dispatched_payloads'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        $this->assertContains('team_id,created_at', $indexColumns);
    }

    public function test_table_has_no_headers_method_or_deleted_at_columns(): void
    {
        $columns = array_map('strtolower', Schema::getColumnListing('dispatched_payloads'));

        $this->assertNotContains('deleted_at', $columns, 'dispatched_payloads must not be soft-deletable.');
        $this->assertNotContains('headers', $columns);
        $this->assertNotContains('method', $columns);
    }

    public function test_body_round_trips_through_the_encrypted_cast(): void
    {
        $raw = "binary\x00bytes-\xff-payload";
        $payload = DispatchedPayload::factory()->createQuietly(['body' => $raw]);

        // Decrypts back to the exact original bytes on read.
        $this->assertSame($raw, $payload->fresh()->body);

        // Ciphertext at rest is not the plaintext.
        $stored = DB::table('dispatched_payloads')->where('id', $payload->id)->value('body');
        $this->assertNotSame($raw, $stored);
    }

    public function test_body_is_genuinely_null_when_unset_at_both_raw_and_cast_level(): void
    {
        $payload = DispatchedPayload::factory()->createQuietly(['body' => null]);

        $stored = DB::table('dispatched_payloads')->where('id', $payload->id)->value('body');
        $this->assertNull($stored);
        $this->assertNull($payload->fresh()->body);
    }

    public function test_relations_resolve_to_the_correct_records(): void
    {
        $event = WebhookEvent::factory()->createQuietly();
        $payload = DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $event->team_id,
            'proxy_id' => $event->proxy_id,
        ]);

        $this->assertTrue($payload->webhookEvent->is($event));
        $this->assertInstanceOf(Proxy::class, $payload->proxy);
        $this->assertSame($event->proxy_id, $payload->proxy->id);
    }
}
