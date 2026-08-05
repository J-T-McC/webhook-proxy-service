<?php

namespace Tests\Feature\Retention;

use App\Actions\PurgeExpiredPayloads;
use App\Enums\FifoDispatchStatus;
use App\Models\DeliveryAttempt;
use App\Models\DispatchedPayload;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * End-to-end proof that a `PurgeExpiredPayloads` pass erases completely,
 * touches nothing else, and is atomic across both stores (AC5, AC6, AC9,
 * AC12, AC22b) — complementing T11's unit-level happy path.
 */
class RetentionErasureCompletenessAcceptanceTest extends TestCase
{
    private function expiredEventFor(Proxy $proxy): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays(31),
        ]);
    }

    public function test_the_purge_command_is_registered_and_callable(): void
    {
        $this->artisan('payloads:purge-expired')->assertExitCode(0);
    }

    public function test_a_pass_erases_body_and_headers_in_both_stores_leaving_retained_descriptors_and_related_records_intact(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        $before = DB::table('webhook_events')->where('id', $event->id)->first();

        $output = DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'body' => '{"diverged":true}',
        ]);
        $fifo = FifoDispatch::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => FifoDispatchStatus::Settled,
            'settled_at' => now(),
        ]);
        $attempt = DeliveryAttempt::factory()->succeeded()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => $event->ingest_id,
        ]);
        $beforeAttempt = DB::table('delivery_attempts')->where('id', $attempt->id)->first();
        $beforeFifo = DB::table('fifo_dispatches')->where('id', $fifo->id)->first();

        PurgeExpiredPayloads::run();

        $raw = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNull($raw->body);
        $this->assertNull($raw->headers);
        $this->assertNotNull($raw->payload_cleaned_at);

        // Retained descriptors, byte-for-byte (AC6, AC10).
        $this->assertSame($before->method, $raw->method);
        $this->assertSame($before->content_type, $raw->content_type);
        $this->assertSame($before->byte_size, $raw->byte_size);
        $this->assertSame($before->received_at, $raw->received_at);
        $this->assertSame($before->ingest_id, $raw->ingest_id);
        $this->assertSame($before->team_id, $raw->team_id);
        $this->assertSame($before->proxy_id, $raw->proxy_id);
        $this->assertSame($before->created_at, $raw->created_at);
        // Exactly three columns written via the query builder — updated_at untouched.
        $this->assertSame($before->updated_at, $raw->updated_at);

        $rawOutput = DB::table('dispatched_payloads')->where('id', $output->id)->first();
        $this->assertNull($rawOutput->body);

        // delivery_attempts is never written by the GC (AC9) — byte-identical, still queryable.
        $afterAttempt = DB::table('delivery_attempts')->where('id', $attempt->id)->first();
        $this->assertEquals($beforeAttempt, $afterAttempt);

        // fifo_dispatches is never written by the GC either — still present, unchanged.
        $afterFifo = DB::table('fifo_dispatches')->where('id', $fifo->id)->first();
        $this->assertEquals($beforeFifo, $afterFifo);
    }

    public function test_a_failed_dispatched_payloads_erase_rolls_back_the_webhook_events_erase_too(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);
        $output = DispatchedPayload::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'body' => '{"diverged":true}',
        ]);
        $beforeEvent = DB::table('webhook_events')->where('id', $event->id)->first();
        $beforeOutput = DB::table('dispatched_payloads')->where('id', $output->id)->first();

        // Fault-injection seam: fail exactly the second UPDATE (`dispatched_payloads`) of the
        // erase pass, after the first (`webhook_events`) has already executed within the same
        // transaction — proving the transaction, not the application, is what undoes it.
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'update `dispatched_payloads`')) {
                throw new RuntimeException('Injected fault: dispatched_payloads erase failed.');
            }
        });

        $thrown = null;
        try {
            PurgeExpiredPayloads::run();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The injected fault must propagate out of the erase pass.');

        $afterEvent = DB::table('webhook_events')->where('id', $event->id)->first();
        $afterOutput = DB::table('dispatched_payloads')->where('id', $output->id)->first();

        // Neither write survives: the event is never left marked cleaned with its output intact.
        $this->assertEquals($beforeEvent, $afterEvent);
        $this->assertEquals($beforeOutput, $afterOutput);
        $this->assertNull($afterEvent->payload_cleaned_at);
    }

    public function test_a_second_scheduled_run_over_already_cleaned_rows_touches_nothing(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $event = $this->expiredEventFor($proxy);

        $this->artisan('payloads:purge-expired')->assertExitCode(0);
        $firstPass = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNotNull($firstPass->payload_cleaned_at);

        $this->artisan('payloads:purge-expired')->assertExitCode(0);
        $secondPass = DB::table('webhook_events')->where('id', $event->id)->first();

        $this->assertEquals($firstPass, $secondPass);
    }
}
