<?php

namespace Tests\Unit\Models;

use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    public function test_dispatch_uuid_and_destination_id_have_a_composite_unique_index(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME',
            ['deliveries'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        $this->assertContains('dispatch_uuid,destination_id', $indexColumns);
    }

    public function test_duplicate_dispatch_uuid_and_destination_id_pair_is_rejected(): void
    {
        $delivery = Delivery::factory()->create();

        $this->expectException(QueryException::class);
        Delivery::factory()->create([
            'dispatch_uuid' => $delivery->dispatch_uuid,
            'destination_id' => $delivery->destination_id,
        ]);
    }

    public function test_the_same_dispatch_uuid_across_different_destinations_is_allowed(): void
    {
        $delivery = Delivery::factory()->create();
        $otherDestination = Destination::factory()->create([
            'proxy_id' => $delivery->proxy_id,
            'team_id' => $delivery->team_id,
        ]);

        $second = Delivery::factory()->create([
            'dispatch_uuid' => $delivery->dispatch_uuid,
            'webhook_event_id' => $delivery->webhook_event_id,
            'proxy_id' => $delivery->proxy_id,
            'team_id' => $delivery->team_id,
            'destination_id' => $otherDestination->id,
        ]);

        $this->assertNotSame($delivery->id, $second->id);
    }

    public function test_the_webhook_event_status_composite_index_exists(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            ['deliveries'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        $this->assertContains('webhook_event_id,status', $indexColumns);
    }

    public function test_the_status_next_attempt_at_composite_index_exists(): void
    {
        $indexColumns = collect(DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
             GROUP BY INDEX_NAME',
            ['deliveries'],
        ))->pluck('cols')->map(fn ($c) => strtolower((string) $c))->all();

        $this->assertContains('status,next_attempt_at', $indexColumns);
    }

    public function test_status_defaults_to_pending_at_the_schema_level(): void
    {
        $column = DB::selectOne(
            'SELECT COLUMN_DEFAULT, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['deliveries', 'status'],
        );

        $this->assertNotNull($column, 'Expected a status column on deliveries.');
        $this->assertSame('pending', (string) $column->COLUMN_DEFAULT);
        $this->assertSame('NO', strtoupper((string) $column->IS_NULLABLE));
    }

    public function test_next_attempt_at_is_a_nullable_timestamp(): void
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_NAME = ? AND COLUMN_NAME = ? AND TABLE_SCHEMA = DATABASE()',
            ['deliveries', 'next_attempt_at'],
        );

        $this->assertNotNull($column, 'Expected a next_attempt_at column on deliveries.');
        $this->assertSame('timestamp', strtolower((string) $column->DATA_TYPE));
        $this->assertSame('YES', strtoupper((string) $column->IS_NULLABLE));
    }

    public function test_table_has_no_soft_delete_or_payload_columns(): void
    {
        $columns = array_map('strtolower', Schema::getColumnListing('deliveries'));

        $this->assertNotContains('deleted_at', $columns, 'deliveries must not be soft-deletable.');

        foreach (['payload', 'body', 'headers'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "deliveries must not store a {$forbidden}.");
        }
    }

    public function test_kind_and_status_round_trip_through_their_enum_casts(): void
    {
        $delivery = Delivery::factory()->create([
            'kind' => DispatchKind::Replay,
            'status' => DeliveryStatus::Retrying,
        ]);

        $fresh = $delivery->fresh();
        $this->assertInstanceOf(DispatchKind::class, $fresh->kind);
        $this->assertSame(DispatchKind::Replay, $fresh->kind);
        $this->assertInstanceOf(DeliveryStatus::class, $fresh->status);
        $this->assertSame(DeliveryStatus::Retrying, $fresh->status);
    }

    public function test_next_attempt_at_casts_to_carbon_when_set_and_stays_null_when_unset(): void
    {
        $unset = Delivery::factory()->create();
        $this->assertNull($unset->fresh()->next_attempt_at);

        $set = Delivery::factory()->create(['next_attempt_at' => now()->addMinutes(5)]);
        $this->assertInstanceOf(CarbonInterface::class, $set->fresh()->next_attempt_at);
    }

    public function test_belongs_to_relations_resolve_to_the_correct_records(): void
    {
        $delivery = Delivery::factory()->create();

        $this->assertInstanceOf(Proxy::class, $delivery->proxy);
        $this->assertSame($delivery->proxy_id, $delivery->proxy->id);

        $this->assertInstanceOf(Destination::class, $delivery->destination);
        $this->assertSame($delivery->destination_id, $delivery->destination->id);

        $this->assertInstanceOf(WebhookEvent::class, $delivery->webhookEvent);
        $this->assertSame($delivery->webhook_event_id, $delivery->webhookEvent->id);

        // The event's proxy/team and the delivery's proxy/team are the same
        // (factory consistency).
        $this->assertSame($delivery->proxy_id, $delivery->webhookEvent->proxy_id);
        $this->assertSame($delivery->team_id, $delivery->webhookEvent->team_id);
    }

    public function test_factory_happy_path_creates_a_pending_original_delivery(): void
    {
        $delivery = Delivery::factory()->create();

        $this->assertSame(DispatchKind::Original, $delivery->kind);
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertNotEmpty($delivery->dispatch_uuid);
        $this->assertNull($delivery->next_attempt_at);
    }
}
