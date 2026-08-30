<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import AnalyticsWindowNav from '@/components/analytics/AnalyticsWindowNav.vue';
import DeliveriesCard from '@/components/analytics/DeliveriesCard.vue';
import LatencyCard from '@/components/analytics/LatencyCard.vue';
import RetryReplayCard from '@/components/analytics/RetryReplayCard.vue';
import TrendCard from '@/components/analytics/TrendCard.vue';
import CopyField from '@/components/CopyField.vue';
import ProxySigningDialog from '@/components/ProxySigningDialog.vue';
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
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTeamSlug } from '@/composables/useTeamSlug';
import {
    ATTEMPT_SUCCESS_COLUMN_LABEL,
    DELIVERY_SUCCESS_COLUMN_LABEL,
    LATENCY_AVERAGE_COLUMN_LABEL,
    compactRateText,
    formatLatencyMs,
    lastWindowSubtitle,
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
import { proxiesCrumb, proxyCrumb } from '@/lib/breadcrumbs';
import { formatTimestamp } from '@/lib/format';
import proxyRoutes from '@/routes/proxies';
import proxyEventRoutes from '@/routes/proxies/events';
import type { Team } from '@/types';
import type {
    AnalyticsWindowValue,
    DestinationBreakdownRow,
    StatisticsPanel,
} from '@/types/analytics';
import type {
    ProxyDetail,
    ProxyPermissions,
    ProxySecurity,
} from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyDetail;
    permissions: ProxyPermissions;
    statistics: StatisticsPanel;
    destinations: DestinationBreakdownRow[];
    /** Status-only signing and destination-credential state (T22) — never a
     * value, never a length. */
    security: ProxySecurity;
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
            proxiesCrumb(options.currentTeam),
            proxyCrumb(options.currentTeam, options.proxy),
        ],
    }),
});

const teamSlug = useTeamSlug();

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

function windowHref(value: AnalyticsWindowValue) {
    return proxyRoutes.show(
        { current_team: teamSlug.value, proxy: props.proxy.id },
        { query: { window: value } },
    );
}

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
 * Screen 5's `Credential` badge (T33; AC30; plan Technical ruling 4) — looked
 * up by the row's existing id in the `security.destinations` map (T32),
 * never a field on `DestinationBreakdownRow` itself (that DTO is untouched
 * by this feature). Defaults to `false` for an id the map doesn't carry
 * (there is none in practice — T32's map is built `withTrashed()` over every
 * destination the proxy has — but this keeps the lookup total).
 */
