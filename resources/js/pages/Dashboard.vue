<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import TrendChart from '@/components/TrendChart.vue';
import { Badge } from '@/components/ui/badge';
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
    ATTEMPT_SUCCESS_LABEL,
    DELIVERY_SUCCESS_COLUMN_LABEL,
    DELIVERY_SUCCESS_LABEL,
    EVENTUAL_SUCCESS_LABEL,
    LATENCY_AVERAGE_LABEL,
    LATENCY_CAPTION,
    LATENCY_P95_LABEL,
    LIVE_VS_REPLAY_LABEL,
    RETRY_VOLUME_LABEL,
    TERMINAL_FAILURE_LABEL,
    TERMINAL_FAILURES_COLUMN_LABEL,
    TREND_NO_DATA_LABEL,
    attemptCaption,
    bridgeSentence,
    compactRateText,
    deliveryCaption,
    formatBucketPeriod,
    formatLatencyMs,
    formatRate,
    lastWindowSubtitle,
    liveVsReplayText,
    trendTableFirstColumnHeader,
} from '@/data/analyticsLabels';
import { dashboard } from '@/routes';
import proxyRoutes from '@/routes/proxies';
import proxyEventRoutes from '@/routes/proxies/events';
import type { DashboardInvitation, Team } from '@/types';
import type {
    AnalyticsWindowValue,
    ProxyBreakdownRow,
    StatisticsPanel,
} from '@/types/analytics';

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    statistics: StatisticsPanel;
    proxies: ProxyBreakdownRow[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

/** The three windows the page-level selector switches between (AC17). */
const WINDOW_VALUES: AnalyticsWindowValue[] = ['24h', '7d', '30d'];

/**
 * A full-page navigation to the same Dashboard with a different `?window=`
 * (design-11 § Interactions) — never client-side state, so the server
 * recomputes every figure for the newly selected window.
 */
function windowHref(value: AnalyticsWindowValue) {
    return dashboard(teamSlug.value, { query: { window: value } });
}

/**
 * The bridge sentence naming the gap between delivery- and attempt-level
 * success (AC14(d)) — `null` when there is nothing to bridge, so the
 * paragraph is omitted rather than rendered empty.
 */
const bridgeText = computed(() =>
    bridgeSentence(props.statistics.bridgeFailedAttempts),
);

// Proxies table sorting (design-11 § Interactions: client-side, on data
// already on the page — no new request). Default is alphabetical by name,
// ascending (Flow B step 2; flagged design call 5 — never worst-first).
type ProxySortColumn = 'name' | 'delivery' | 'attempt' | 'terminalFailures';

const proxySortColumn = ref<ProxySortColumn>('name');
const proxySortDirection = ref<'asc' | 'desc'>('asc');

function toggleProxySort(column: ProxySortColumn): void {
    if (proxySortColumn.value === column) {
        proxySortDirection.value =
            proxySortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        proxySortColumn.value = column;
        proxySortDirection.value = 'asc';
    }
}

function proxySortAria(
    column: ProxySortColumn,
): 'ascending' | 'descending' | 'none' {
    if (proxySortColumn.value !== column) {
        return 'none';
    }

    return proxySortDirection.value === 'asc' ? 'ascending' : 'descending';
}

function proxySortValue(
    row: ProxyBreakdownRow,
    column: ProxySortColumn,
): string | number {
    switch (column) {
        case 'name':
            return row.name.toLowerCase();
        case 'delivery':
            // A `null` rate (no traffic) sorts before every real rate.
            return row.delivery.rate ?? -1;
        case 'attempt':
            return row.attempt.rate ?? -1;
        case 'terminalFailures':
            return row.terminalFailures;
    }
}

const sortedProxies = computed(() => {
    const direction = proxySortDirection.value === 'asc' ? 1 : -1;

    return [...props.proxies].sort((a, b) => {
        const valueA = proxySortValue(a, proxySortColumn.value);
        const valueB = proxySortValue(b, proxySortColumn.value);

        if (valueA < valueB) {
            return -1 * direction;
        }

        if (valueA > valueB) {
            return 1 * direction;
        }

        return 0;
    });
});

/**
 * Proxy Show, carrying the currently selected window (Flow B step 4) — the
 * next page opens on the same period rather than resetting to the default.
 */
function proxyShowHref(proxyId: number) {
    return proxyRoutes.show(
        { current_team: teamSlug.value, proxy: proxyId },
        { query: { window: props.statistics.window } },
    );
}

/**
 * The Terminal-failures cell's drill-through target (Flow B step 5;
 * design-11 Flow E entry-point table) — proxy · window ·
 * `outcome=delivery_failed` (T23; T21's filter resolver reads this on the
 * Events list controller).
 */
function proxyFailuresHref(proxyId: number) {
    return proxyEventRoutes.index(
        { current_team: teamSlug.value, proxy: proxyId },
        {
            query: {
                window: props.statistics.window,
                outcome: 'delivery_failed',
            },
        },
    );
}
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <!-- No proxies at all: the whole page below the header is a single
         centered card — no window selector, no headline/table/chart shells
         (design-11 Screen 1, "No proxies at all" state; nothing to window
         over when there is nothing to measure yet). -->
    <div
        v-if="props.proxies.length === 0"
        class="flex h-full flex-1 flex-col items-center justify-center p-4"
    >
        <Card class="max-w-md items-center gap-3 p-10 text-center">
            <h2 class="text-lg font-medium">No proxies yet</h2>
            <p class="text-sm text-muted-foreground">
                Create a proxy to start receiving and delivering webhooks —
                figures appear here once it does.
            </p>
            <Button variant="outline" as-child class="mt-2">
                <Link :href="proxyRoutes.create(teamSlug)">
                    Create a proxy
                </Link>
            </Button>
        </Card>
    </div>

    <div
        v-else
        class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-4"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">Dashboard</h1>
            <nav class="flex items-center gap-2" aria-label="Time window">
                <Button
                    v-for="value in WINDOW_VALUES"
                    :key="value"
                    as-child
                    :variant="
                        value === props.statistics.window
                            ? 'default'
                            : 'outline'
                    "
                    size="sm"
                >
                    <Link
                        :href="windowHref(value)"
                        :aria-current="
                            value === props.statistics.window
                                ? 'true'
                                : undefined
                        "
                    >
                        {{ value }}
                    </Link>
                </Button>
            </nav>
        </div>

        <!-- Deliveries card -->
        <Card class="gap-4 p-6">
            <h2 class="text-base font-semibold">Deliveries</h2>
            <dl class="flex flex-col gap-5">
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ DELIVERY_SUCCESS_LABEL }}
                    </dt>
                    <dd>
                        <span class="text-3xl font-semibold">
                            {{ formatRate(props.statistics.delivery.rate) }}
                        </span>
                        <p class="text-sm text-muted-foreground">
                            {{
                                deliveryCaption(
                                    props.statistics.delivery.succeeded,
                                    props.statistics.delivery.total,
                                    props.statistics.window,
                                )
                            }}
                        </p>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ ATTEMPT_SUCCESS_LABEL }}
                    </dt>
                    <dd>
                        <span class="text-lg font-medium">
                            {{ formatRate(props.statistics.attempt.rate) }}
                        </span>
                        <p class="text-sm text-muted-foreground">
                            {{
                                attemptCaption(
                                    props.statistics.attempt.succeeded,
                                    props.statistics.attempt.total,
                                    props.statistics.window,
                                )
                            }}
                        </p>
                    </dd>
                </div>
            </dl>
            <p v-if="bridgeText" class="text-sm text-muted-foreground italic">
                {{ bridgeText }}
            </p>
        </Card>

        <!-- Trend card -->
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
                        <Button variant="ghost" size="sm" class="w-fit">
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
                                    <TableCell>{{
                                        compactRateText(point.delivery)
                                    }}</TableCell>
                                    <TableCell>{{
                                        compactRateText(point.attempt)
                                    }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CollapsibleContent>
                </Collapsible>
            </template>
        </Card>

        <!-- Retry & replay card -->
        <Card class="gap-4 p-6">
            <div>
                <h2 class="text-base font-semibold">Retry & replay</h2>
                <p class="text-sm text-muted-foreground">
                    {{ lastWindowSubtitle(props.statistics.window) }}
                </p>
            </div>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ EVENTUAL_SUCCESS_LABEL }}
                    </dt>
                    <dd class="text-lg font-medium">
                        {{ props.statistics.retryReplay.eventualSuccess }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ TERMINAL_FAILURE_LABEL }}
                    </dt>
                    <dd class="text-lg font-medium">
                        {{ props.statistics.retryReplay.terminalFailure }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ RETRY_VOLUME_LABEL }}
                    </dt>
                    <dd class="text-lg font-medium">
                        {{ props.statistics.retryReplay.retryVolume }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ LIVE_VS_REPLAY_LABEL }}
                    </dt>
                    <dd class="text-lg font-medium">
                        {{
                            liveVsReplayText(
                                props.statistics.retryReplay.live,
                                props.statistics.retryReplay.replay,
                            )
                        }}
                    </dd>
                </div>
            </dl>
        </Card>

        <!-- Latency card -->
        <Card class="gap-4 p-6">
            <div>
                <h2 class="text-base font-semibold">Latency</h2>
                <p class="text-sm text-muted-foreground">
                    {{ lastWindowSubtitle(props.statistics.window) }}
                </p>
            </div>
            <dl class="flex flex-col gap-3">
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">
                        {{ LATENCY_AVERAGE_LABEL }}
                    </dt>
                    <dd class="text-lg font-medium">
                        {{
                            formatLatencyMs(props.statistics.latency.averageMs)
                        }}
                    </dd>
                </div>
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">
                        {{ LATENCY_P95_LABEL }}
                    </dt>
                    <dd class="text-lg font-medium">
                        {{ formatLatencyMs(props.statistics.latency.p95Ms) }}
                    </dd>
                </div>
            </dl>
            <p class="text-sm text-muted-foreground">{{ LATENCY_CAPTION }}</p>
        </Card>

        <!-- Proxies card -->
        <Card class="gap-4 p-6">
            <div>
                <h2 class="text-base font-semibold">Proxies</h2>
                <p class="text-sm text-muted-foreground">
                    {{ lastWindowSubtitle(props.statistics.window) }}
                </p>
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead :aria-sort="proxySortAria('name')">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 font-medium"
                                @click="toggleProxySort('name')"
                            >
                                Proxy
                                <span
                                    v-if="proxySortColumn === 'name'"
                                    aria-hidden="true"
                                >
                                    {{
                                        proxySortDirection === 'asc' ? '▲' : '▼'
                                    }}
                                </span>
                            </button>
                        </TableHead>
                        <TableHead :aria-sort="proxySortAria('delivery')">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 font-medium"
                                @click="toggleProxySort('delivery')"
                            >
                                {{ DELIVERY_SUCCESS_COLUMN_LABEL }}
                                <span
                                    v-if="proxySortColumn === 'delivery'"
                                    aria-hidden="true"
                                >
                                    {{
                                        proxySortDirection === 'asc' ? '▲' : '▼'
                                    }}
                                </span>
                            </button>
                        </TableHead>
                        <TableHead :aria-sort="proxySortAria('attempt')">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 font-medium"
                                @click="toggleProxySort('attempt')"
                            >
                                {{ ATTEMPT_SUCCESS_COLUMN_LABEL }}
                                <span
                                    v-if="proxySortColumn === 'attempt'"
                                    aria-hidden="true"
                                >
                                    {{
                                        proxySortDirection === 'asc' ? '▲' : '▼'
                                    }}
                                </span>
                            </button>
                        </TableHead>
                        <TableHead
                            :aria-sort="proxySortAria('terminalFailures')"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 font-medium"
                                @click="toggleProxySort('terminalFailures')"
                            >
                                {{ TERMINAL_FAILURES_COLUMN_LABEL }}
                                <span
                                    v-if="
                                        proxySortColumn === 'terminalFailures'
                                    "
                                    aria-hidden="true"
                                >
                                    {{
                                        proxySortDirection === 'asc' ? '▲' : '▼'
                                    }}
                                </span>
                            </button>
                        </TableHead>
                        <TableHead class="text-right">
                            <span class="sr-only">View</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="proxy in sortedProxies" :key="proxy.id">
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <Link
                                    v-if="proxy.canDrillThrough"
                                    :href="proxyShowHref(proxy.id)"
                                    class="font-medium hover:underline"
                                >
                                    {{ proxy.name }}
                                </Link>
                                <span v-else class="font-medium">{{
                                    proxy.name
                                }}</span>
                                <Badge
                                    v-if="proxy.isDeleted"
                                    variant="secondary"
                                >
                                    Deleted
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell>{{
                            compactRateText(proxy.delivery)
                        }}</TableCell>
                        <TableCell>{{
                            compactRateText(proxy.attempt)
                        }}</TableCell>
                        <TableCell>
                            <Link
                                v-if="proxy.canDrillThrough"
                                :href="proxyFailuresHref(proxy.id)"
                                class="hover:underline"
                            >
                                {{ proxy.terminalFailures }}
                            </Link>
                            <span v-else>{{ proxy.terminalFailures }}</span>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                v-if="proxy.canDrillThrough"
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <Link :href="proxyShowHref(proxy.id)">
                                    View
                                </Link>
                            </Button>
                            <span v-else class="text-sm text-muted-foreground"
                                >—</span
                            >
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </Card>
    </div>
</template>
