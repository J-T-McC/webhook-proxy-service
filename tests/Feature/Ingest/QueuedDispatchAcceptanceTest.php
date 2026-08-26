<?php

namespace Tests\Feature\Ingest;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\ProcessingMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Services\WebhookEventCapture;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * End-to-end proof that the queued-dispatch half is wired correctly over the real
 * ingest route, preserving #3's decoupled-response guarantees (T13, AC1–AC3).
 */
class QueuedDispatchAcceptanceTest extends TestCase
{
    private function proxy(ProcessingMode $mode): Proxy
    {
        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => $mode,
            'response_status' => 200,
            'response_body' => 'ACK',
        ]);
        Destination::factory()->for($proxy)->createQuietly();

        return $proxy;
    }

    private function ingest(Proxy $proxy): TestResponse
    {
        return $this->post('https://localhost/ingest/'.$proxy->ingest_token, ['hello' => 'world']);
    }

    /** @return array<int, array{0: ProcessingMode}> */
    public static function modes(): array
    {
        return [
            'async' => [ProcessingMode::Async],
            'fifo' => [ProcessingMode::Fifo],
        ];
    }

    #[DataProvider('modes')]
    public function test_ingest_dispatches_processing_and_runs_no_delivery_inline(ProcessingMode $mode): void
    {
        Queue::fake();

        $proxy = $this->proxy($mode);

        $this->ingest($proxy)->assertStatus(200)->assertSee('ACK');

        // Dispatched, not run inline: no attempt exists at request return (AC1).
        $this->assertSame(0, DeliveryAttempt::count());

        if ($mode === ProcessingMode::Fifo) {
            AdvanceProxyFifoQueue::assertPushed(1);
            $this->assertSame(1, FifoDispatch::count());
        } else {
            ProcessIngestedWebhook::assertPushed(1);
            $this->assertSame(0, FifoDispatch::count());
        }
    }

    #[DataProvider('modes')]
    public function test_response_is_independent_of_a_failing_destination(ProcessingMode $mode): void
    {
        // No Queue::fake — under the sync driver the dispatched work drains inline,
        // so the destination's failure happens within the request lifecycle. The
        // response was resolved BEFORE delivery, so it is unaffected (AC2, ADR-004).
        Http::fake(['*' => Http::response('upstream boom', 500)]);

        $proxy = $this->proxy($mode);

        $this->ingest($proxy)->assertStatus(200)->assertSee('ACK');

        // Delivery ran and recorded a failure — but the response above was unchanged.
        // T14: still un-faked (the point of this test), so a failure's scheduled
        // `RetryDelivery` also drains inline under sync, cascading through the
        // system-default attempt limit (5, config/retry.php) before terminalizing.
        $this->assertSame(5, DeliveryAttempt::count());
        $this->assertSame('failed', DeliveryAttempt::firstOrFail()->status->value);
    }

    #[DataProvider('modes')]
    public function test_response_is_independent_of_a_throwing_destination(ProcessingMode $mode): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $proxy = $this->proxy($mode);

        $this->ingest($proxy)->assertStatus(200)->assertSee('ACK');

        $this->assertSame('failed', DeliveryAttempt::firstOrFail()->status->value);
    }

    #[DataProvider('modes')]
    public function test_capture_failure_returns_500_and_dispatches_nothing(ProcessingMode $mode): void
    {
        Queue::fake();
        Http::fake();

        $proxy = $this->proxy($mode);

        $this->mock(
            WebhookEventCapture::class,
            fn (MockInterface $m) => $m->shouldReceive('capture')
                ->andThrow(new \RuntimeException('capture store unavailable')),
        );

        $this->ingest($proxy)->assertStatus(500);

        $this->assertSame(0, FifoDispatch::count());
        Queue::assertNothingPushed();
    }
}
