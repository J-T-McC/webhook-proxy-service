<?php

namespace Tests\Feature\Ingest;

use App\Actions\DeliverToDestination;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\AttemptStatus;
use App\Enums\ProcessingMode;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * End-to-end proof that draining an Async proxy's queue fans out correctly (T14,
 * AC5/AC8/AC10), complementing the DeliverStep unit-level branch test.
 */
class AsyncDispatchAcceptanceTest extends TestCase
{
    public function test_a_factory_proxy_defaults_to_async(): void
    {
        // A #1/#3-shaped row (no explicit processing_mode) reads async (AC5).
        // Reload so the schema default (applied by the DB, not the factory) materialises.
        $this->assertSame(
            ProcessingMode::Async,
            Proxy::factory()->createQuietly()->fresh()->processing_mode,
        );
    }

    public function test_each_destination_gets_a_separate_job_on_the_webhooks_queue(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        Destination::factory()->for($proxy)->count(3)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        // One queued delivery job per destination, on the dedicated webhooks queue.
        DeliverToDestination::assertPushedOn(config('ingest.webhooks_queue'), 3);
    }

    public function test_draining_delivers_one_payload_free_attempt_and_events_per_destination(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        Destination::factory()->for($proxy)->count(3)->createQuietly();

        // Sync driver: dispatch drains inline through the whole chain.
        $this->post('https://localhost/ingest/'.$proxy->ingest_token, ['a' => 'b'])
            ->assertStatus(202);

        $this->assertSame(3, DeliveryAttempt::count());
        Http::assertSentCount(3);
        Event::assertDispatchedTimes(DeliverySucceeded::class, 3);

        // Payload-free by construction (ADR-003): no body/payload column exists.
        $columns = array_map('strtolower', Schema::getColumnListing('delivery_attempts'));
        foreach (['payload', 'body', 'request_body'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_one_destination_failing_does_not_prevent_the_others_succeeding(): void
    {
        Event::fake();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'boom')) {
                return Http::response('nope', 500);
            }

            return Http::response('ok', 200);
        });

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Async]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://boom.test/hook']);
        Destination::factory()->for($proxy)->count(2)->createQuietly();

        $this->post('https://localhost/ingest/'.$proxy->ingest_token, ['a' => 'b'])
            ->assertStatus(202);

        // T14: still un-faked, so the failing destination's scheduled `RetryDelivery`
        // also drains inline under sync, cascading through the system-default
        // attempt limit (5, config/retry.php) before terminalizing — 5 failed
        // attempts (one per cascade), 2 succeeded (the healthy destinations).
        $this->assertSame(2, DeliveryAttempt::where('status', AttemptStatus::Succeeded)->count());
        $this->assertSame(5, DeliveryAttempt::where('status', AttemptStatus::Failed)->count());
        Event::assertDispatchedTimes(DeliverySucceeded::class, 2);
        Event::assertDispatchedTimes(DeliveryFailed::class, 5);
    }
}
