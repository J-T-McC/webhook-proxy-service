/**
 * TypeScript mirror of the `App\Data\Analytics\*` DTOs (plan-11 § API "Prop
 * shapes"). Field names are camelCase, matching the PHP readonly classes'
 * public properties exactly as they serialize over the wire — Inertia's
 * `json_encode` of a public-property object, no `Http\Resources\` snake_case
 * translation involved (see each DTO's own doc-block in `app/Data/Analytics`).
 */

/**
 * The three windows every analytics figure can be read over. Mirrors
 * `App\Enums\AnalyticsWindow`'s backing values exactly — a PHP backed enum
 * serializes to its scalar backing value, not an object.
 */
export type AnalyticsWindowValue = '24h' | '7d' | '30d';

/**
 * A success/failure figure for one unit (delivery- or attempt-grain) at one
 * grain (team / proxy / destination). `rate` is `null` when `total === 0`
 * (Amendment A(i)) — never `0`.
 */
export interface UnitFigure {
    succeeded: number;
    failed: number;
    total: number;
    rate: number | null;
}

/** The four AC19 retry/replay figures — all plain counts, always rendered. */
export interface RetryReplayFigures {
    eventualSuccess: number;
    terminalFailure: number;
    retryVolume: number;
    live: number;
    replay: number;
}

/**
 * Average and 95th-percentile duration over resolved attempts (AC12, AC20).
 * Both `null` when `sampleCount === 0`; `p95Ms` is also `null` at
 * destination grain by design (Amendment A(ii)).
 */
export interface LatencyFigure {
    averageMs: number | null;
    p95Ms: number | null;
    sampleCount: number;
}

/**
 * One densified day in the daily trend series (AC16) — a no-traffic day is a
 * real point with zero counts and a `null` rate, never a gap.
 */
export interface SeriesPoint {
    date: string;
    delivery: UnitFigure;
    attempt: UnitFigure;
}

/** The full figure set for one grain (team or proxy) over one window. */
export interface StatisticsPanel {
    window: AnalyticsWindowValue;
    delivery: UnitFigure;
    attempt: UnitFigure;
    bridgeFailedAttempts: number;
    retryReplay: RetryReplayFigures;
    latency: LatencyFigure;
    series: SeriesPoint[];
    hasTraffic: boolean;
}

/**
 * One row of the Dashboard's per-proxy breakdown table (AC6, AC15).
 * `canDrillThrough` is `false` iff the proxy is soft-deleted — a fact about
 * the route, not a permission.
 */
export interface ProxyBreakdownRow {
    id: number;
    name: string;
    isDeleted: boolean;
    delivery: UnitFigure;
    attempt: UnitFigure;
    terminalFailures: number;
    canDrillThrough: boolean;
}

/** One row of the proxy Show page's per-destination breakdown table (AC6, AC15). */
export interface DestinationBreakdownRow {
    id: number;
    url: string;
    httpMethod: string;
    isDeleted: boolean;
    delivery: UnitFigure;
    attempt: UnitFigure;
    latencyAverageMs: number | null;
}

/**
 * The Events list's active-filter chip descriptors (T21+; Revision A/T23,
 * `Q-11-04`). `destination`/`outcome`/`day` are `null` when the
 * corresponding query parameter could not be resolved — a chip never claims
 * a narrowing the query did not apply. A resolved `day` (ISO `Y-m-d`) is
 * **not** a fourth chip — it renders as the value of the existing Window
 * chip (design-11 Screen 4; plan Technical ruling 10).
 */
export interface EventListFilters {
    window: AnalyticsWindowValue;
    destination: {
        id: number;
        url: string;
        httpMethod: string;
        isDeleted: boolean;
    } | null;
    outcome: {
        unit: string;
        label: string;
    } | null;
    day: string | null;
}
