<?php

namespace App\Actions;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DestinationValidationState;
use App\Enums\FifoDispatchStatus;
use App\Events\DeliveryAttempted;
use App\Events\DeliveryExhausted;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\FifoDispatch;
use App\Pipeline\DeliveryUnit;
use App\Services\DeliveryUnitResolver;
use App\Services\RetryPolicy;
use App\Support\IngestHostGuard;
use App\Support\OutboundHeaders;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * The delivery-level run-sync-or-queue action (ADR-003/005/011/015/016/020). Delivers
 * ONE unit to ONE destination, recording only outcome metadata — never the payload
 * (ADR-003). Invoked with `::run(DeliveryUnit $unit)` for an in-process/resolved
 * call (attempts 2..N arrive this way via `RetryDelivery`), or `::dispatch(int
 * $deliveryId, int $attemptNumber)` **by reference** onto the webhooks queue — the
 * job's own entry point, `asJob()` (ADR-020 Decision 7), taken in both Async and
 * FIFO modes alike. No payload bytes, header values, or destination model ever
 * travel in the queued job's arguments; `asJob()` resolves everything via the
 * shared `DeliveryUnitResolver` and then runs the same `handle(DeliveryUnit
 * $unit)` logic below, unchanged.
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
 *
 * On a FIFO proxy, a delivery that just settled into a terminal state (succeeded or
 * exhausted-failed) triggers the completion check (T17, ADR-016 Decision 1): if its
 * dispatch has no non-terminal deliveries left, the held `fifo_dispatches` row
 * compare-and-sets `awaiting_retry → settled` and the advancer is nudged to resume
 * the line. Async proxies have no matching `fifo_dispatches` row — the check is a
 * structural no-op for them.
 */
class DeliverToDestination
{
    use AsAction;

    /**
     * Outbound HTTP timeout in seconds.
     */
    private const TIMEOUT_SECONDS = 15;

    public int $tries = 1;

    public function __construct(
        private readonly RetryPolicy $retryPolicy,
        private readonly DeliveryUnitResolver $resolver,
    ) {}

    /**
     * The by-reference queue entry point (ADR-020 Decision 7) — what
     * `JobDecorator::handle()` calls in preference to `handle()` when this action
     * is dispatched as a job (`AsJob`'s `hasMethod('asJob')` check). Resolves the
     * `DeliveryUnit` via the shared `DeliveryUnitResolver`; a `null` result means
     * the parent event was cleaned before this attempt could run, terminalized
     * per `RetryDelivery::terminalizeCleaned()`'s semantics — compare-and-set
     * keyed on `pending` **and** `retrying` (correct for attempt 1 as well as any
     * later attempt reaching this entry point), no attempt row written, no send
     * made. Otherwise runs the existing `handle(DeliveryUnit $unit)` logic
     * unchanged.
     */
    public function asJob(int $deliveryId, int $attemptNumber): void
    {
        $delivery = Delivery::query()->findOrFail($deliveryId);

        $unit = $this->resolver->resolve($delivery, $attemptNumber);

        if ($unit === null) {
            $this->terminalizeCleaned($delivery);

            return;
        }

        $this->handle($unit);
    }

    /**
     * Terminalize a delivery whose parent event's payload has been erased before
     * this attempt could run (ADR-014 Decision 7; ADR-020 Decision 7's cleaned
     * branch) — no send is ever attempted, so no attempt row is ever written.
     * Reuses {@see self::transition()}'s compare-and-set, keyed on `pending` AND
     * `retrying` so it is correct whichever status the delivery holds when this
     * by-reference entry point runs; a zero-row CAS means another settler already
     * won and this is a no-op.
     */
    private function terminalizeCleaned(Delivery $delivery): void
    {
        $affected = $this->transition($delivery, DeliveryStatus::Failed, ['next_attempt_at' => null]);

        if (! $affected) {
            return;
        }

        $delivery->status = DeliveryStatus::Failed;
        $delivery->next_attempt_at = null;

        event(new DeliveryExhausted($delivery));

        Log::info('payload.expired', ['ingest_id' => $delivery->webhookEvent->ingest_id]);
    }

