<?php

namespace Tests\Unit\Http;

use App\Models\Destination;
use App\Models\Proxy;
use App\Services\WebhookEventCapture;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers T46 (R5; plan Technical ruling 8): `IngestController`'s capture-failure
 * `report()` wrap never leaks `QueryException::formatMessage()`'s interpolated SQL
 * (statement text or bindings) into what gets reported, regardless of which write
 * inside the wrapped transaction failed.
 */
class IngestControllerReportWrapTest extends TestCase
{
    private function proxyWithToken(): array
    {
        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        return [$proxy, $proxy->ingest_token];
    }

    public function test_a_query_exception_during_capture_is_reported_without_the_interpolated_sql(): void
    {
        Exceptions::fake();

        [$proxy, $token] = $this->proxyWithToken();

        // Stands in for a ciphertext binding — proxy_secrets/destinations.credential_secret
        // and webhook_events' payload column are all `encrypted`-cast, so a real binding
        // would look like this, never plaintext. It must not appear in the report either way.
        $binding = 'ciphertext-binding-must-never-appear-in-a-report';
        $sql = 'insert into `webhook_events` (`raw_payload`, `proxy_id`) values (?, ?)';

        $this->mock(
            WebhookEventCapture::class,
            fn (MockInterface $m) => $m->shouldReceive('capture')->andThrow(new QueryException(
                'mysql',
                $sql,
                [$binding, $proxy->id],
                new PDOException('SQLSTATE[23000]: Integrity constraint violation', 23000),
            )),
        );

        $this->post('https://localhost/ingest/'.$token, ['hello' => 'world'])
            ->assertStatus(500);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(function (RuntimeException $e) use ($proxy, $binding, $sql) {
            $message = $e->getMessage();

            return str_contains($message, 'proxy_id='.$proxy->id)
                && str_contains($message, 'sqlstate=23000')
                && ! str_contains($message, $binding)
                && ! str_contains($message, $sql)
                && $e->getPrevious() === null;
        });
    }

    /**
     * The same sanitized shape applies table-agnostically — a failed secret write
     * (`proxy_secrets`/`destinations.credential_secret`) inside the same wrapped
     * transaction is indistinguishable from any other write that throws here, so one
     * `QueryException` case (above) already covers it; this proves the wrap is not
     * accidentally `QueryException`-only.
     */
    public function test_a_non_query_exception_during_capture_is_reported_without_a_sqlstate(): void
    {
        Exceptions::fake();

        [, $token] = $this->proxyWithToken();

        $this->mock(
            WebhookEventCapture::class,
            fn (MockInterface $m) => $m->shouldReceive('capture')
                ->andThrow(new RuntimeException('capture store unavailable')),
        );

        $this->post('https://localhost/ingest/'.$token, ['hello' => 'world'])
            ->assertStatus(500);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(function (RuntimeException $e) {
            $message = $e->getMessage();

            return ! str_contains($message, 'sqlstate=')
                && ! str_contains($message, 'capture store unavailable')
                && $e->getPrevious() === null;
        });
    }
}
