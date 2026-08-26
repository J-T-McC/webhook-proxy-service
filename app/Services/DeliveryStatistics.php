<?php

namespace App\Services;

use App\Data\Analytics\UnitFigure;
use App\Enums\AnalyticsWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
            'delivery' => $this->unitFigure('deliveries', $constraints, $window),
            'attempt' => $this->unitFigure('delivery_attempts', $constraints, $window),
        ];
    }

    /**
     * One table's success/failure `UnitFigure` for one grain, over one
     * window. `rate` is `null` when `total === 0` (Amendment A(i)) — never
     * `0`.
     *
     * @param  array<string, int>  $constraints
     */
    private function unitFigure(string $table, array $constraints, AnalyticsWindow $window): UnitFigure
    {
        $counts = $this->statusCounts($table, $constraints, $window);

        $succeeded = $counts['succeeded'] ?? 0;
        $failed = $counts['failed'] ?? 0;
        $total = $succeeded + $failed;

        return new UnitFigure(
            succeeded: $succeeded,
            failed: $failed,
            total: $total,
            rate: $total === 0 ? null : $succeeded / $total,
        );
    }

    /**
     * `succeeded`/`failed` row counts for one table, one grain, one window —
     * `pending`/`retrying`/`dispatched` rows are excluded by the `status`
     * predicate, never counted as failures (AC13).
     *
     * @param  array<string, int>  $constraints
     * @return array<string, int>
     */
    private function statusCounts(string $table, array $constraints, AnalyticsWindow $window): array
    {
        $query = DB::table($table)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('status', ['succeeded', 'failed'])
            ->whereBetween('updated_at', [$this->windowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        /** @var array<string, int> $counts */
        $counts = $query->groupBy('status')->pluck('aggregate', 'status')
            ->map(fn (mixed $count) => (int) $count)
            ->all();

        return $counts;
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
