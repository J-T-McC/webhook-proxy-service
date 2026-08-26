<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CopyField from '@/components/CopyField.vue';
import TrendChart from '@/components/TrendChart.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
    LATENCY_AVERAGE_COLUMN_LABEL,
    LATENCY_AVERAGE_LABEL,
    LATENCY_CAPTION,
    LATENCY_P95_LABEL,
    LIVE_VS_REPLAY_LABEL,
    RETRY_VOLUME_LABEL,
    TERMINAL_FAILURE_LABEL,
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
    zeroProxyTrafficMessage,
} from '@/data/analyticsLabels';
import { proxyProcessingModeLabel } from '@/data/proxyProcessingModes';
import {
    proxyResponseStatusLabel,
    proxyStatusForcesEmptyBody,
} from '@/data/proxyResponseStatuses';
import {
    proxyRetryAttemptLimitDisplay,
    proxyRetryBackoffStrategyDisplay,
} from '@/data/proxyRetryBackoffStrategies';
import proxyRoutes from '@/routes/proxies';
import proxyEventRoutes from '@/routes/proxies/events';
import type { Team } from '@/types';
import type {
    AnalyticsWindowValue,
    DestinationBreakdownRow,
    StatisticsPanel,
} from '@/types/analytics';
import type { ProxyDetail, ProxyPermissions } from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyDetail;
    permissions: ProxyPermissions;
    statistics: StatisticsPanel;
    destinations: DestinationBreakdownRow[];
}>();

// Edit/delete visibility derives from the shared page-level permissions + the
// resource's is_creator flag (ADR-009 Amendment B5) — no per-record policy call.
// The server ProxyPolicy still enforces the mutation.
const canUpdate = computed(
    () =>
        props.permissions.canUpdateProxy &&
        (props.proxy.is_creator || props.permissions.canUpdateAnyProxy),
);
const canDelete = computed(
    () =>
        props.permissions.canDeleteProxy &&
        (props.proxy.is_creator || props.permissions.canDeleteAnyProxy),
);