function hasCredential(destination: DestinationBreakdownRow): boolean {
    return props.security.destinations[destination.id]?.has_credential ?? false;
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

// Signing card — Screen 4b (AC54, AC57, AC63; Flows G, I). Proxy-wide status
// only, driven entirely by `security.signing` (T38); the mutating actions
// (Enable/Manage signing, End overlap now) are `canUpdate`-gated, the status
// itself always renders.
const signingOverlapStatus = computed(() => {
    const expiresAt = props.security.signing.overlap_expires_at;

    return expiresAt ? formatTimestamp(expiresAt) : null;
});
const signingGeneratedStatus = computed(() =>
    props.security.signing.generated_at
        ? `Enabled — generated ${formatTimestamp(props.security.signing.generated_at)}`
        : null,
);

const signingDialogOpen = ref(false);
const signingOverlapBusy = ref(false);
const signingOverlapError = ref<string | null>(null);

function endSigningOverlap(): void {
    signingOverlapBusy.value = true;
    signingOverlapError.value = null;

    router.delete(
        proxyRoutes.signing.overlap.destroy({
            current_team: teamSlug.value,
            proxy: props.proxy.id,
        }).url,
        {
            preserveScroll: true,
            only: ['security'],
            onError: () => {
                signingOverlapError.value =
                    'Could not end the rotation overlap. Try again.';
            },
            onFinish: () => {
                signingOverlapBusy.value = false;
            },
        },
    );
}

const proxyPauseOpen = ref(false);
const pauseResumeBusy = ref(false);

function confirmPauseProxy(): void {
    pauseResumeBusy.value = true;

    router.post(
        proxyRoutes.pause.store({
            current_team: teamSlug.value,
            proxy: props.proxy.id,
        }).url,
        {},
        {
            onFinish: () => {
                pauseResumeBusy.value = false;
                proxyPauseOpen.value = false;
            },
        },
    );
}

function resumeProxy(): void {
    // AC10: resuming requires no confirmation.
    pauseResumeBusy.value = true;

    router.delete(
        proxyRoutes.pause.destroy({
            current_team: teamSlug.value,
            proxy: props.proxy.id,
        }).url,
        {
            onFinish: () => {
                pauseResumeBusy.value = false;
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
                    <Badge v-if="props.proxy.paused_at" variant="outline">
                        Paused since
                        {{ formatTimestamp(props.proxy.paused_at) }}
                    </Badge>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="canUpdate && !props.proxy.paused_at"
                        variant="outline"
                        :disabled="pauseResumeBusy"
                        @click="proxyPauseOpen = true"
                    >
                        Pause
                    </Button>
                    <Button
                        v-if="canUpdate && props.proxy.paused_at"
                        variant="outline"
                        :disabled="pauseResumeBusy"
                        @click="resumeProxy"
                    >
                        <Spinner v-if="pauseResumeBusy" />
                        Resume
                    </Button>
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
            <AnalyticsWindowNav
                :window="props.statistics.window"
                :href-for="windowHref"
            />
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
        <DeliveriesCard
            :statistics="props.statistics"
            :empty-message="zeroProxyTrafficMessage(props.statistics.window)"
        />

        <template v-if="props.statistics.hasTraffic">
            <TrendCard
                :statistics="props.statistics"
                :day-href="trendDayHref"
            />

            <RetryReplayCard
                :statistics="props.statistics"
                :terminal-failure-href="terminalFailureHref()"
            />

            <LatencyCard :statistics="props.statistics" />
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
                                <Badge
                                    v-if="hasCredential(destination)"
                                    variant="outline"
                                >
                                    Credential
                                </Badge>
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

        <!-- Signing card (Screen 4b; AC54, AC57, AC63; Flows G, I) — the
             proxy-wide outbound signing status. No per-destination badge
             anywhere (Amendment B ruling 1) and no trust-domain warning
             (ruling 2b) — this card states the proxy-wide fact once, where
             the setting lives. -->
        <Card class="gap-4 p-6">
            <h2 class="text-base font-semibold">Signing</h2>
            <p class="text-sm text-muted-foreground">
                Whether this proxy signs its dispatches so every destination it
                sends to can verify the request really came from this proxy.
            </p>

            <template v-if="!props.security.signing.enabled">
                <p class="text-sm text-muted-foreground">
                    This proxy does not sign its dispatches yet.
                </p>
                <Button
                    v-if="canUpdate"
                    variant="outline"
                    class="w-fit"
                    @click="signingDialogOpen = true"
                >
                    Enable signing
                </Button>
            </template>

            <template v-else>
                <dl class="flex flex-col gap-3">
                    <div
                        class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                    >
                        <dt class="text-sm text-muted-foreground">Status</dt>
                        <dd class="text-sm">{{ signingGeneratedStatus }}</dd>
                    </div>
                </dl>

                <!-- Rotation status always renders for anyone who can view
                     this proxy; only the mutating actions are canUpdate-gated. -->
                <template v-if="signingOverlapStatus">
                    <p class="text-sm">
                        A rotation is in progress — your previous secret is
                        still honoured until {{ signingOverlapStatus }}.
                    </p>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="canUpdate"
                            variant="outline"
                            :disabled="signingOverlapBusy"
                            @click="endSigningOverlap"
                        >
                            <Spinner v-if="signingOverlapBusy" />
                            End overlap now
                        </Button>
                        <Button
                            v-if="canUpdate"
                            variant="ghost"
                            @click="signingDialogOpen = true"
                        >
                            Manage signing
                        </Button>
                    </div>
                    <AlertError
                        v-if="signingOverlapError"
                        :errors="[signingOverlapError]"
                        title="Could not end the rotation overlap"
                    />
                </template>
                <Button
                    v-else-if="canUpdate"
                    variant="ghost"
                    class="w-fit"
                    @click="signingDialogOpen = true"
                >
                    Manage signing
                </Button>
            </template>
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

    <!-- Manage proxy signing dialog (Screen 6; Flows G, H, I) -->
    <ProxySigningDialog
        :open="signingDialogOpen"
        :team-slug="teamSlug"
        :proxy-id="props.proxy.id"
        :proxy-name="props.proxy.name"
        :signing="props.security.signing"
        :can-update="canUpdate"
        @update:open="(value) => (signingDialogOpen = value)"
    />

    <!-- Pause proxy confirmation (AC10: the consequence is stated before the
         decision — resuming needs no confirmation at all). -->
    <AlertDialog
        :open="proxyPauseOpen"
        @update:open="(value) => (proxyPauseOpen = value)"
    >
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>
                    Pause &ldquo;{{ props.proxy.name }}&rdquo;?
                </AlertDialogTitle>
                <AlertDialogDescription>
                    Nothing will be sent to its destinations until it is
                    resumed. Events keep aging and expire on schedule whether or
                    not they were sent.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    :disabled="pauseResumeBusy"
                    @click="confirmPauseProxy"
                >
                    Pause proxy
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

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
