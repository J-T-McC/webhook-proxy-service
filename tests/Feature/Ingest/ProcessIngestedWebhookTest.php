<?php

namespace Tests\Feature\Ingest;

use App\Actions\ProcessIngestedWebhook;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Pipeline\PipelineContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessIngestedWebhookTest extends TestCase
{
    public function test_running_the_pipeline_delivers_once_per_live_destination(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->create();
        Destination::factory()->for($proxy)->count(3)->create();
        $trashed = Destination::factory()->for($proxy)->create();
        $trashed->delete();

        $ctx = new PipelineContext(
            ingestId: 'ingest-xyz',
            proxy: $proxy->fresh(),
            method: 'POST',
            headers: ['Content-Type' => ['application/json']],
            rawBody: '{"hello":"world"}',
        );

        ProcessIngestedWebhook::run($ctx);

        // One delivery attempt per live destination (trashed excluded).
        $this->assertSame(3, DeliveryAttempt::count());
        Http::assertSentCount(3);
    }
}
