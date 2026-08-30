<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import TrendChart from '@/components/TrendChart.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    ATTEMPT_SUCCESS_COLUMN_LABEL,
    DELIVERY_SUCCESS_COLUMN_LABEL,
    TREND_NO_DATA_LABEL,
    compactRateText,
    formatBucketPeriod,
    trendTableFirstColumnHeader,
} from '@/data/analyticsLabels';
import type { StatisticsPanel } from '@/types/analytics';
import type { RouteDefinition } from '@/wayfinder';

const props = defineProps<{
    statistics: StatisticsPanel;
    /**
     * Builds a per-day, per-unit drill-through target for one table row
     * (design-11 Flow E entry-point table). Omitted by a caller whose grain
     * has nothing to drill through to (the Dashboard, whose rows span every
     * proxy), where the cells render as plain text.
     */
    dayHref?:
        | ((
              date: string,
              unit: 'delivery' | 'attempt',
          ) => RouteDefinition<'get'>)
        | null;
}>();
</script>

<template>
    <Card class="gap-4 p-6">
        <h2 class="text-base font-semibold">Trend</h2>
        <p
            v-if="!props.statistics.hasTraffic"
            class="text-sm text-muted-foreground"
        >
            {{ TREND_NO_DATA_LABEL }}
        </p>
        <template v-else>
            <TrendChart
                :series="props.statistics.series"
                :window="props.statistics.window"
                :bucket="props.statistics.bucket"
            />
            <!--
                The chart's "View as table" fallback is collapsed by
                default now that the chart above it is the primary
                representation (design-11 § Interactions).
            -->
            <Collapsible>
                <CollapsibleTrigger as-child>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-auto w-fit px-2 py-1 text-xs font-normal text-muted-foreground"
                    >
                        View as table
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{
                                    trendTableFirstColumnHeader(
                                        props.statistics.bucket,
                                    )
                                }}</TableHead>
                                <TableHead>{{
                                    DELIVERY_SUCCESS_COLUMN_LABEL
                                }}</TableHead>
                                <TableHead>{{
                                    ATTEMPT_SUCCESS_COLUMN_LABEL
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="point in props.statistics.series"
                                :key="point.bucketStart"
                            >
                                <TableCell>{{
                                    formatBucketPeriod(
                                        point.bucketStart,
                                        props.statistics.bucket,
                                    )
                                }}</TableCell>
                                <!--
                                    An hourly row (`point.date === null`)
                                    owes no drill-through (Amendment
                                    B(ii)) and renders plain text — no
                                    `Link`, no button, no disabled/muted
                                    control, no explanatory note (§
                                    *Technical rulings* 13; T32). The gate
                                    reads `point.date` alone, never
                                    `props.statistics.bucket`.
                                -->
                                <TableCell>
                                    <Link
                                        v-if="props.dayHref && point.date"
                                        :href="
                                            props.dayHref(
                                                point.date,
                                                'delivery',
                                            )
                                        "
                                        class="hover:underline"
                                    >
                                        {{ compactRateText(point.delivery) }}
                                    </Link>
                                    <template v-else>{{
                                        compactRateText(point.delivery)
                                    }}</template>
                                </TableCell>
                                <TableCell>
                                    <Link
                                        v-if="props.dayHref && point.date"
                                        :href="
                                            props.dayHref(point.date, 'attempt')
                                        "
                                        class="hover:underline"
                                    >
                                        {{ compactRateText(point.attempt) }}
                                    </Link>
                                    <template v-else>{{
                                        compactRateText(point.attempt)
                                    }}</template>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CollapsibleContent>
            </Collapsible>
        </template>
    </Card>
</template>
