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
 * `Chart` construction happens exclusively inside `Vue3ChartJs`'s own
 * `onMounted` hook (the wrapper's `dist/vue3-chartjs.es.js`: `w(() => l())`,
 * where `l()` calls `new Chart(...)` the first time and `chart.update()`
 * thereafter) — nothing here calls `new Chart` directly, and nothing chart-
 * related runs at module scope, so this component stays renderable if an
 * Inertia SSR entrypoint is ever added (binding constraint 3). The wrapper
 * exposes no automatic unmount cleanup of its own, so `onUnmounted` below
 * calls its exposed `destroy()` explicitly.
 *
 * The canvas is `aria-hidden`: the surrounding `<figure>` carries a short
 * `aria-label` summary, and the accessible table beside this component (T28)
 * is the only representation a screen reader or keyboard user needs — the
 * canvas itself carries no `tabindex` and no click handler (design-11 Flow C
 * step 3, plan Technical ruling 10).
 */
import Vue3ChartJs from '@j-t-mcc/vue3-chartjs';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed, onUnmounted, ref, useTemplateRef, watch } from 'vue';
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
        legend: { display: true },
        tooltip: { enabled: true },
    },
};

const chartRef = useTemplateRef<InstanceType<typeof Vue3ChartJs>>('chartRef');
const { resolvedAppearance } = useAppearance();

// Re-resolve on data change and on theme change alike (T26) — a chart that
// caches its palette at init keeps the previous theme's colours until torn
// down and rebuilt.
//
// Deliberately bypasses the wrapper's own exposed `update()`: its internal
// `props` snapshot (`vue3-chartjs.es.js`'s `props: { ...f }`) is captured
// once in `setup()` and never re-read, so calling it after a `:data` prop
// change silently reapplies the *original* mount-time data — confirmed
// empirically (theme toggled live, canvas pixel colour never changed).
// Writing straight to the exposed `chartJSState.chart` — the real `Chart`
// instance — and calling its own `update()` is the actual Chart.js API this
// wrapper is a thin layer over, and is unaffected by that snapshot bug.
watch([() => props.series, () => props.bucket, resolvedAppearance], () => {
    chartData.value = buildChartData();

    const chart = chartRef.value?.chartJSState.chart;

    if (chart) {
        chart.data = chartData.value;
        chart.update();
    }
});

onUnmounted(() => {
    chartRef.value?.destroy();
});
</script>

<template>
    <figure class="w-full" :aria-label="ariaLabel">
        <div class="h-64 w-full">
            <Vue3ChartJs
                ref="chartRef"
                type="line"
                :data="chartData"
                :options="chartOptions"
                aria-hidden="true"
                class="h-full w-full"
            />
        </div>
    </figure>
</template>
