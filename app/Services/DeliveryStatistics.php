<?php

namespace App\Services;

use App\Data\Analytics\RetryReplayFigures;
use App\Data\Analytics\UnitFigure;
use App\Enums\AnalyticsWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The single resolver for every number item #11 displays (plan-11 §
 * Services & Actions), stateless and with no HTTP knowledge, in the same
 * tradition as `RetryPolicy`/`StoredPayloadLookup`. No other class may build
 * an analytics query — not a controller, not a model scope, not a Vue
 * component computing a rate from raw counts on the client.
 *
 * Binding invariants, load-bearing for every method added to this class
 * across item #11's tasks (plan-11 §§ Architecture, Technical rulings, Risks):
 * - No lock, no transaction, on any analytics read path.
 * - No query selects `webhook_events.body` or `headers`, and no aggregate
 *   hydrates a `WebhookEvent` model — this class never reads `webhook_events`
 *   at all.
 * - No aggregate joins or eager-loads `proxies` or `destinations`;
 *   `withTrashed()` is reserved for the two label-lookup call sites (T8).
 * - Every query states its own `team_id` (or a policy-gated `proxy_id`)
 *   explicitly; never `withoutGlobalScope(TeamScope::class)` (`ApplyTeamScope`
 *   does not scope `Delivery` at all, and this class never relies on it for
 *   `DeliveryAttempt` either — Technical ruling 7).
 * - `deliveries` and `delivery_attempts` are never joined to each other for a
 *   success/failure figure — the two units come from two disjoint queries.
 * - A rate with a zero denominator is `null`, never `0`; counts are always
 *   integers, always present, including `0`.
 * - The window anchor is `updated_at` on both fact tables (Technical ruling
 *   1) — never `created_at`, never `received_at`.
 */
class DeliveryStatistics
{
    /**
     * Delivery-level and attempt-level success/failure figures for the given
     * team, over the given window (AC7, AC13).
     *
     * @return array{delivery: UnitFigure, attempt: UnitFigure}
     */
    public function unitFiguresForTeam(int $teamId, AnalyticsWindow $window): array
    {
        return $this->unitFigures(['team_id' => $teamId], $window);
    }

    /**
     * Delivery-level and attempt-level success/failure figures for the given
     * proxy, over the given window (AC7, AC13).
     *
     * @return array{delivery: UnitFigure, attempt: UnitFigure}
     */
    public function unitFiguresForProxy(int $proxyId, AnalyticsWindow $window): array
    {
        return $this->unitFigures(['proxy_id' => $proxyId], $window);
    }

    /**
     * Delivery-level and attempt-level success/failure figures for the given
     * destination within the given proxy, over the given window (AC7, AC13,
     * AC15).
     *
     * @return array{delivery: UnitFigure, attempt: UnitFigure}
     */
    public function unitFiguresForDestination(int $proxyId, int $destinationId, AnalyticsWindow $window): array
    {
        return $this->unitFigures(['proxy_id' => $proxyId, 'destination_id' => $destinationId], $window);
    }

    /**
     * Retry/replay figures (AC19) plus the bridge-sentence count for the
     * given team, over the given window.
     *
     * @return array{retryReplay: RetryReplayFigures, bridgeFailedAttempts: int}
     */
    public function retryReplayForTeam(int $teamId, AnalyticsWindow $window): array
    {
        return $this->retryReplayAndBridge(['team_id' => $teamId], $window);
    }

    /**
     * Retry/replay figures (AC19) plus the bridge-sentence count for the
     * given proxy, over the given window.
     *
     * @return array{retryReplay: RetryReplayFigures, bridgeFailedAttempts: int}
     */
    public function retryReplayForProxy(int $proxyId, AnalyticsWindow $window): array
    {
        return $this->retryReplayAndBridge(['proxy_id' => $proxyId], $window);
    }

    /**
     * The delivery-level and attempt-level `UnitFigure` pair for one grain,
     * from `deliveries` and `delivery_attempts` respectively — never joined
     * to each other. Pre-#6 `delivery_attempts` rows (`delivery_id IS NULL`)
     * are structurally excluded from the delivery-level figure (that query
     * never reads `delivery_attempts`) and structurally included in the
     * attempt-level one (no `delivery_id` clause exists to exclude them) —
     * `Q-11-03(4)`.
     *
     * @param  array<string, int>  $constraints
     * @return array{delivery: UnitFigure, attempt: UnitFigure}
     */
    private function unitFigures(array $constraints, AnalyticsWindow $window): array
    {
        return [
            'delivery' => $this->deliveryUnitFigure($this->deliveryAggregates($constraints, $window)),
            'attempt' => $this->attemptUnitFigure($this->attemptAggregates($constraints, $window)),
        ];
    }

    /**
     * `RetryReplayFigures` plus the bridge-sentence count for one grain.
     * `eventualSuccess` and the bridge count are the two deliberate places
     * this service reads across both tables for one grain (plan-11 §
     * Architecture A) — everything else here reuses the single grouped
     * aggregate per table.
     *
     * @param  array<string, int>  $constraints
     * @return array{retryReplay: RetryReplayFigures, bridgeFailedAttempts: int}
     */
    private function retryReplayAndBridge(array $constraints, AnalyticsWindow $window): array
    {
        $deliveryRows = $this->deliveryAggregates($constraints, $window);
        $attemptRows = $this->attemptAggregates($constraints, $window);

        $retryReplay = new RetryReplayFigures(
            eventualSuccess: $this->eventualSuccessCount($constraints, $window),
            terminalFailure: (int) $deliveryRows->where('status', 'failed')->sum('aggregate'),
            retryVolume: (int) $attemptRows->sum('retry_aggregate'),
            live: (int) $deliveryRows->where('kind', 'original')->sum('aggregate'),
            replay: (int) $deliveryRows->where('kind', 'replay')->sum('aggregate'),
        );

        return [
            'retryReplay' => $retryReplay,
            'bridgeFailedAttempts' => $this->bridgeFailedAttemptsCount($constraints, $window),
        ];
    }

