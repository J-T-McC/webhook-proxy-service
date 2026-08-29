<?php

namespace Tests\Feature\Ingest;

use App\Actions\ProcessIngestedWebhook;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\ProcessingMode;
use App\Events\DeliveryExhausted;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessIngestedWebhookTest extends TestCase
{
    public function test_rebuilds_from_ingest_id_and_delivers_once_per_live_destination(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(3)->createQuietly();
        $trashed = Destination::factory()->for($proxy)->createQuietly();
        $trashed->delete();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'ingest-xyz',
            'method' => 'POST',
            'headers' => ['content-type' => ['application/json']],
            'body' => '{"hello":"world"}',
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        // One delivery attempt per live destination (trashed excluded).
        $this->assertSame(3, DeliveryAttempt::count());
        Http::assertSentCount(3);
    }

    public function test_creates_one_original_delivery_row_per_live_destination(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        $destinations = Destination::factory()->for($proxy)->count(3)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'ingest-original-rows',
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(3, Delivery::count());

        foreach ($destinations as $destination) {
            $delivery = Delivery::query()->where('destination_id', $destination->id)->firstOrFail();
            $this->assertSame(DispatchKind::Original, $delivery->kind);
            // Created Pending, but this proxy is Async-default and every response
            // fakes 200 — DeliverToDestination's post-settle CAS (T13) transitions it
            // to Succeeded synchronously (sync queue) within this same call.
            $this->assertSame(DeliveryStatus::Succeeded, $delivery->status);
            $this->assertSame('ingest-original-rows', $delivery->dispatch_uuid);
            $this->assertSame($event->id, $delivery->webhook_event_id);
            $this->assertSame($proxy->id, $delivery->proxy_id);
            $this->assertSame($proxy->team_id, $delivery->team_id);
        }
    }

    public function test_reinvoking_for_the_same_ingest_id_creates_no_duplicate_delivery_rows(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'ingest-redelivery',
        ]);

        // Simulated redelivery: the same ingest id is processed twice.
        ProcessIngestedWebhook::run($event->ingest_id);
        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(2, Delivery::count());
    }

    public function test_a_trashed_destination_is_not_given_an_original_delivery_row(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(2)->createQuietly();
        $trashed = Destination::factory()->for($proxy)->createQuietly();
        $trashed->delete();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(2, Delivery::count());
        $this->assertSame(
            0,
            Delivery::query()->where('destination_id', $trashed->id)->count(),
        );
    }

    public function test_delivers_for_an_event_whose_proxy_was_soft_deleted_after_capture(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        // The proxy is soft-deleted AFTER the event was captured.
        $proxy->delete();

        ProcessIngestedWebhook::run($event->ingest_id);

        // Trashed-inclusive load: the event still delivers to both live destinations.
        $this->assertSame(2, DeliveryAttempt::count());
        Http::assertSentCount(2);
    }

    public function test_unknown_ingest_id_raises_and_does_not_silently_no_op(): void
    {
        Http::fake();

        $this->expectException(ModelNotFoundException::class);

        ProcessIngestedWebhook::run('does-not-exist');
    }

    public function test_a_cleaned_replays_pre_created_delivery_rows_are_terminalized_not_left_stuck(): void
    {
        // Item #15 Q-15-01(4): a replay pre-creates its `deliveries` rows before
        // this action ever runs. If the event is cleaned by the time it does,
        // those rows must not be left non-terminal forever (the livelock this
        // brief fixes at the root, in the cleaned-event early return).
        Event::fake();
        Http::fake();

        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->for($proxy)->createQuietly();

        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        $replayDispatchUuid = 'replay-uuid-1';
        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $replayDispatchUuid,
            'kind' => DispatchKind::Replay,
            'status' => DeliveryStatus::Pending,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id, $replayDispatchUuid);

        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->next_attempt_at);
        $this->assertSame(0, DeliveryAttempt::count(), 'No attempt is ever made for a cleaned event.');
        Http::assertNothingSent();
        Event::assertDispatched(DeliveryExhausted::class, fn ($event) => $event->delivery->id === $delivery->id);
    }

    public function test_a_cleaned_event_with_no_pre_created_deliveries_is_an_unaffected_no_op(): void
    {
        // The ordinary (original-dispatch) shape: nothing to terminalize.
        Http::fake();

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(0, Delivery::count());
        $this->assertSame(0, DeliveryAttempt::count());
        Http::assertNothingSent();
    }

    public function test_a_paused_async_proxy_dispatches_nothing_and_creates_no_delivery_rows(): void
    {
        Http::fake();

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Async,
        ]);
        $proxy->forceFill(['paused_at' => now()])->save();
        Destination::factory()->for($proxy)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(0, Delivery::count());
        Http::assertNothingSent();
    }

    public function test_a_paused_fifo_proxy_still_dispatches_when_invoked_directly(): void
    {
        // The pause guard inside this action is scoped away from FIFO
        // (Q-15-01(2) is handled at AdvanceProxyFifoQueue's claim instead) —
        // were it to also fire here, a bare invocation with zero deliveries
        // created would read as "done" to settleOrHold() and silently lose
        // the event rather than leave it to resume.
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
        ]);
        $proxy->forceFill(['paused_at' => now()])->save();
        Destination::factory()->for($proxy)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $event->ingest_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(1, Delivery::count());
        Http::assertSentCount(1);
    }

    public function test_a_cleaned_event_returns_cleanly_and_dispatches_nothing(): void
    {
        Http::fake();

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(0, DeliveryAttempt::count());
        Http::assertNothingSent();
    }
}
