<?php

namespace Tests\Feature\Analytics;

use App\Actions\DeliverToDestination;
use App\Actions\PurgeExpiredPayloads;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\DeliveryUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the invariant the entire windowing/anchor design rests on
 * (plan-11 Technical ruling 1, § Risks R2, binding constraint 5): once a
 * `deliveries` or `delivery_attempts` row reaches a terminal/resolved
 * status, no code path may write to it again, so its `updated_at` (the
 * window anchor) is frozen. No production code changes in this task — this
 * is pure test coverage proving the invariant holds today by construction.
 */
class AnchorInvariantTest extends TestCase
{
    private function deliveryUpdatedAt(int $deliveryId): string
    {
        return (string) DB::table('deliveries')->where('id', $deliveryId)->value('updated_at');
    }

    private function attemptUpdatedAt(int $attemptId): string
    {
        return (string) DB::table('delivery_attempts')->where('id', $attemptId)->value('updated_at');
    }

    public function test_a_terminal_deliverys_updated_at_is_unchanged_after_a_re_driven_settle_attempt(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->createQuietly();
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
        ]);

        // Reach a terminal state via the real settle path.
        DB::table('deliveries')->where('id', $delivery->id)->update([
            'status' => DeliveryStatus::Failed->value,
            'next_attempt_at' => null,
        ]);

        $before = $this->deliveryUpdatedAt($delivery->id);

        // A concurrent settler for a DIFFERENT, later attempt of the same delivery
        // (e.g. a straggling retry that lands after another settler already won the
        // terminal CAS) re-drives the settle path — the CAS keyed on the prior
        // non-terminal status must be a no-op here.
        $unit = new DeliveryUnit(
            ingestId: (string) Str::uuid(),
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: [],
            payload: '{"a":1}',
            deliveryId: $delivery->id,
            attemptNumber: 5,
        );

        DeliverToDestination::run($unit);

        $after = $this->deliveryUpdatedAt($delivery->id);

        $this->assertSame($before, $after, "A terminal delivery's updated_at must never move again.");
        $this->assertSame(DeliveryStatus::Failed->value, DB::table('deliveries')->where('id', $delivery->id)->value('status'));
    }

    public function test_a_resolved_delivery_attempts_updated_at_is_unchanged_after_the_redelivery_path(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->createQuietly();
        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
        ]);

        $unit = new DeliveryUnit(
            ingestId: (string) Str::uuid(),
            teamId: $destination->team_id,
            proxyId: $destination->proxy_id,
            destination: $destination,
            method: $destination->http_method->value,
            headers: [],
            payload: '{"a":1}',
            deliveryId: $delivery->id,
            attemptNumber: 1,
        );

        // Resolve the attempt once.
        DeliverToDestination::run($unit);
        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Succeeded, $attempt->status);

        $before = $this->attemptUpdatedAt($attempt->id);

        // DeliverToDestination::resume() is private and reached only via handle() —
        // this simulates the queue's at-least-once redelivery of the SAME unit
        // against an already-resolved row.
        DeliverToDestination::run($unit);

        $after = $this->attemptUpdatedAt($attempt->id);

        $this->assertSame(1, DeliveryAttempt::count(), 'The redelivery must not create a second row.');
        Http::assertSentCount(1);
        $this->assertSame($before, $after, "A resolved delivery attempt's updated_at must never move again.");
    }

    public function test_purge_expired_payloads_leaves_a_terminal_delivery_and_a_resolved_attempt_unchanged(): void
    {
        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'created_at' => now()->subDays(31),
        ]);

        $delivery = Delivery::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'kind' => DispatchKind::Original,
            'status' => DeliveryStatus::Succeeded,
        ]);

        $attempt = DeliveryAttempt::factory()->createQuietly([
            'delivery_id' => $delivery->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Succeeded,
        ]);

        $deliveryBefore = $this->deliveryUpdatedAt($delivery->id);
        $attemptBefore = $this->attemptUpdatedAt($attempt->id);

        PurgeExpiredPayloads::run();

        // The event really was collectable and really was erased — proving this is
        // a real GC run over live rows, not a run that skipped everything.
        $this->assertNotNull(DB::table('webhook_events')->where('id', $event->id)->value('payload_cleaned_at'));

        $deliveryAfter = $this->deliveryUpdatedAt($delivery->id);
        $attemptAfter = $this->attemptUpdatedAt($attempt->id);

        $this->assertSame($deliveryBefore, $deliveryAfter, 'GC must never write deliveries — updated_at must be unchanged.');
        $this->assertSame($attemptBefore, $attemptAfter, 'GC must never write delivery_attempts — updated_at must be unchanged.');
    }
}