    /**
     * `deliveries.status = 'succeeded'` count where the delivery took two or
     * more attempts (AC19(a)) — `EXISTS (delivery_attempts WHERE delivery_id
     * = deliveries.id AND attempt_number >= 2)`, served by
     * `UNIQUE(delivery_id, attempt_number)`.
     *
     * @param  array<string, int>  $constraints
     */
    private function eventualSuccessCount(array $constraints, AnalyticsWindow $window): int
    {
        $query = DB::table('deliveries')
            ->where('status', 'succeeded')
            ->whereBetween('updated_at', [$this->windowStart($window), CarbonImmutable::now()])
            ->whereExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('delivery_attempts')
                    ->whereColumn('delivery_attempts.delivery_id', 'deliveries.id')
                    ->where('delivery_attempts.attempt_number', '>=', 2);
            });

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /**
     * Failed attempts belonging to the window's succeeded deliveries (the
     * Screen 1/2 bridge sentence, e.g. "14 attempts failed before these
     * deliveries succeeded") — the one deliberate two-table join in this
     * service (plan-11 § Architecture A). Descriptive only, never converted
     * back into either unit's rate.
     *
     * @param  array<string, int>  $constraints
     */
    private function bridgeFailedAttemptsCount(array $constraints, AnalyticsWindow $window): int
    {
        $query = DB::table('delivery_attempts')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_attempts.delivery_id')
            ->where('delivery_attempts.status', 'failed')
            ->where('deliveries.status', 'succeeded')
            ->whereBetween('deliveries.updated_at', [$this->windowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where("deliveries.{$column}", $value);
        }

        return $query->count();
    }

    /**
     * One `UnitFigure` from `deliveries`' grouped `(status, kind)` rows.
     * `rate` is `null` when `total === 0` (Amendment A(i)) — never `0`.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    private function deliveryUnitFigure(Collection $rows): UnitFigure
    {
        $succeeded = (int) $rows->where('status', 'succeeded')->sum('aggregate');
        $failed = (int) $rows->where('status', 'failed')->sum('aggregate');
        $total = $succeeded + $failed;

        return new UnitFigure(
            succeeded: $succeeded,
            failed: $failed,
            total: $total,
            rate: $total === 0 ? null : $succeeded / $total,
        );
    }

    /**
     * One `UnitFigure` from `delivery_attempts`' grouped `status` rows.
     * `rate` is `null` when `total === 0` (Amendment A(i)) — never `0`.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    private function attemptUnitFigure(Collection $rows): UnitFigure
    {
        $succeeded = (int) $rows->where('status', 'succeeded')->sum('aggregate');
        $failed = (int) $rows->where('status', 'failed')->sum('aggregate');
        $total = $succeeded + $failed;

        return new UnitFigure(
            succeeded: $succeeded,
            failed: $failed,
            total: $total,
            rate: $total === 0 ? null : $succeeded / $total,
        );
    }

    /**
     * `deliveries`' single grouped aggregate for one grain, one window:
     * `(status, kind) => count`. Feeds both the delivery-level `UnitFigure`
     * and, with no extra query, `RetryReplayFigures`' terminal-failure and
     * live-vs-replay counts (plan-11 § Architecture B).
     *
     * @param  array<string, int>  $constraints
     * @return Collection<int, stdClass>
     */
    private function deliveryAggregates(array $constraints, AnalyticsWindow $window): Collection
    {
        $query = DB::table('deliveries')
            ->select('status', 'kind', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('status', ['succeeded', 'failed'])
            ->whereBetween('updated_at', [$this->windowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query->groupBy('status', 'kind')->get();
    }

    /**
     * `delivery_attempts`' single grouped aggregate for one grain, one
     * window: `status => (count, retry count)`. Feeds both the attempt-level
     * `UnitFigure` and, with no extra query, `RetryReplayFigures`' retry
     * volume (`SUM(attempt_number > 1)`, AC19(c); plan-11 § Architecture B).
     * `pending`/`retrying`/`dispatched` rows are excluded by the `status`
     * predicate, never counted as failures (AC13).
     *
     * @param  array<string, int>  $constraints
     * @return Collection<int, stdClass>
     */
    private function attemptAggregates(array $constraints, AnalyticsWindow $window): Collection
    {
        $query = DB::table('delivery_attempts')
            ->select(
                'status',
                DB::raw('COUNT(*) as aggregate'),
                DB::raw('SUM(CASE WHEN attempt_number > 1 THEN 1 ELSE 0 END) as retry_aggregate'),
            )
            ->whereIn('status', ['succeeded', 'failed'])
            ->whereBetween('updated_at', [$this->windowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query->groupBy('status')->get();
    }

    /**
     * The start of the window (inclusive), anchored on `updated_at` (Technical
     * ruling 1) — never `created_at`, never `received_at`.
     */
    private function windowStart(AnalyticsWindow $window): CarbonImmutable
    {
        return CarbonImmutable::now()->sub($window->interval());
    }
}