    public function handle(DeliveryUnit $unit): void
    {
        // Item #18 AC8 — the dispatch-gate, the second of the feature's two
        // enforcement points. `ProcessIngestedWebhook`'s queue-check stops an
        // unvalidated destination ever getting a row, but it cannot see a state
        // change that happens afterwards: a URL edit returns a destination to
        // unvalidated (AC5), and a 7-day challenge can expire while a delivery
        // waits on a retry backoff.
        //
        // Placed before `existingAttempt()` so a re-driven unit is caught too.
        if ($unit->destination->validation_state !== DestinationValidationState::Validated) {
            $this->skip($unit);

            return;
        }

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
     *
     * The outbound header set is built here, through `OutboundHeaders` (T26,
     * T34) — the one build point (plan-10 § Architecture C) — so the
     * credential and signing headers apply identically to attempt 1
     * (`asJob()`), every retry (`RetryDelivery`), and every replay, all of
     * which funnel into this same method.
     * `$unit->destination->credential_secret` decrypts via the model's
     * `encrypted` cast at read time here, in the send path, never earlier;
     * `$unit->signingSecrets` (T36) is already resolved by the time this
     * runs — only the signature itself, over the exact dispatched bytes and
     * this attempt's timestamp, is computed here.
     *
     * **Delivery-loop guard send-time backstop**
     * (`docs/briefs/delivery-loop-guard.md`): re-checks the destination's
     * host against this service's own ingest host immediately before the
     * HTTP call, via the same `IngestHostGuard` the save-time
     * `NotSelfReferencingDestinationUrl` rule uses. A row saved before that
     * rule existed is never re-validated by a form rule, and
     * `config('ingest.url')` can change after save so a previously-valid
     * destination becomes self-referential — this is the only re-check for
     * either case. Does not repeat the IP-literal check (static at save
     * time, not something a later config change turns an existing row
     * into). Fails this attempt with a clear `error_summary`, through the
     * same `catch (Throwable $e)` below — no new catch path.
     *
     * Guzzle follows redirects by default; `->withoutRedirecting()` on the
     * client below stops a destination answering 3xx from routing around
     * both this check and the save-time rule — the redirect response
     * itself settles as an ordinary failed attempt.
     */
    private function send(DeliveryUnit $unit, DeliveryAttempt $attempt): void
    {
        $startedAt = now();

        try {
            $host = IngestHostGuard::hostFrom($unit->destination->url);

            if ($host !== null && IngestHostGuard::pointsBackToIngest($host)) {
                throw new RuntimeException('Destination host resolves to this service\'s own ingest host; refusing to deliver.');
            }

            // T39, AC11's all-or-none rule: a signing secret that failed to
            // decrypt (deferred by `DeliveryUnitResolver`, T36, so the
            // `DeliveryAttempt` row above still gets created) fails THIS
            // destination's attempt here, before any header is built and
            // before any HTTP request is made — never a silent fallback to
            // an unsigned dispatch. Every destination of the same proxy
            // reads the identical corrupted `proxy_secrets` row through its
            // own independent `resolve()` call, so this is reached
            // identically by each of them; no shared state is needed to
            // coordinate "the whole proxy fails together".
            if ($unit->signingSecretsUnavailable !== null) {
                throw $unit->signingSecretsUnavailable;
            }

            $headers = OutboundHeaders::build(
                $unit,
                $unit->destination->credential_header_name,
                $unit->destination->credential_secret,
                $unit->signingSecrets,
            );

            $response = Http::withHeaders($headers)
                ->withoutRedirecting()
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
            $affected = $this->transition($delivery, DeliveryStatus::Succeeded, ['next_attempt_at' => null]);

            if ($affected) {
                $this->settleFifoLineIfComplete($delivery);
            }

            return;
        }

        // Trashed-inclusive: an in-flight delivery whose proxy was soft-deleted since
        // dispatch must still resolve its retry policy under its own settings (same
        // precedent as ProcessIngestedWebhook's `Proxy::withTrashed()` load and
        // DeliverStep/RetryDelivery's `destination()->withTrashed()`) rather than
        // have the default `belongsTo` scope return null into RetryPolicy's
        // non-nullable parameter.
        $proxy = $delivery->proxy()->withTrashed()->firstOrFail();
        $limit = $this->retryPolicy->attemptLimitFor($proxy);

        if ($unit->attemptNumber >= $limit) {
            $affected = $this->transition($delivery, DeliveryStatus::Failed, ['next_attempt_at' => null]);

            if ($affected) {
                $delivery->status = DeliveryStatus::Failed;
                $delivery->next_attempt_at = null;

                event(new DeliveryExhausted($delivery));

                $this->settleFifoLineIfComplete($delivery);
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
     * The FIFO `awaiting_retry → settled` completion check (T17, ADR-016
     * Decision 1): once a delivery has just settled into a terminal state,
     * close out its dispatch's held `fifo_dispatches` row when no sibling
     * delivery of the same dispatch remains non-terminal, and nudge the
     * advancer to resume the line. The compare-and-set is keyed on the prior
     * `awaiting_retry` status, so a racing duplicate settle (two deliveries of
     * the same dispatch completing near-simultaneously) transitions the row
     * at most once and nudges at most once. Async proxies never have a
     * matching `fifo_dispatches` row for the dispatch — the CAS affects zero
     * rows and this is a structural no-op for them (no proxy-mode branch
     * needed).
     */
    private function settleFifoLineIfComplete(Delivery $delivery): void
    {
        $hasOpenDeliveries = Delivery::query()
            ->where('dispatch_uuid', $delivery->dispatch_uuid)
            ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Retrying])
            ->exists();

        if ($hasOpenDeliveries) {
            return;
        }

        $affected = FifoDispatch::query()
            ->where('dispatch_uuid', $delivery->dispatch_uuid)
            ->where('status', FifoDispatchStatus::AwaitingRetry)
            ->update(['status' => FifoDispatchStatus::Settled, 'settled_at' => now()]);

        if ($affected > 0) {
            AdvanceProxyFifoQueue::dispatch($delivery->proxy_id);
        }
    }

    /**
     * Resolve a delivery whose destination is no longer validated (#18 AC8,
     * AC11; ADR-028). Terminal, so the FIFO completion check settles the line
     * rather than holding it — but **not** a failure: no attempt row is
     * written because no attempt is made, no `DeliveryExhausted` or
     * `DeliveryFailed` fires, and `DeliveryStatistics` excludes it from both
     * the numerator and the denominator of every rate because its filters are
     * positive on `succeeded` and `failed`.
     *
     * A zero-row compare-and-set means another settler already resolved this
     * delivery; nothing further is owed.
     */
    private function skip(DeliveryUnit $unit): void
    {
        $delivery = Delivery::query()->find($unit->deliveryId);

        if ($delivery === null) {
            return;
        }

        $affected = $this->transition($delivery, DeliveryStatus::Skipped, ['next_attempt_at' => null]);

        if (! $affected) {
            return;
        }

        Log::info('delivery.skipped_unvalidated_destination', [
            'delivery_id' => $delivery->id,
            'destination_id' => $unit->destination->id,
        ]);

        $delivery->status = DeliveryStatus::Skipped;
        $delivery->next_attempt_at = null;

        $this->settleFifoLineIfComplete($delivery);
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
