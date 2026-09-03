<script setup lang="ts">
/**
 * The trend chart (AC16; design-11 Flow C step 3, § Accessibility; plan-11
 * Implementation Notes 14–15, 21–23). Two lines — delivery success solid,
 * attempt success dashed — fed the same `SeriesPoint[]` the sibling "View as
 * table" fallback (T17/T19) already renders from. Buckets hourly on the
 * 24-hour window and daily on 7d/30d (Amendment B(i)) — `props.bucket`
 * (`StatisticsPanel.bucket`) drives axis and summary wording only, never a
 * drill-through decision (§ *Technical rulings* 13; T32).
 *
 * `Chart` construction and teardown both happen inside `Vue3ChartJs`'s own
 * lifecycle hooks — nothing here calls `new Chart` directly, and nothing
 * chart-related runs at module scope, so this component stays renderable if
 * an Inertia SSR entrypoint is ever added (binding constraint 3).
 *
 * The canvas is `aria-hidden`: the surrounding `<figure>` carries a short
 * `aria-label` summary, and the accessible table beside this component (T28)
 * is the only representation a screen reader or keyboard user needs — the
 * canvas itself carries no `tabindex` and no click handler (design-11 Flow C
 * step 3, plan Technical ruling 10).
 */
import Vue3ChartJs from '@j-t-mcc/vue3-chartjs';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed, ref, watch } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import {
    ATTEMPT_SUCCESS_LABEL,
    DELIVERY_SUCCESS_LABEL,
    formatBucketAxisTick,
    trendChartAriaLabel,
} from '@/data/analyticsLabels';
import { resolveChartSeriesColours } from '@/lib/chartTokens';
import type {
    AnalyticsWindowValue,
    SeriesBucketValue,
    SeriesPoint,
} from '@/types/analytics';

const props = defineProps<{
    series: SeriesPoint[];
    window: AnalyticsWindowValue;
    bucket: SeriesBucketValue;
}>();

const ariaLabel = computed(() =>
    trendChartAriaLabel(props.window, props.bucket),
);

/**
 * The bucket key (`bucketStart`'s date portion) an hourly tick's calendar
 * day differs on from the point before it — the day-boundary crossing the
 * axis is obliged to date-qualify (design-11 Screen 1 mockup axis note).
 * The first point is never date-qualified (design-11 "Call 3" — permitted,
 * not required, additive only; not adopted here).
 */
function isDayBoundaryCrossing(index: number): boolean {
    if (index === 0) {
        return false;
    }

    const current = props.series[index].bucketStart.slice(0, 10);
    const previous = props.series[index - 1].bucketStart.slice(0, 10);

    return current !== previous;
}

// A zero-traffic day's `rate` is `null` (Amendment A(i)) — plotted as a gap,
// never as a literal 0%, which would read as total failure. `spanGaps` stays
// at Chart.js's default `false` so the break is visible rather than bridged.
function ratePercent(
    figure: SeriesPoint['delivery' | 'attempt'],
): number | null {
    return figure.rate === null ? null : Math.round(figure.rate * 100);
}

function buildChartData(): ChartData<'line'> {
    const colours = resolveChartSeriesColours();

    return {
        labels: props.series.map((point, index) =>
            formatBucketAxisTick(
                point.bucketStart,
                props.bucket,
                props.bucket === 'hour' && isDayBoundaryCrossing(index),
            ),
        ),
        datasets: [
            {
                label: DELIVERY_SUCCESS_LABEL,
                data: props.series.map((point) => ratePercent(point.delivery)),
                borderColor: colours.delivery,
                backgroundColor: colours.delivery,
                borderDash: [],
                pointRadius: 2,
                pointHoverRadius: 4,
                tension: 0.25,
            },
            {
                label: ATTEMPT_SUCCESS_LABEL,
                data: props.series.map((point) => ratePercent(point.attempt)),
                borderColor: colours.attempt,
                backgroundColor: colours.attempt,
                borderDash: [6, 4],
                pointRadius: 2,
                pointHoverRadius: 4,
                tension: 0.25,
            },
        ],
    };
}

const chartData = ref(buildChartData());

// The legend is rendered as HTML beside the canvas rather than by Chart.js
// (`plugins.legend.display` is false below), so these colours are needed
// outside `buildChartData()` too. Re-resolved by the same watch, for the same
// reason the datasets are: a cached palette survives a theme change.
const colours = ref(resolveChartSeriesColours());

/**
 * Legend entries, mirroring the two datasets' own stroke styles — solid for
 * delivery-grain, dashed for attempt-grain — so the swatch reads as the line
 * it stands for rather than as a colour chip alone. That matters here because
 * the two series are distinguished by dash pattern as well as by hue, and hue
 * alone would not survive a monochrome print or colour-blind viewing.
 */
const legendEntries = computed(() => [
    {
        label: DELIVERY_SUCCESS_LABEL,
        colour: colours.value.delivery,
        dash: undefined,
    },
    {
        label: ATTEMPT_SUCCESS_LABEL,
        colour: colours.value.attempt,
        dash: '5 3',
    },
]);

const chartOptions: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: { intersect: false, mode: 'index' },
    scales: {
        y: {
            min: 0,
            max: 100,
            ticks: { callback: (value) => `${value}%` },
        },
    },
    plugins: {
        // Chart.js's own legend is off: it sizes its labels from the canvas
        // font rather than the page's, sits hard against the plot area, and
        // centres and stacks its entries on a narrow viewport. The HTML
        // legend in the template below inherits the app's type scale, keeps
        // its own spacing, and wraps inline instead of stacking centred.
        legend: { display: false },
        tooltip: { enabled: true },
    },
};

const { resolvedAppearance } = useAppearance();

// Re-resolve on data change and on theme change alike (T26) — a chart that
// caches its palette at init keeps the previous theme's colours until torn
// down and rebuilt. Assigning `chartData` is the whole update: the wrapper
// watches its `data` prop and applies the new value itself, so nothing here
// holds a component ref or calls `update()`.
watch([() => props.series, () => props.bucket, resolvedAppearance], () => {
    chartData.value = buildChartData();
    colours.value = resolveChartSeriesColours();
});
</script>

<template>
    <figure class="w-full" :aria-label="ariaLabel">
        <!--
            Visual legend only, so it is hidden from assistive technology for
            the same reason the canvas is: the accessible table beside this
            component already names both series. Left-aligned and wrapping,
            rather than centred and stacked as Chart.js's own legend renders
            on a narrow viewport.
        -->
        <ul
            aria-hidden="true"
            class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1"
        >
            <li
                v-for="entry in legendEntries"
                :key="entry.label"
                class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <svg width="14" height="2" viewBox="0 0 14 2" class="shrink-0">
                    <line
                        x1="0"
                        y1="1"
                        x2="14"
                        y2="1"
                        :stroke="entry.colour"
                        stroke-width="2"
                        :stroke-dasharray="entry.dash"
                    />
                </svg>
                {{ entry.label }}
            </li>
        </ul>
        <div class="h-64 w-full">
            <Vue3ChartJs
                type="line"
                :data="chartData"
                :options="chartOptions"
                aria-hidden="true"
                class="h-full w-full"
            />
        </div>
    </figure>
</template>
