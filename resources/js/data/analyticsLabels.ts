/**
 * Single source of every figure label and unit for item #11's Dashboard
 * (T14–T17) and Proxy Show (T19–T20) — design-11 correction C4. No
 * free-standing string literal for any of these labels may appear in a
 * `.vue` file; every consuming component imports from here instead, so
 * wording cannot drift between the two surfaces (plan-11 Implementation
 * Note 13, R6).
 *
 * Values MUST stay in sync with the PHP `AnalyticsWindow` enum
 * (`app/Enums/AnalyticsWindow.php`) for the window labels — the backend is
 * authoritative there; the figure/unit labels below have no backend
 * counterpart to drift from since they are pure display text.
 */

import type { AnalyticsWindowValue } from '@/types/analytics';

/** The two-tier headline card's `dt` labels (design-11 Screen 1(b)). */
export const DELIVERY_SUCCESS_LABEL = 'Delivery success';
export const ATTEMPT_SUCCESS_LABEL = 'Attempt success — destination health';

/**
 * The Proxies table's (T15) and Destinations table's (T20) column headers —
 * shorter than the headline's `ATTEMPT_SUCCESS_LABEL` because the column's
 * own header ("Attempt success") pairs with the "Delivery success" column
 * beside it; a table cell doesn't repeat the headline's "— destination
 * health" qualifier.
 */
export const DELIVERY_SUCCESS_COLUMN_LABEL = 'Delivery success';
export const ATTEMPT_SUCCESS_COLUMN_LABEL = 'Attempt success';

/** The Proxies table's failure-count column (design-11 Screen 1, Flow B). */
export const TERMINAL_FAILURES_COLUMN_LABEL = 'Terminal failures (deliveries)';

/** The Destinations table's latency column (design-11 Screen 3). */
export const LATENCY_AVERAGE_COLUMN_LABEL = 'Latency (avg)';

/** The four "Retry & replay" stat tile labels (AC19; design-11 Screen 1/2). */
export const EVENTUAL_SUCCESS_LABEL = 'Eventual success (deliveries)';
export const TERMINAL_FAILURE_LABEL = 'Terminal failure (deliveries)';
export const RETRY_VOLUME_LABEL = 'Retry volume (attempts)';
export const LIVE_VS_REPLAY_LABEL = 'Live vs replay (deliveries)';

/** The "Latency" card's `dt` labels and fixed caption (AC12, AC20). */
export const LATENCY_AVERAGE_LABEL = 'Average';
export const LATENCY_P95_LABEL = '95th percentile';
export const LATENCY_CAPTION = 'Excludes time spent waiting in the queue.';

/** Shared no-data text — a rate (Amendment A(i)) vs. a measure (AC20). */
export const RATE_NO_DATA_LABEL = 'No deliveries yet';
export const LATENCY_NO_DATA_LABEL = 'No data';

/**
 * The Trend card's zero-deliveries-in-window message (design-11 Screen 1
 * state list) — the plotted series are rates, so a flat 0% line/table would
 * read as total failure, which is false; this stands in for the whole trend
 * representation instead.
 */
export const TREND_NO_DATA_LABEL = 'No data for this period.';

/**
 * Window labels (design correction C2's "Last {window}" subtitles), kept in
 * lockstep with `AnalyticsWindow::label()` — `'24h'` → `'24 hours'`, etc.
 */
const WINDOW_LABELS: Record<AnalyticsWindowValue, string> = {
    '24h': '24 hours',
    '7d': '7 days',
    '30d': '30 days',
};

/** The display label for a window value (e.g. `'30d'` → `'30 days'`). */
export function windowLabel(window: AnalyticsWindowValue): string {
    return WINDOW_LABELS[window];
}

/** The card-subtitle text design correction C2 requires — `"Last 30 days"`. */
export function lastWindowSubtitle(window: AnalyticsWindowValue): string {
    return `Last ${windowLabel(window)}`;
}

