<?php

namespace App\Actions;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryExhausted;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Pipeline\DeliveryUnit;
use App\Services\RetryPolicy;
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
 * is no longer part of the create-or-resume lookup.
 *
 * After an attempt settles (never on a resume-skip), the delivery row is
 * transitioned by compare-and-set (ADR-015 Decisions 5/6): success ⇒ `succeeded`;
 * failure at/above `RetryPolicy::attemptLimitFor()` ⇒ `failed` (terminal),
 * `DeliveryExhausted` firing iff the CAS affected a row (the once-guard); failure
 * below the limit ⇒ `retrying` + `next_attempt_at`, plus a delayed `RetryDelivery`
 * dispatch for the next attempt. A zero-row CAS (another settler already won) does
 * nothing further — no event, no schedule, no double-dispatch.
 */
class DeliverToDestination
{
    use AsAction;

    /**
     * Outbound HTTP timeout in seconds.
     */
    private const TIMEOUT_SECONDS = 15;

    public int $tries = 1;

    public function __construct(private readonly RetryPolicy $retryPolicy) {}

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
     * Perform the outbound send, settle the given attempt row in place, and
     * transition the parent delivery row accordingly.
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
            $succeeded = false;

            $attempt->update([
                'status' => AttemptStatus::Failed,
                // 247 + '...' = 250 chars max, fitting the string(250) column. Summary
                // only — never a payload/body (ADR-003 / AC15).
                'error_summary' => Str::limit($e->getMessage(), 247),
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
            ]);

            event(new DeliveryFailed($attempt));
        }

        $this->settleDelivery($unit, $succeeded);
    }

    /**
     * Transition the attempt's `Delivery` row by compare-and-set (ADR-015
     * Decisions 5/6, plan-06 binding invariant — never a blind `save()`):
     * success ⇒ `succeeded`; failure at/above the resolved attempt limit ⇒
     * `failed` (terminal), emitting `DeliveryExhausted` iff the CAS affected a
     * row (the once-guard); failure below the limit ⇒ `retrying` +
     * `next_attempt_at`, plus a delayed `RetryDelivery` dispatch for the next
     * attempt. A zero-row CAS (another settler already won) does nothing
     * further.
     */
    private function settleDelivery(DeliveryUnit $unit, bool $succeeded): void
    {
        $delivery = Delivery::query()->findOrFail($unit->deliveryId);

        if ($succeeded) {
            $this->transition($delivery, DeliveryStatus::Succeeded, ['next_attempt_at' => null]);

            return;
        }

        $proxy = $delivery->proxy;
        $limit = $this->retryPolicy->attemptLimitFor($proxy);

        if ($unit->attemptNumber >= $limit) {
            $affected = $this->transition($delivery, DeliveryStatus::Failed, ['next_attempt_at' => null]);

            if ($affected) {
                $delivery->status = DeliveryStatus::Failed;
                $delivery->next_attempt_at = null;

                event(new DeliveryExhausted($delivery));
            }

            return;
        }

        $nextAttemptNumber = $unit->attemptNumber + 1;
        $delay = $this->retryPolicy->delayBefore($proxy, $nextAttemptNumber);
        $nextAttemptAt = now()->add($delay);

        $affected = $this->transition($delivery, DeliveryStatus::Retrying, ['next_attempt_at' => $nextAttemptAt]);

        if ($affected) {
            RetryDelivery::dispatch($delivery->id, $nextAttemptNumber)
                ->delay($delay)
                ->onQueue(config('ingest.webhooks_queue'));
        }
    }

    /**
     * Compare-and-set `$delivery`'s status to `$to`, keyed on the prior
     * non-terminal statuses (`pending`/`retrying`). Returns whether the CAS
     * affected a row; a zero-row result means another settler already
     * transitioned this delivery — the caller must do nothing further.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Delivery $delivery, DeliveryStatus $to, array $attributes): bool
    {
        return Delivery::query()
            ->whereKey($delivery->id)
            ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Retrying])
            ->update(['status' => $to, ...$attributes]) > 0;
    }
}
