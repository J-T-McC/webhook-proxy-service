<?php

namespace App\Services;

use App\Data\Analytics\DestinationBreakdownRow;
use App\Data\Analytics\LatencyFigure;
use App\Data\Analytics\ProxyBreakdownRow;
use App\Data\Analytics\RetryReplayFigures;
use App\Data\Analytics\SeriesPoint;
use App\Data\Analytics\StatisticsPanel;
use App\Data\Analytics\UnitFigure;
use App\Enums\AnalyticsWindow;
use App\Models\Destination;
use App\Models\Proxy;
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
     * The full figure set for the given team, over the given window — every
     * query this method's pieces issue states `team_id` explicitly (audited
     * below); none relies on `ApplyTeamScope` (which does not scope
     * `Delivery` at all) or calls `withoutGlobalScope(TeamScope::class)`
     * (AC23; Technical ruling 7). Reads through the exact same per-grain
     * methods `unitFiguresForTeam()`/`retryReplayForTeam()`/
     * `latencyForTeam()`/`seriesForTeam()` a caller could call directly, so
     * a figure can never disagree between this panel and a direct call.
     *
     * **Explicit `team_id` audit (Technical ruling 7).** Every query built by
     * `unitFigures()`, `retryReplayAndBridge()`
     * (`eventualSuccessCount()`/`bridgeFailedAttemptsCount()` included),
     * `latencyFigure()`/`percentileDurationMs()`, and `series()`
     * (`dailyAggregates()`) takes its grain constraint from the
     * `$constraints` array this class's public `*ForTeam()`/`*ForProxy()`
     * methods build (`['team_id' => $teamId]` / `['proxy_id' => $proxyId]`)
     * and applies it via a plain `where($column, $value)` loop — there is no
     * code path in this class that omits it or substitutes a global scope.
     * A Simple-mode proxy's figures are counted exactly like an Enhanced
     * one's (AC25): no query here reads `proxies.mode`. `deliveries`/
     * `delivery_attempts` rows exist identically regardless of a proxy's
     * `processing_mode` (FIFO or Async, AC26): no query here reads
     * `processing_mode` either — both are asserted by absence in
     * `DeliveryStatisticsScopingTest`.
     */
    public function forTeam(int $teamId, AnalyticsWindow $window): StatisticsPanel
    {
        $units = $this->unitFiguresForTeam($teamId, $window);
        $retryReplay = $this->retryReplayForTeam($teamId, $window);
        $latency = $this->latencyForTeam($teamId, $window);
        $series = $this->seriesForTeam($teamId, $window);

        return new StatisticsPanel(
            window: $window,
            delivery: $units['delivery'],
            attempt: $units['attempt'],
            bridgeFailedAttempts: $retryReplay['bridgeFailedAttempts'],
            retryReplay: $retryReplay['retryReplay'],
            latency: $latency,
            series: $series,
            hasTraffic: $units['delivery']->total > 0 || $units['attempt']->total > 0,
        );
    }

    /**
     * The full figure set for the given proxy, over the given window — see
     * `forTeam()`'s doc-block for the `team_id`/mode-independence audit,
     * which applies identically here with `proxy_id` as the grain.
     */
    public function forProxy(Proxy $proxy, AnalyticsWindow $window): StatisticsPanel
    {
        $units = $this->unitFiguresForProxy($proxy->id, $window);
        $retryReplay = $this->retryReplayForProxy($proxy->id, $window);
        $latency = $this->latencyForProxy($proxy->id, $window);
        $series = $this->seriesForProxy($proxy->id, $window);

        return new StatisticsPanel(
            window: $window,
            delivery: $units['delivery'],
            attempt: $units['attempt'],
            bridgeFailedAttempts: $retryReplay['bridgeFailedAttempts'],
            retryReplay: $retryReplay['retryReplay'],
            latency: $latency,
            series: $series,
            hasTraffic: $units['delivery']->total > 0 || $units['attempt']->total > 0,
        );
    }

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
     * Average and 95th-percentile duration for the given team, over the
     * given window (AC12, AC20).
     */
    public function latencyForTeam(int $teamId, AnalyticsWindow $window): LatencyFigure
    {
        return $this->latencyFigure(['team_id' => $teamId], $window, includePercentile: true);
    }

    /**
     * Average and 95th-percentile duration for the given proxy, over the
     * given window (AC12, AC20).
     */
    public function latencyForProxy(int $proxyId, AnalyticsWindow $window): LatencyFigure
    {
        return $this->latencyFigure(['proxy_id' => $proxyId], $window, includePercentile: true);
    }

    /**
     * Average duration for the given destination within the given proxy,
     * over the given window (AC12, AC20). `p95Ms` is always `null` here —
     * no percentile query is issued at destination grain (Amendment A(ii)).
     */
    public function latencyForDestination(int $proxyId, int $destinationId, AnalyticsWindow $window): LatencyFigure
    {
        return $this->latencyFigure(
            ['proxy_id' => $proxyId, 'destination_id' => $destinationId],
            $window,
            includePercentile: false,
        );
    }

    /**
     * The densified daily trend series (AC16) for the given team, over the
     * given window — one `SeriesPoint` per calendar day, including a day
     * with no traffic (zero counts, `rate === null`), never a gap.
     *
     * @return list<SeriesPoint>
     */
    public function seriesForTeam(int $teamId, AnalyticsWindow $window): array
    {
        return $this->series(['team_id' => $teamId], $window);
    }

    /**
     * The densified daily trend series (AC16) for the given proxy, over the
     * given window — one `SeriesPoint` per calendar day, including a day
     * with no traffic (zero counts, `rate === null`), never a gap.
     *
     * @return list<SeriesPoint>
     */
    public function seriesForProxy(int $proxyId, AnalyticsWindow $window): array
    {
        return $this->series(['proxy_id' => $proxyId], $window);
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
     * One row per proxy the team has (AC6, AC15), live and soft-deleted
     * alike — a proxy with no traffic in the window still gets a row (zero
     * figures, "No deliveries yet" is a Vue-side rendering of `rate ===
     * null`, not an absent row). Runs a fixed number of queries regardless
     * of how many proxies the team has (R7): one label-lookup query
     * (`Proxy::withTrashed()`, one of this feature's exactly two
     * `withTrashed()` call sites) plus one grouped aggregate per table — no
     * per-proxy query, ever.
     *
     * @return list<ProxyBreakdownRow>
     */
    public function proxyBreakdown(int $teamId, AnalyticsWindow $window): array
    {
        $proxies = Proxy::withTrashed()->where('team_id', $teamId)->orderBy('name')->get(['id', 'name', 'deleted_at']);

        $deliveryRows = $this->groupedAggregates('deliveries', 'proxy_id', ['team_id' => $teamId], $window);
        $attemptRows = $this->groupedAggregates('delivery_attempts', 'proxy_id', ['team_id' => $teamId], $window);

        return array_values($proxies->map(function (Proxy $proxy) use ($deliveryRows, $attemptRows) {
            $delivery = $this->groupedUnitFigure($deliveryRows, 'proxy_id', $proxy->id);
            $attempt = $this->groupedUnitFigure($attemptRows, 'proxy_id', $proxy->id);

            return new ProxyBreakdownRow(
                id: $proxy->id,
                name: $proxy->name,
                isDeleted: $proxy->trashed(),
                delivery: $delivery,
                attempt: $attempt,
                terminalFailures: $delivery->failed,
                canDrillThrough: ! $proxy->trashed(),
            );
        })->all());
    }

    /**
     * One row per destination in the given proxy's row set (AC6, AC15) — the
     * **union** of the proxy's live destinations and every `destination_id`
     * with activity in the window (a live destination with no traffic reads
     * "No deliveries yet"; a deleted destination with historical traffic
     * still gets a row, labelled Deleted). Runs a fixed number of queries
     * regardless of how many destinations the proxy has (R7): the live-
     * destination lookup, one grouped aggregate per table, and — only when
     * a trashed destination actually has activity in the window — one more
     * `Destination::withTrashed()` lookup (this feature's other
     * `withTrashed()` call site) for that (small, fixed) id set.
     *
     * @return list<DestinationBreakdownRow>
     */
    public function destinationBreakdown(Proxy $proxy, AnalyticsWindow $window): array
    {
        $deliveryRows = $this->groupedAggregates('deliveries', 'destination_id', ['proxy_id' => $proxy->id], $window);
        $attemptRows = $this->groupedAggregates(
            'delivery_attempts',
            'destination_id',
            ['proxy_id' => $proxy->id],
            $window,
            withDuration: true,
        );

        $liveDestinations = Destination::where('proxy_id', $proxy->id)->get(['id', 'url', 'http_method']);
        $liveIds = $liveDestinations->pluck('id')->map(fn (int|string $id) => (int) $id);

        $activeIds = $deliveryRows->pluck('destination_id')
            ->merge($attemptRows->pluck('destination_id'))
            ->map(fn (int|string $id) => (int) $id)
            ->unique();

        $trashedIdsWithActivity = $activeIds->diff($liveIds)->values();

        $destinations = $liveDestinations;

        if ($trashedIdsWithActivity->isNotEmpty()) {
            $trashedDestinations = Destination::withTrashed()
                ->whereIn('id', $trashedIdsWithActivity)
                ->get(['id', 'url', 'http_method', 'deleted_at']);

            $destinations = $destinations->concat($trashedDestinations);
        }

        return array_values($destinations->map(function (Destination $destination) use ($deliveryRows, $attemptRows) {
            $delivery = $this->groupedUnitFigure($deliveryRows, 'destination_id', $destination->id);
            $attempt = $this->groupedUnitFigure($attemptRows, 'destination_id', $destination->id);

            return new DestinationBreakdownRow(
                id: $destination->id,
                url: $destination->url,
                httpMethod: $destination->http_method->value,
                isDeleted: $destination->trashed(),
                delivery: $delivery,
                attempt: $attempt,
                latencyAverageMs: $this->groupedLatencyAverageMs($attemptRows, 'destination_id', $destination->id),
            );
        })->all());
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
     * window: `status => (count, retry count, duration sum, duration
     * count)`. Feeds the attempt-level `UnitFigure`, `RetryReplayFigures`'
     * retry volume (`SUM(attempt_number > 1)`, AC19(c)), and
     * `LatencyFigure`'s average/sample-count, all with no extra query
     * (plan-11 § Architecture B). `pending`/`retrying`/`dispatched` rows are
     * excluded by the `status` predicate, never counted as failures (AC13).
     * `SUM(duration_ms)`/`COUNT(duration_ms)` skip `NULL` rows by ordinary
     * SQL aggregate-function semantics — the same population a
     * `whereNotNull('duration_ms')` filter would select, without a separate
     * clause to keep in sync (AC20; plan Technical ruling 4).
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
                DB::raw('SUM(duration_ms) as duration_sum'),
                DB::raw('COUNT(duration_ms) as duration_count'),
            )
            ->whereIn('status', ['succeeded', 'failed'])
            ->whereBetween('updated_at', [$this->windowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query->groupBy('status')->get();
    }

    /**
     * `LatencyFigure` for one grain: average and sample count come from
     * `attemptAggregates()`'s existing duration columns (no extra query);
     * the 95th percentile, when requested, is one further ordered
     * `LIMIT 1 OFFSET` read (Technical ruling 4) that does not run when
     * `sampleCount === 0`. `averageMs`/`p95Ms` are `null` when `sampleCount
     * === 0` — never `0` (AC12, AC20).
     *
     * @param  array<string, int>  $constraints
     */
    private function latencyFigure(array $constraints, AnalyticsWindow $window, bool $includePercentile): LatencyFigure
    {
        $rows = $this->attemptAggregates($constraints, $window);

        $durationSum = (int) $rows->sum('duration_sum');
        $sampleCount = (int) $rows->sum('duration_count');

        $averageMs = $sampleCount === 0 ? null : (int) round($durationSum / $sampleCount);

        $p95Ms = $includePercentile && $sampleCount > 0
            ? $this->percentileDurationMs($constraints, $window, $sampleCount)
            : null;

        return new LatencyFigure(
            averageMs: $averageMs,
            p95Ms: $p95Ms,
            sampleCount: $sampleCount,
        );
    }

    /**
     * The 95th percentile of resolved attempts' `duration_ms` by nearest-rank
     * (Technical ruling 4): ordinal `CEIL(0.95 × n)`, read with `ORDER BY
     * duration_ms ASC LIMIT 1 OFFSET CEIL(0.95 × n) − 1`, over the same
     * `whereNotNull('duration_ms')`-guarded population `attemptAggregates()`
     * counted. `$sampleCount` is the already-computed resolved-attempt count
     * at this grain — the caller does not run this query when it is `0`.
     *
     * @param  array<string, int>  $constraints
     */
    private function percentileDurationMs(array $constraints, AnalyticsWindow $window, int $sampleCount): ?int
    {
        $ordinal = (int) ceil(0.95 * $sampleCount);

        $query = DB::table('delivery_attempts')
            ->whereIn('status', ['succeeded', 'failed'])
            ->whereNotNull('duration_ms')
            ->whereBetween('updated_at', [$this->windowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        $value = $query->orderBy('duration_ms')
            ->offset($ordinal - 1)
            ->limit(1)
            ->value('duration_ms');

        return $value === null ? null : (int) $value;
    }

    /**
     * The start of the window (inclusive), anchored on `updated_at` (Technical
     * ruling 1) — never `created_at`, never `received_at`.
     */
    private function windowStart(AnalyticsWindow $window): CarbonImmutable
    {
        return CarbonImmutable::now()->sub($window->interval());
    }

    /**
     * The densified daily series for one grain: one `GROUP BY DATE(updated_at),
     * status` query per table, then a PHP pass filling every calendar day in
     * the window — a day with no traffic becomes a real point with zero
     * counts and a `null` rate, never a gap (AC16; plan §§ Windowing,
     * Architecture C). `DATE(updated_at)` is computed in the application
     * timezone: this deployment's MySQL connection reports `SYSTEM` /
     * `UTC`, matching `config('app.timezone')`, so no session `time_zone`
     * override is needed (Technical ruling 9).
     *
     * @param  array<string, int>  $constraints
     * @return list<SeriesPoint>
     */
    private function series(array $constraints, AnalyticsWindow $window): array
    {
        $deliveryRows = $this->dailyAggregates('deliveries', $constraints, $window);
        $attemptRows = $this->dailyAggregates('delivery_attempts', $constraints, $window);

        return array_values(collect($this->daysInWindow($window))
            ->map(fn (string $date) => new SeriesPoint(
                date: $date,
                delivery: $this->dailyUnitFigure($deliveryRows, $date),
                attempt: $this->dailyUnitFigure($attemptRows, $date),
            ))
            ->all());
    }

    /**
     * One table's `(day, status) => count` rows for one grain, one window —
     * the raw, possibly-sparse per-day counts `series()` densifies.
     *
     * @param  array<string, int>  $constraints
     * @return Collection<int, stdClass>
     */
    private function dailyAggregates(string $table, array $constraints, AnalyticsWindow $window): Collection
    {
        $query = DB::table($table)
            ->select(DB::raw('DATE(updated_at) as day'), 'status', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('status', ['succeeded', 'failed'])
            ->whereBetween('updated_at', [$this->seriesWindowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query->groupBy('day', 'status')->get();
    }

    /**
     * One day's `UnitFigure` from a table's raw `dailyAggregates()` rows.
     * `rate` is `null` when `total === 0` (Amendment A(i)) — never `0`, and a
     * day absent from `$rows` entirely (no traffic that day) still produces
     * a zeroed figure here rather than being skipped.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    private function dailyUnitFigure(Collection $rows, string $date): UnitFigure
    {
        $dayRows = $rows->filter(fn (stdClass $row) => (string) $row->day === $date);

        $succeeded = (int) $dayRows->where('status', 'succeeded')->sum('aggregate');
        $failed = (int) $dayRows->where('status', 'failed')->sum('aggregate');
        $total = $succeeded + $failed;

        return new UnitFigure(
            succeeded: $succeeded,
            failed: $failed,
            total: $total,
            rate: $total === 0 ? null : $succeeded / $total,
        );
    }

    /**
     * Every calendar day (`Y-m-d`) in the window, oldest first — exactly
     * `$window->days()` entries, densifying the trend series regardless of
     * how sparse the underlying `GROUP BY` result is.
     *
     * @return list<string>
     */
    private function daysInWindow(AnalyticsWindow $window): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        return array_values(collect(range($window->days() - 1, 0))
            ->map(fn (int $daysAgo) => $today->subDays($daysAgo)->format('Y-m-d'))
            ->all());
    }

    /**
     * The start of the series window (inclusive) — calendar-day aligned so
     * the series densifies to exactly `$window->days()` points, unlike
     * `windowStart()`'s precise rolling cutoff used by every other figure.
     */
    private function seriesWindowStart(AnalyticsWindow $window): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfDay()->subDays($window->days() - 1);
    }

    /**
     * One table's `($groupColumn, status) => (count[, duration sum, duration
     * count])` rows across a whole set (e.g. every proxy on a team, every
     * destination on a proxy) — one query regardless of how many distinct
     * `$groupColumn` values exist, feeding `groupedUnitFigure()`/
     * `groupedLatencyAverageMs()` per row of a breakdown table (R7).
     *
     * @param  array<string, int>  $constraints
     * @return Collection<int, stdClass>
     */
    private function groupedAggregates(
        string $table,
        string $groupColumn,
        array $constraints,
        AnalyticsWindow $window,
        bool $withDuration = false,
    ): Collection {
        $select = [$groupColumn, 'status', DB::raw('COUNT(*) as aggregate')];

        if ($withDuration) {
            $select[] = DB::raw('SUM(duration_ms) as duration_sum');
            $select[] = DB::raw('COUNT(duration_ms) as duration_count');
        }

        $query = DB::table($table)
            ->select($select)
            ->whereIn('status', ['succeeded', 'failed'])
            ->whereBetween('updated_at', [$this->windowStart($window), CarbonImmutable::now()]);

        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query->groupBy($groupColumn, 'status')->get();
    }

    /**
     * One id's `UnitFigure` from a `groupedAggregates()` result. `rate` is
     * `null` when `total === 0` (Amendment A(i)) — never `0`, including for
     * an id with no rows at all in `$rows` (a proxy/destination with no
     * traffic in the window).
     *
     * @param  Collection<int, stdClass>  $rows
     */
    private function groupedUnitFigure(Collection $rows, string $groupColumn, int $id): UnitFigure
    {
        $idRows = $rows->filter(fn (stdClass $row) => (int) $row->{$groupColumn} === $id);

        $succeeded = (int) $idRows->where('status', 'succeeded')->sum('aggregate');
        $failed = (int) $idRows->where('status', 'failed')->sum('aggregate');
        $total = $succeeded + $failed;

        return new UnitFigure(
            succeeded: $succeeded,
            failed: $failed,
            total: $total,
            rate: $total === 0 ? null : $succeeded / $total,
        );
    }

    /**
     * One id's average duration from a `groupedAggregates(..., withDuration:
     * true)` result — `null` when that id has no resolved attempts with a
     * recorded `duration_ms` in the window (AC20).
     *
     * @param  Collection<int, stdClass>  $rows
     */
    private function groupedLatencyAverageMs(Collection $rows, string $groupColumn, int $id): ?int
    {
        $idRows = $rows->filter(fn (stdClass $row) => (int) $row->{$groupColumn} === $id);

        $durationSum = (int) $idRows->sum('duration_sum');
        $sampleCount = (int) $idRows->sum('duration_count');

        return $sampleCount === 0 ? null : (int) round($durationSum / $sampleCount);
    }
}
