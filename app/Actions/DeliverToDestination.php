<?php

namespace App\Actions;

use App\Enums\AttemptStatus;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\DeliveryAttempt;
use App\Pipeline\DeliveryUnit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The delivery-level run-sync-or-queue action (ADR-003/005). Delivers ONE unit to
 * ONE destination, recording only outcome metadata — never the payload (ADR-003).
 * At item #1 it is invoked with `::run` (inline); #4 flips to `::dispatch`.
 */
class DeliverToDestination
{
    use AsAction;

    /**
     * Outbound HTTP timeout in seconds.
     */
    private const TIMEOUT_SECONDS = 15;

    public function handle(DeliveryUnit $unit): void
    {
        $startedAt = now();

        // Durable source of truth — written BEFORE the outcome is known so a crash
        // still leaves a 'dispatched' row (crash safety, ADR-003). Payload-free.
        $attempt = DeliveryAttempt::create([
            'team_id' => $unit->teamId,
            'proxy_id' => $unit->proxyId,
            'destination_id' => $unit->destination->id,
            'ingest_id' => $unit->ingestId,
            'status' => AttemptStatus::Dispatched,
            'attempt_number' => $unit->attemptNumber,
            'started_at' => $startedAt,
        ]);

        event(new DeliveryAttempted($attempt));

        try {
            $response = Http::withHeaders($unit->forwardHeaders())
                ->timeout(self::TIMEOUT_SECONDS)
                ->send($unit->method, $unit->destination->url, ['body' => $unit->payload]);

            $succeeded = $response->successful();

            $attempt->update([
                'status' => $succeeded ? AttemptStatus::Succeeded : AttemptStatus::Failed,
                'http_status' => $response->status(),
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
            ]);

            $succeeded
                ? event(new DeliverySucceeded($attempt))
                : event(new DeliveryFailed($attempt));
        } catch (Throwable $e) {
            $attempt->update([
                'status' => AttemptStatus::Failed,
                // 247 + '...' = 250 chars max, fitting the string(250) column. Summary
                // only — never a payload/body (ADR-003 / AC15).
                'error_summary' => Str::limit($e->getMessage(), 247),
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
            ]);

            event(new DeliveryFailed($attempt));
        }
    }
}