/**
 * A rate (0–1) formatted as a whole-percentage string, or
 * {@link RATE_NO_DATA_LABEL} when the rate is `null` (a zero-denominator
 * window, Amendment A(i)) — never `0%`.
 */
export function formatRate(rate: number | null): string {
    if (rate === null) {
        return RATE_NO_DATA_LABEL;
    }

    return `${Math.round(rate * 100)}%`;
}

/**
 * A `UnitFigure`-shaped rate rendered compactly as `"96% (42/42)"` (design-11
 * Screen 3's convention for a breakdown-table cell), or
 * {@link RATE_NO_DATA_LABEL} alone when the rate is `null` — never `0% (0/0)`.
 */
export function compactRateText(figure: {
    rate: number | null;
    succeeded: number;
    total: number;
}): string {
    if (figure.rate === null) {
        return RATE_NO_DATA_LABEL;
    }

    return `${formatRate(figure.rate)} (${figure.succeeded}/${figure.total})`;
}

/** The delivery headline/table caption — `"42 of 42 delivered · last 30 days"`. */
export function deliveryCaption(
    succeeded: number,
    total: number,
    window: AnalyticsWindowValue,
): string {
    return `${succeeded} of ${total} delivered · last ${windowLabel(window)}`;
}

/** The attempt headline caption — `"28 of 42 attempts succeeded · last 30 days"`. */
export function attemptCaption(
    succeeded: number,
    total: number,
    window: AnalyticsWindowValue,
): string {
    return `${succeeded} of ${total} attempts succeeded · last ${windowLabel(window)}`;
}

/**
 * The bridge sentence naming the gap between the two units (AC14(d)) —
 * descriptive only, never arithmetic. `null` when there is nothing to
 * bridge (`bridgeFailedAttempts === 0`), so the caller knows to omit it.
 */
export function bridgeSentence(bridgeFailedAttempts: number): string | null {
    if (bridgeFailedAttempts <= 0) {
        return null;
    }

    const noun = bridgeFailedAttempts === 1 ? 'attempt' : 'attempts';

    return `${bridgeFailedAttempts} ${noun} failed before these deliveries succeeded — see Retry & replay below.`;
}

/** The live-vs-replay tile's two-labelled-numbers text — `"42 live · 3 replay"`. */
export function liveVsReplayText(live: number, replay: number): string {
    return `${live} live · ${replay} replay`;
}

/**
 * Proxy Show's Analytics-card zero-traffic message (design-11 Screen 2,
 * Flow C step 6) — the entire card collapses to this single message when
 * the current proxy has no traffic in the window.
 */
export function zeroProxyTrafficMessage(window: AnalyticsWindowValue): string {
    return `No deliveries to this proxy in the last ${windowLabel(window)}. Figures appear once it receives and delivers a webhook.`;
}

/**
 * An ISO `Y-m-d` series date, formatted for display. Shared by the Dashboard
 * and Proxy Show trend tables' Date column (T17/T19) and, on Proxy Show only,
 * the day-narrowed Events list Window chip a per-day drill-through link
 * lands on (T23/T24, Revision A, `Q-11-04`; plan Implementation Note 20) —
 * one formatter, so the two surfaces cannot disagree about how a day is
 * written.
 */
export function formatSeriesDate(isoDate: string): string {
    return new Date(`${isoDate}T00:00:00`).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/**
 * A latency duration in whole milliseconds, formatted as `"340 ms"` below
 * one second and `"1.2 s"` at or above it, or {@link LATENCY_NO_DATA_LABEL}
 * when `null` (`sampleCount === 0`) — never `0 ms`.
 */
export function formatLatencyMs(ms: number | null): string {
    if (ms === null) {
        return LATENCY_NO_DATA_LABEL;
    }

    if (ms < 1000) {
        return `${ms} ms`;
    }

    return `${(ms / 1000).toFixed(1)} s`;
}
