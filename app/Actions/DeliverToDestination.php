<?php

namespace App\Actions;

use App\Enums\AttemptStatus;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\DeliveryAttempt;
use App\Pipeline\DeliveryUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The delivery-level run-sync-or-queue action (ADR-003/005/011/015). Delivers ONE
 * unit to ONE destination, recording only outcome metadata — never the payload
 * (ADR-003). Invoked with `::run` inline (FIFO) or `::dispatch` onto the webhooks
 * queue (Async).
 *
 * Idempotent against the queue's inherent at-least-once redelivery (ADR-011 Decision
 * 4, AC9), guarded by the `UNIQUE(delivery_id, attempt_number)` index (ADR-015
 * Decision 2 — replaces the pre-#6 `(ingest_id, destination_id, attempt_number)` key,
 * which could not survive replay: two different deliveries legitimately share
 * `attempt_number = 1`): a redelivery of an already-settled unit is a no-op (no send,
 * no duplicate row/event); a unit left `dispatched` by a crashed worker is re-driven
 * on the SAME row. `ingest_id` is still written to the row (team-scoped browsing) but
 * is no longer part of the create-or-resume lookup. No retry/scheduling behaviour is
 * added here (`$tries = 1` unchanged, a failed attempt still just fails) — that is M3.
 */
class DeliverToDestination
{
    use AsAction;

    /**
     * Outbound HTTP timeout in seconds.
     */
    private const TIMEOUT_SECONDS = 15;

    public int $tries = 1;

    public function handle(DeliveryUnit $unit): void
    {
        $existing = $this->existingAttempt($unit);

        if ($existing !== null) {
            $this->resume($existing, $unit);

            return;
        }

        // Create the durable 'dispatched' row (payload-free) BEFORE the outcome is
        // known, so a crash still leaves a re-drivable row (ADR-003). A concurrent
        // redelivery may race us on the unique index — treat that as "already exists".
        try {
            $attempt = DeliveryAttempt::create([
                'team_id' => $unit->teamId,
                'proxy_id' => $unit->proxyId,
                'destination_id' => $unit->destination->id,
                'ingest_id' => $unit->ingestId,
                'delivery_id' => $unit->deliveryId,
                'status' => AttemptStatus::Dispatched,
                'attempt_number' => $unit->attemptNumber,
                'started_at' => now(),
            ]);
        } catch (QueryException $e) {
            $raced = $this->existingAttempt($unit);

            if ($raced === null) {
                throw $e;
            }

            $this->resume($raced, $unit);

            return;
        }

        event(new DeliveryAttempted($attempt));

        $this->send($unit, $attempt);
    }

    /**
     * The existing attempt for this idempotency key, if any.
     */
    private function existingAttempt(DeliveryUnit $unit): ?DeliveryAttempt
    {
        return DeliveryAttempt::query()
            ->where('delivery_id', $unit->deliveryId)
            ->where('attempt_number', $unit->attemptNumber)
            ->first();
    }

    /**
     * Apply the redelivery rule to a pre-existing attempt: a terminal row is a
     * settled unit and is skipped; a still-`dispatched` row (crashed mid-flight) is
     * re-driven to settlement on the SAME row (never a duplicate).
     */
    private function resume(DeliveryAttempt $attempt, DeliveryUnit $unit): void
    {
        if ($attempt->status !== AttemptStatus::Dispatched) {
            return;
        }

        $this->send($unit, $attempt);
    }

    /**
     * Perform the outbound send and settle the given attempt row in place.
     */
    private function send(DeliveryUnit $unit, DeliveryAttempt $attempt): void
    {
        $startedAt = now();

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