defineOptions({
    layout: (options: { currentTeam?: Team | null; proxy: ProxyDetail }) => ({
        breadcrumbs: [
            {
                title: 'Proxies',
                href: options.currentTeam
                    ? proxyRoutes.index(options.currentTeam.slug)
                    : '/',
            },
            {
                title: options.proxy.name,
                href: options.currentTeam
                    ? proxyRoutes.show({
                          current_team: options.currentTeam.slug,
                          proxy: options.proxy.id,
                      })
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

// Response card — read-only view of the upstream acknowledgement contract. The
// status label and the 204 empty-body coupling come from the shared
// response-status const (@/data/proxyResponseStatuses), the same source the edit
// form's select options derive from, so a status reads identically in both.
const responseStatusLabel = computed(() =>
    proxyResponseStatusLabel(props.proxy.response_status),
);

// Whether the stored status forces an empty body (204 No Content) — drives the
// "No content" branch below without a bare 204 literal.
const statusForcesEmptyBody = computed(() =>
    proxyStatusForcesEmptyBody(props.proxy.response_status),
);

// A real body block renders only for a body-allowing status (not unconfigured,
// not empty-body) with a non-empty string; every other case (unconfigured, 204,
// or an empty/null body) shows muted text.
const hasResponseBody = computed(
    () =>
        props.proxy.response_status !== null &&
        !statusForcesEmptyBody.value &&
        props.proxy.response_body !== null &&
        props.proxy.response_body !== '',
);

// Retry policy card — read-only view of the effective retry policy (design-06
// Screen 1 / Flow G). A simple-mode proxy's `retry_attempt_limit`/
// `retry_backoff_strategy` are suppressed to null on this payload by
// `ProxyResource` (T5), gated server-side on `mode === Enhanced`
// (`RetryPolicy::configuredAttemptLimitFor()`/`configuredStrategyFor()`, T1,
// ADR-018 Decision 4) — never a raw-column read here, and never leaking a
// dormant value even if one is persisted (AC14(b)). So this card always
// renders the same "(default)" values as an unconfigured enhanced proxy — the
// display helpers don't need to branch on mode for the value itself, only for
// the extra note.
const retryAttemptsDisplay = computed(() =>
    proxyRetryAttemptLimitDisplay(props.proxy.retry_attempt_limit),
);
const retryBackoffDisplay = computed(() =>
    proxyRetryBackoffStrategyDisplay(props.proxy.retry_backoff_strategy),
);

/** The three windows the page-level selector switches between (AC17). */
const WINDOW_VALUES: AnalyticsWindowValue[] = ['24h', '7d', '30d'];

/**
 * A full-page navigation to this same proxy with a different `?window=`
 * (design-11 § Interactions) — never client-side state, so the server
 * recomputes every figure for the newly selected window.
 */
function windowHref(value: AnalyticsWindowValue) {
    return proxyRoutes.show(
        { current_team: teamSlug.value, proxy: props.proxy.id },
        { query: { window: value } },
    );
}

/**
 * The bridge sentence naming the gap between delivery- and attempt-level
 * success (AC14(d)) — `null` when there is nothing to bridge, so the
 * paragraph is omitted rather than rendered empty.
 */
const bridgeText = computed(() =>
    bridgeSentence(props.statistics.bridgeFailedAttempts),
);

/**
 * The "Retry & replay" Terminal failure tile's drill-through target (Flow C
 * step 4; design-11 Flow E entry-point table) — the only one of the four
 * tiles that is failure-shaped. Proxy · window · `outcome=delivery_failed`
 * (T23; T21's filter resolver reads this on the Events list controller). No
 * `canDrillThrough`-style gate is needed here: this page only renders for a
 * live proxy (the route's implicit model binding 404s on a soft-deleted one,
 * T22), so drill-through is always available from it.
 */
function terminalFailureHref() {
    return proxyEventRoutes.index(
        { current_team: teamSlug.value, proxy: props.proxy.id },
        {
            query: {
                window: props.statistics.window,
                outcome: 'delivery_failed',
            },
        },
    );
}

/**
 * The Destinations table's "View events" action target (Flow D step 3) —
 * proxy · destination · window, **no** outcome filter (this row's figures
 * are rates over all of that destination's traffic, not a failure count).
 * Carries the same link for a deleted destination as a live one — soft
 * delete preserves the id, and the destination needs only to be
 * identifiable, not manageable, for drill-through to work (design-11 Screen
 * 3, `Q-11-03(9)`'s destination half).
 */
function viewEventsHref(destination: DestinationBreakdownRow) {
    return proxyEventRoutes.index(
        { current_team: teamSlug.value, proxy: props.proxy.id },
        {
            query: {
                window: props.statistics.window,
                destination: destination.id,
            },
        },
    );
}

/**
 * The Trend table's per-day, per-unit drill-through target (Flow C step 3;
 * design-11 Flow E entry-point table; T23/Revision A, `Q-11-04`, plan
 * Technical ruling 10) — proxy (current) · window (still carried, ruling 10)
 * · the row's own `date`, narrowing the window to that single day ·
 * `outcome=delivery_failed`/`attempt_failed` at the clicked cell's unit. No
 * `canDrillThrough` gate needed, for the same reason `terminalFailureHref()`
 * above has none: this page only renders for a live proxy.
 *
 * Callers pass a row's `date` only when it is present — a row builds a link
 * when and only when it has one (§ *Technical rulings* 13; T32). `date`
 * being `string` here rather than `string | null` is intentional: the
 * template gates on `point.date` before ever calling this function, so an
 * hourly row (whose `date` is `null`) never reaches it.
 */
function trendDayHref(date: string, unit: 'delivery' | 'attempt') {
    return proxyEventRoutes.index(
        { current_team: teamSlug.value, proxy: props.proxy.id },
        {
            query: {
                window: props.statistics.window,
                date,
                outcome:
                    unit === 'delivery' ? 'delivery_failed' : 'attempt_failed',
            },
        },
    );
}

const proxyDeleteOpen = ref(false);
const busy = ref(false);

function confirmDeleteProxy(): void {
    busy.value = true;

    router.delete(
        proxyRoutes.destroy({
            current_team: teamSlug.value,
            proxy: props.proxy.id,
        }).url,
        {
            onFinish: () => {
                busy.value = false;
                proxyDeleteOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="props.proxy.name" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
        <div class="flex flex-col gap-1">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold">
                        {{ props.proxy.name }}
                    </h1>
                    <Badge variant="secondary">
                        {{
                            props.proxy.mode === 'enhanced'
                                ? 'Enhanced'
                                : 'Simple'
                        }}
                    </Badge>
                    <Badge variant="secondary">
                        {{
                            proxyProcessingModeLabel(
                                props.proxy.processing_mode,
                            )
                        }}
                    </Badge>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" as-child>
                        <Link
                            :href="
                                proxyEventRoutes.index({
                                    current_team: teamSlug,
                                    proxy: props.proxy.id,
                                })
                            "
                        >
                            Events
                        </Link>
                    </Button>
                    <Button v-if="canUpdate" variant="outline" as-child>
                        <Link
                            :href="
                                proxyRoutes.edit({
                                    current_team: teamSlug,
                                    proxy: props.proxy.id,
                                })
                            "
                        >
                            Edit
                        </Link>
                    </Button>
                    <Button
                        v-if="canDelete"
                        variant="destructive"
                        :aria-label="`Delete proxy ${props.proxy.name}`"
                        @click="proxyDeleteOpen = true"
                    >
                        Delete
                    </Button>
                </div>
            </div>
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

        <!-- Analytics cards (design-11 Screen 2; flagged design call 3's
             accepted reordering — these lead ahead of Ingest URL because "is
             this working" is the reason a member opens a proxy from a
             Dashboard drill-through, Flow C step 1). Split from one combined
             card into the same four cards the Dashboard renders, on an Owner
             ruling of 2026-08-26 — see the note in design-11. The window
             selector is page-level and now sits in the page header, where it
             stays reachable even in the zero-traffic state so a member can
             check another window. -->
        <Card class="gap-4 p-6">
            <h2 class="text-base font-semibold">Deliveries</h2>

            <p
                v-if="!props.statistics.hasTraffic"
                class="text-sm text-muted-foreground"
            >
                {{ zeroProxyTrafficMessage(props.statistics.window) }}
            </p>

            <template v-else>
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
                <p
                    v-if="bridgeText"
                    class="text-sm text-muted-foreground italic"
                >
                    {{ bridgeText }}
                </p>
            </template>
        </Card>

        <template v-if="props.statistics.hasTraffic">
            <Card class="gap-4 p-6">
                <h2 class="text-base font-semibold">Trend</h2>
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
                                            v-if="point.date"
                                            :href="
                                                trendDayHref(
                                                    point.date,
                                                    'delivery',
                                                )
                                            "
                                            class="hover:underline"
                                        >
                                            {{
                                                compactRateText(point.delivery)
                                            }}
                                        </Link>
                                        <template v-else>{{
                                            compactRateText(point.delivery)
                                        }}</template>
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            v-if="point.date"
                                            :href="
                                                trendDayHref(
                                                    point.date,
                                                    'attempt',
                                                )
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
            </Card>

            <Card class="gap-4 p-6">
                <div>
                    <h3 class="text-base font-semibold">Retry & replay</h3>
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
                            <Link
                                :href="terminalFailureHref()"
                                class="hover:underline"
                            >
                                {{
                                    props.statistics.retryReplay.terminalFailure
                                }}
                            </Link>
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

            <Card class="gap-4 p-6">
                <div>
                    <h3 class="text-base font-semibold">Latency</h3>
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
                                formatLatencyMs(
                                    props.statistics.latency.averageMs,
                                )
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
                            {{
                                formatLatencyMs(props.statistics.latency.p95Ms)
                            }}
                        </dd>
                    </div>
                </dl>
                <p class="text-sm text-muted-foreground">
                    {{ LATENCY_CAPTION }}
                </p>
            </Card>
        </template>

        <!-- Ingest URL card -->
        <Card class="gap-4 p-6">
            <h2 class="text-base font-semibold">Ingest URL</h2>
            <CopyField :value="props.proxy.ingest_url" />
            <p class="text-sm text-muted-foreground">
                Anyone with this URL can post webhooks to this proxy. Keep it
                secret.
            </p>
        </Card>

        <!-- Response card -->
        <Card class="gap-4 p-6">
            <h2 class="text-base font-semibold">Response</h2>
            <p class="text-sm text-muted-foreground">
                Returned to the sender immediately when the webhook is received
                — independent of whether delivery to your destinations succeeds.
            </p>
            <dl class="flex flex-col gap-3">
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Status</dt>
                    <dd>
                        <Badge variant="secondary">{{
                            responseStatusLabel
                        }}</Badge>
                    </dd>
                </div>
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Body</dt>
                    <dd class="min-w-0 flex-1">
                        <span
                            v-if="props.proxy.response_status === null"
                            class="text-sm text-muted-foreground italic"
                        >
                            No custom body configured — the default response has
                            no body.
                        </span>
                        <span
                            v-else-if="statusForcesEmptyBody"
                            class="text-sm text-muted-foreground italic"
                        >
                            No content (204)
                        </span>
                        <div
                            v-else-if="hasResponseBody"
                            class="max-h-48 overflow-y-auto rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm break-words whitespace-pre-wrap dark:bg-input/30"
                            v-text="props.proxy.response_body"
                        />
                        <span
                            v-else
                            class="text-sm text-muted-foreground italic"
                        >
                            (empty)
                        </span>
                    </dd>
                </div>
            </dl>
        </Card>

        <!-- Destinations card (design-11 Screen 3) — driven from
             `props.destinations` (T18's `DestinationBreakdownRow[]`), never
             from `props.proxy.destinations` (that relation is live-only and
             shared with index()/edit(), plan Implementation Note 11), so a
             deleted destination with historical traffic still gets a row. -->
        <Card class="gap-4 p-6">
            <div>
                <h2 class="text-base font-semibold">Destinations</h2>
                <p class="text-sm text-muted-foreground">
                    {{ lastWindowSubtitle(props.statistics.window) }}
                </p>
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Destination</TableHead>
                        <TableHead>{{
                            DELIVERY_SUCCESS_COLUMN_LABEL
                        }}</TableHead>
                        <TableHead>{{
                            ATTEMPT_SUCCESS_COLUMN_LABEL
                        }}</TableHead>
                        <TableHead>{{
                            LATENCY_AVERAGE_COLUMN_LABEL
                        }}</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="destination in props.destinations"
                        :key="destination.id"
                    >
                        <TableCell>
                            <div class="flex min-w-0 items-center gap-3">
                                <Badge variant="outline">{{
                                    destination.httpMethod
                                }}</Badge>
                                <span class="truncate font-mono text-sm">{{
                                    destination.url
                                }}</span>
                            </div>
                        </TableCell>
                        <TableCell>{{
                            compactRateText(destination.delivery)
                        }}</TableCell>
                        <TableCell>{{
                            compactRateText(destination.attempt)
                        }}</TableCell>
                        <TableCell>{{
                            formatLatencyMs(destination.latencyAverageMs)
                        }}</TableCell>
                        <TableCell class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <Badge
                                    v-if="destination.isDeleted"
                                    variant="secondary"
                                >
                                    Deleted
                                </Badge>
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="viewEventsHref(destination)">
                                        View events
                                    </Link>
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </Card>

        <!-- Retry policy card -->
        <Card class="gap-4 p-6">
            <h2 class="text-base font-semibold">Retry policy</h2>
            <p class="text-sm text-muted-foreground">
                Governs automatic re-attempts to your destinations after a
                failed delivery.
            </p>
            <dl class="flex flex-col gap-3">
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Attempts</dt>
                    <dd class="text-sm">{{ retryAttemptsDisplay }}</dd>
                </div>
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Backoff</dt>
                    <dd class="text-sm">{{ retryBackoffDisplay }}</dd>
                </div>
            </dl>
            <p
                v-if="props.proxy.mode === 'simple'"
                class="text-sm text-muted-foreground"
            >
                Simple-mode proxies use the fixed system default. Configuring
                attempts and backoff is an Enhanced-mode capability.
            </p>
        </Card>
    </div>

    <!-- Delete proxy confirmation -->
    <AlertDialog
        :open="proxyDeleteOpen"
        @update:open="(value) => (proxyDeleteOpen = value)"
    >
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>
                    Delete &ldquo;{{ props.proxy.name }}&rdquo;?
                </AlertDialogTitle>
                <AlertDialogDescription>
                    Its ingest URL will stop accepting webhooks and all its
                    destinations are removed. This cannot be undone.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    class="bg-destructive text-white hover:bg-destructive/90"
                    :disabled="busy"
                    @click="confirmDeleteProxy"
                >
                    Delete proxy
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
