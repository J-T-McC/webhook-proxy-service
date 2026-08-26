<?php

namespace Tests\Unit\Models;

use App\Enums\FifoDispatchStatus;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FifoDispatchTest extends TestCase
{
    public function test_webhook_event_id_has_a_plain_non_unique_index_and_no_unique_index(): void
    {
        $indexes = collect(DB::select(
            'SELECT INDEX_NAME, NON_UNIQUE, COUNT(*) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME, NON_UNIQUE',
            ['fifo_dispatches'],
        ));

        $webhookEventIdIndexes = $indexes->filter(
            fn ($i) => str_contains(strtolower((string) $i->INDEX_NAME), 'webhook_event_id'),
        );

        $unique = $webhookEventIdIndexes->first(fn ($i) => (int) $i->NON_UNIQUE === 0);
        $plain = $webhookEventIdIndexes->first(fn ($i) => (int) $i->NON_UNIQUE === 1 && (int) $i->cols === 1);

        $this->assertNull($unique, 'UNIQUE(webhook_event_id) must have been dropped (T6).');
        $this->assertNotNull($plain, 'Expected a plain single-column index on webhook_event_id (FK support).');
    }

    public function test_dispatch_uuid_has_a_single_column_unique_index(): void
    {
        $indexes = DB::select(
            'SELECT INDEX_NAME, COUNT(*) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME',
            ['fifo_dispatches'],
        );

        $unique = collect($indexes)->first(
            fn ($i) => str_contains(strtolower($i->INDEX_NAME), 'dispatch_uuid'),
        );

        $this->assertNotNull($unique, 'Expected a unique index on dispatch_uuid.');
        $this->assertSame(1, (int) $unique->cols, 'dispatch_uuid unique index must be single-column.');
    }

    public function test_duplicate_dispatch_uuid_is_rejected(): void
    {
        $dispatch = FifoDispatch::factory()->create();

        $this->expectException(QueryException::class);
        FifoDispatch::factory()->create(['dispatch_uuid' => $dispatch->dispatch_uuid]);
    }

    public function test_two_rows_for_the_same_webhook_event_id_are_now_accepted(): void
    {
        $first = FifoDispatch::factory()->create();

        $second = FifoDispatch::factory()->create([
            'webhook_event_id' => $first->webhook_event_id,
            'proxy_id' => $first->proxy_id,
            'team_id' => $first->team_id,
            'dispatch_uuid' => (string) Str::uuid(),
        ]);

        $this->assertSame($first->webhook_event_id, $second->webhook_event_id);
        $this->assertNotSame($first->dispatch_uuid, $second->dispatch_uuid);
    }

    public function test_dispatch_uuid_backfills_to_the_events_ingest_id(): void
    {
        $dispatch = FifoDispatch::factory()->create();

        $this->assertSame($dispatch->webhookEvent->ingest_id, $dispatch->dispatch_uuid);
    }

    public function test_composite_proxy_status_event_index_exists(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            ['fifo_dispatches'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        $this->assertContains('proxy_id,status,webhook_event_id', $indexColumns);
    }

    public function test_status_defaults_to_pending_at_the_schema_level(): void
    {
        $column = DB::selectOne(
            'SELECT COLUMN_DEFAULT, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['fifo_dispatches', 'status'],
        );

        $this->assertNotNull($column, 'Expected a status column on fifo_dispatches.');
        $this->assertSame('pending', (string) $column->COLUMN_DEFAULT);
        $this->assertSame('NO', strtoupper((string) $column->IS_NULLABLE));
    }

    public function test_table_has_no_soft_delete_or_payload_columns(): void
    {
        $columns = array_map('strtolower', Schema::getColumnListing('fifo_dispatches'));

        $this->assertNotContains('deleted_at', $columns, 'fifo_dispatches must not be soft-deletable.');

        foreach (['payload', 'body', 'headers'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "fifo_dispatches must not store a {$forbidden}.");
        }
    }

    public function test_status_round_trips_through_the_enum_cast(): void
    {
        $dispatch = FifoDispatch::factory()->create(['status' => FifoDispatchStatus::Claimed]);

        $fresh = $dispatch->fresh();
        $this->assertInstanceOf(FifoDispatchStatus::class, $fresh->status);
        $this->assertSame(FifoDispatchStatus::Claimed, $fresh->status);
    }

    public function test_nullable_timestamps_cast_to_carbon_when_set_and_stay_null_when_unset(): void
    {
        $unset = FifoDispatch::factory()->create();
        $this->assertNull($unset->fresh()->claimed_at);
        $this->assertNull($unset->fresh()->lease_expires_at);
        $this->assertNull($unset->fresh()->settled_at);

        $set = FifoDispatch::factory()->create([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
            'settled_at' => now(),
        ]);

        $fresh = $set->fresh();
        $this->assertInstanceOf(CarbonInterface::class, $fresh->claimed_at);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->lease_expires_at);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->settled_at);
    }

    public function test_belongs_to_relations_resolve_to_the_correct_records(): void
    {
        $dispatch = FifoDispatch::factory()->create();

        $this->assertInstanceOf(Proxy::class, $dispatch->proxy);
        $this->assertSame($dispatch->proxy_id, $dispatch->proxy->id);

        $this->assertInstanceOf(WebhookEvent::class, $dispatch->webhookEvent);
        $this->assertSame($dispatch->webhook_event_id, $dispatch->webhookEvent->id);

        // The event's proxy and the dispatch's proxy are the same (factory consistency).
        $this->assertSame($dispatch->proxy_id, $dispatch->webhookEvent->proxy_id);
        $this->assertSame($dispatch->team_id, $dispatch->webhookEvent->team_id);
    }
}
