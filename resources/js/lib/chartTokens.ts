// Colour resolution for the analytics trend chart.
//
// Reuses the browser round-trip technique already proven in
// `resources/js/components/welcome/canvasKit.ts` (PR #12): CSS custom
// properties are authored as `hsl(...)` in `app.css`, but a production
// minifier can rewrite them to hex, and `getComputedStyle` returns whatever
// format survived — so a hand-rolled `hsl()` pattern-matcher works against the
// dev server and silently fails against a real build. Assigning the raw
// token to a 2D canvas context's `fillStyle` and reading the value back
// normalises whatever CSS colour format the browser understands, which is
// also exactly the string shape Chart.js expects for a `borderColor`.
//
// Kept as a local copy rather than importing across from `canvasKit.ts`: the
// welcome illustrations and this chart are unrelated features, and the
// normaliser here is a handful of lines with no per-frame caching need (a
// chart only re-resolves on mount and on theme change, not every animation
// frame), so extracting a shared `lib/` module would add indirection without
// removing meaningful duplication.

let colorProbe: CanvasRenderingContext2D | null = null;

function resolveColorToken(token: string): string {
    if (typeof document === 'undefined') {
        return token;
    }

    if (!colorProbe) {
        colorProbe = document.createElement('canvas').getContext('2d');
    }

    if (!colorProbe) {
        return token;
    }

    colorProbe.fillStyle = '#000000';
    colorProbe.fillStyle = token;

    return colorProbe.fillStyle;
}

function readColorToken(name: string): string {
    return getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();
}

export interface ChartSeriesColours {
    delivery: string;
    attempt: string;
}

/**
 * Resolves the two trend-chart series colours (`--chart-1` for delivery
 * success, `--chart-2` for attempt success) from their CSS custom
 * properties, normalised through the browser rather than pattern-matched.
 *
 * Call again whenever the theme changes (`useAppearance`'s
 * `resolvedAppearance`) — a chart that caches this at init keeps showing the
 * previous theme's colours until it is torn down and rebuilt.
 */
export function resolveChartSeriesColours(): ChartSeriesColours {
    return {
        delivery: resolveColorToken(readColorToken('--chart-1')),
        attempt: resolveColorToken(readColorToken('--chart-2')),
    };
}
