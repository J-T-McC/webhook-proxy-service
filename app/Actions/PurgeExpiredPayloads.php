<?php

namespace App\Actions;

use App\Enums\AttemptStatus;
use App\Enums\FifoDispatchStatus;
use App\Models\Team;
use App\Services\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * The garbage collector (AC5, AC6, AC9, AC12, AC22b; ADR-012 Decisions 1, 4, 6,
 * 7). Registered as the `payloads:purge-expired` console command (scheduled
 * daily, routes/console.php). Per run, iterates `Team::withTrashed()` — a
 * soft-deleted team's payloads must still expire (plan Risk 12) — and, per
 * team, erases every `webhook_events` row past its retention window under
 * holds H0-H4, one short transaction per event: a conditional compare-and-set
 * `UPDATE` re-asserting H0-H4 in its own `WHERE`, and — only if it affected
 * exactly one row — nulling the sibling `dispatched_payloads.body` in the same
 * transaction (AC12 atomicity). Zero rows affected on the first `UPDATE` means
 * a hold reappeared since selection; the event is skipped, never the second
 * `UPDATE`. Nothing is ever deleted anywhere.
 *
 * Holds: **H0** `payload_cleaned_at IS NULL`; **H1** `created_at <= cutoff`;
 * **H2** no `fifo_dispatches` row for the event with a non-`settled` status;
 * **H3** no `delivery_attempts` row for the event's `ingest_id` with status
 * `dispatched`; **H4** if the event has zero `delivery_attempts` rows, it must
 * be older than `retention.dispatch_horizon_minutes`. `fifo_dispatches` and
 * `delivery_attempts` are read-only here (ADR-012 Decision 5).
 *
 * `RetentionPolicy::cutoffFor($team)` is computed once per team, from the
 * `Team` already in hand for the batch — never per row via `expiresAt()`'s
 * per-event `Team::withTrashed()->findOrFail()` resolver, which would be one
 * extra query per event.
 *
 * Logs counts and identifiers only — never payload content
 * (docs/standards/coding.md's never-log list, binding).
 *
 * Enforces plan-05 §Validation's *Config sanity* invariant for
 * `retention.purge_batch` (must be a positive integer) and
 * `retention.dispatch_horizon_minutes` (must be a non-negative integer) once,
 * at command entry, before any team is touched — never per team. A batch
 * size of zero or less would make the per-team selection `LIMIT` return zero
 * rows forever, so `while (count($ids) === $batchSize)` never terminates
 * (review-05 finding 1(b)); resolving it up front means the do/while loop
 * body is unreachable with an invalid value, not merely guarded inside it.
 * `retention.days` is guarded at its own single seam, `RetentionPolicy::
 * windowFor()`, per plan-05's designated resolver for that value.
 */
class PurgeExpiredPayloads
{
    use AsAction;

    public string $commandSignature = 'payloads:purge-expired';

    public function __construct(private readonly RetentionPolicy $policy) {}

    public function handle(): void
    {
        $batchSize = $this->requirePositiveBatchSize();
        $horizonMinutes = $this->requireNonNegativeHorizonMinutes();

        Team::query()->withTrashed()->chunkById(100, function ($teams) use ($batchSize, $horizonMinutes): void {
            foreach ($teams as $team) {
                $this->purgeForTeam($team, $batchSize, $horizonMinutes);
            }
        });
    }

    /**
     * @throws RuntimeException if `retention.purge_batch` does not resolve
     *                          to a positive integer.
     */
    private function requirePositiveBatchSize(): int
    {
        $batchSize = (int) config('retention.purge_batch');

        if ($batchSize < 1) {
            throw new RuntimeException(sprintf(
                "config('retention.purge_batch') must resolve to a positive integer; got %d. Refusing ".
                'to silently substitute a default — a batch size of zero or less makes the selection '.
                "LIMIT return zero rows forever, hanging the scheduled command's batch terminator in ".
                'an infinite loop on the first team.',
                $batchSize,
            ));
        }

        return $batchSize;
    }

    /**
     * @throws RuntimeException if `retention.dispatch_horizon_minutes` does
     *                          not resolve to a non-negative integer.
     */
    private function requireNonNegativeHorizonMinutes(): int
    {
        $horizonMinutes = (int) config('retention.dispatch_horizon_minutes');

        if ($horizonMinutes < 0) {
            throw new RuntimeException(sprintf(
                "config('retention.dispatch_horizon_minutes') must resolve to a non-negative integer; ".
                'got %d. Refusing to silently substitute a default.',
                $horizonMinutes,
            ));
        }

        return $horizonMinutes;
    }

    /**
     * Loop a single team's batches until one comes back short of the limit.
     */
    private function purgeForTeam(Team $team, int $batchSize, int $horizonMinutes): void
    {
        $cutoff = $this->policy->cutoffFor($team);
        $horizon = CarbonImmutable::now()->subMinutes($horizonMinutes);

        do {
            $ids = $this->selectCollectableIds($team, $cutoff, $horizon, $batchSize);

            $erased = 0;
            foreach ($ids as $id) {
                if ($this->eraseOne($id, $cutoff, $horizon)) {
                    $erased++;
                }
            }

            if ($erased > 0) {
                Log::info('payload.purged', ['team_id' => $team->id, 'count' => $erased]);
            }
        } while (count($ids) === $batchSize);
    }

    /**
     * @return list<int>
     */
    private function selectCollectableIds(Team $team, CarbonImmutable $cutoff, CarbonImmutable $horizon, int $limit): array
    {
        $ids = $this->applyHolds(
            DB::table('webhook_events')->where('team_id', $team->id),
            $cutoff,
            $horizon,
        )
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return array_values($ids->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * Erase one event's raw payload and its dispatched output in one
     * transaction. Returns whether the event was actually erased (false means
     * a hold reappeared between selection and this call, and the event was
     * skipped).
     */
    private function eraseOne(int $id, CarbonImmutable $cutoff, CarbonImmutable $horizon): bool
    {
        return DB::transaction(function () use ($id, $cutoff, $horizon): bool {
            $affected = $this->applyHolds(
                DB::table('webhook_events')->where('id', $id),
                $cutoff,
                $horizon,
            )->update([
                'body' => null,
                'headers' => null,
                'payload_cleaned_at' => now(),
            ]);

            if ($affected !== 1) {
                return false;
            }

            DB::table('dispatched_payloads')
                ->where('webhook_event_id', $id)
                ->update(['body' => null]);

            return true;
        });
    }

    /**
     * Holds H0-H4, expressed once and applied identically to the selection
     * query and to the erase `UPDATE`'s own `WHERE` — the compare-and-set
     * guarantee. The selection query is an optimisation; re-application on the
     * mutating statement is the correctness guarantee (plan §Validation).
     */
    private function applyHolds(Builder $query, CarbonImmutable $cutoff, CarbonImmutable $horizon): Builder
    {
        return $query
            ->whereNull('payload_cleaned_at') // H0
            ->where('created_at', '<=', $cutoff) // H1
            ->whereNotExists(function (Builder $q): void {
                $q->select('id')
                    ->from('fifo_dispatches')
                    ->whereColumn('fifo_dispatches.webhook_event_id', 'webhook_events.id')
                    ->where('status', '!=', FifoDispatchStatus::Settled->value);
            }) // H2
            ->whereNotExists(function (Builder $q): void {
                $q->select('id')
                    ->from('delivery_attempts')
                    ->whereColumn('delivery_attempts.ingest_id', 'webhook_events.ingest_id')
                    ->where('status', AttemptStatus::Dispatched->value);
            }) // H3
            ->where(function (Builder $q) use ($horizon): void {
                $q->whereExists(function (Builder $qq): void {
                    $qq->select('id')
                        ->from('delivery_attempts')
                        ->whereColumn('delivery_attempts.ingest_id', 'webhook_events.ingest_id');
                })->orWhere('created_at', '<=', $horizon);
            }); // H4
    }
}
