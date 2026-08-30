<?php

namespace Tests\Feature\Retention;

use App\Actions\CaptureDispatchedStep;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\ProxyMode;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStep;
use Closure;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end proof of the dispatched-output store through the real, wired
 * pipeline (T9) — complementing T7/T8's unit-level step tests (AC12-AC15,
 * AC19).
 */
class DispatchedOutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    /**
     * @return array{0: Proxy, 1: string}
     */
    private function proxyWithToken(array $attributes = []): array
    {
        $proxy = Proxy::factory()->createQuietly($attributes);
        Destination::factory()->for($proxy)->createQuietly();

        return [$proxy, $proxy->ingest_token];
    }

    private function ingest(string $token, string $raw): TestResponse
    {
        return $this->call('POST', 'https://localhost/ingest/'.$token, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function test_an_enhanced_mode_proxy_produces_exactly_one_output_row_associated_to_the_event(): void
    {
        [$proxy, $token] = $this->proxyWithToken(['mode' => ProxyMode::Enhanced]);

        $this->ingest($token, '{"id":"evt_1"}')->assertStatus(202);

        $event = WebhookEvent::firstOrFail();
        $this->assertSame(1, DispatchedPayload::count());
        $this->assertSame($event->id, DispatchedPayload::query()->sole()->webhook_event_id);
    }

    public function test_a_simple_mode_proxy_produces_no_output_row(): void
    {
        [, $token] = $this->proxyWithToken(['mode' => ProxyMode::Simple]);

        $this->ingest($token, '{"id":"evt_1"}')->assertStatus(202);

        $this->assertSame(0, DispatchedPayload::count());
    }

    public function test_multiple_destinations_for_one_event_still_produce_exactly_one_output_row(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        Destination::factory()->for($proxy)->count(3)->createQuietly();

        $this->ingest($proxy->ingest_token, '{"id":"evt_1"}')->assertStatus(202);

        Http::assertSentCount(3);
        $this->assertSame(1, DispatchedPayload::count());
    }

    public function test_the_identical_case_stores_a_null_body_with_the_raw_byte_size_and_dispatched_at_set(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();
        $raw = '{"id":"evt_1"}';

        $this->ingest($proxy->ingest_token, $raw)->assertStatus(202);

        $row = DispatchedPayload::query()->sole();
        $this->assertNull($row->body);
        $this->assertSame(strlen($raw), $row->byte_size);
        $this->assertNotNull($row->dispatched_at);
    }

    public function test_the_diverged_case_stores_the_dispatched_bytes_encrypted_at_rest(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();
        $raw = '{"id":"evt_1"}';
        $this->ingest($proxy->ingest_token, $raw)->assertStatus(202);
        $event = WebhookEvent::firstOrFail();
        DispatchedPayload::query()->delete();

        $diverged = '{"id":"evt_1","mapped":true}';
        $mutateStep = new class($diverged) implements PipelineStep
        {
            public function __construct(private string $diverged) {}

            /**
             * @param  Closure(PipelineContext): PipelineContext  $next
             */
            public function handle(PipelineContext $ctx, Closure $next): PipelineContext
            {
                $ctx->payload = $this->diverged;

                return $next($ctx);
            }
        };
        $ctx = new PipelineContext(
            ingestId: $event->ingest_id,
            proxy: $proxy,
            method: 'POST',
            headers: ['content-type' => ['application/json']],
            rawBody: $raw,
        );

        app(Pipeline::class)
            ->send($ctx)
            ->through([$mutateStep, CaptureDispatchedStep::make()])
            ->thenReturn();

        $row = DispatchedPayload::query()->sole();
        $this->assertSame($diverged, $row->fresh()->body);
        $this->assertSame(strlen($diverged), $row->byte_size);

        // Ciphertext at rest is not the plaintext.
        $stored = DB::table('dispatched_payloads')->where('id', $row->id)->value('body');
        $this->assertNotSame($diverged, $stored);
    }

    public function test_the_raw_webhook_events_row_and_output_row_count_are_unchanged_by_a_redelivery(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        $this->ingest($proxy->ingest_token, '{"id":"evt_1"}')->assertStatus(202);
        $event = WebhookEvent::firstOrFail();
        $before = DB::table('webhook_events')->where('id', $event->id)->first();

        // Simulate queue redelivery: re-invoke the pipeline-level action directly.
        ProcessIngestedWebhook::run($event->ingest_id);

        $after = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertEquals($before, $after, 'The raw captured row is never written by the output step.');
        $this->assertSame(1, DispatchedPayload::count(), 'Redelivery updates, never duplicates, the output row.');
    }

    public function test_reprocessing_an_event_whose_parent_is_already_cleaned_writes_nothing_and_delivers_nothing(): void
    {
        $proxy = Proxy::factory()->enhanced()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        ProcessIngestedWebhook::run($event->ingest_id);

        $this->assertSame(0, DispatchedPayload::count());
        Http::assertNothingSent();
    }
}
