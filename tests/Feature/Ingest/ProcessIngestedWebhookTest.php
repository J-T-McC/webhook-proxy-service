<?php

namespace Tests\Feature\Ingest;

use App\Actions\ProcessIngestedWebhook;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
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
