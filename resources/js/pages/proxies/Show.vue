<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock } from '@lucide/vue';
import { computed, ref } from 'vue';
import AnalyticsWindowNav from '@/components/analytics/AnalyticsWindowNav.vue';
import DeliveriesCard from '@/components/analytics/DeliveriesCard.vue';
import LatencyCard from '@/components/analytics/LatencyCard.vue';
import RetryReplayCard from '@/components/analytics/RetryReplayCard.vue';
import TrendCard from '@/components/analytics/TrendCard.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DestinationsCard from '@/components/proxies/DestinationsCard.vue';
import IngestUrlCard from '@/components/proxies/IngestUrlCard.vue';
import ResponseCard from '@/components/proxies/ResponseCard.vue';
import RetryPolicyCard from '@/components/proxies/RetryPolicyCard.vue';
import SigningCard from '@/components/proxies/SigningCard.vue';
import ProxySigningDialog from '@/components/ProxySigningDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useProxyActions } from '@/composables/useProxyActions';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { zeroProxyTrafficMessage } from '@/data/analyticsLabels';
import { proxyProcessingModeLabel } from '@/data/proxyProcessingModes';
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
/**
 * How many of this proxy's live destinations are not Validated (T18; AC33,
 * design-18 Screen 3) — Unvalidated, Pending and Expired together: the
 * badge's job is to say traffic is incomplete, not which of the three
 * reasons applies (the Destinations table below says which and why). Counts
 * over the live relation, never the analytics rows — a soft-deleted
 * destination is not part of the fan-out and has nothing to validate.
 */
const unvalidatedCount = computed(
    () =>
        props.proxy.destinations.filter(
            (destination) =>
                props.security.destinations[destination.id]?.validation
                    .status !== 'validated',
        ).length,
);

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
 * proxy · destination · window, **no** outcome filter.
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
 * being `string` here rather than `string | null` is intentional: TrendCard
 * gates on `point.date` before ever calling this function, so an hourly row
 * (whose `date` is `null`) never reaches it.
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

const signingDialogOpen = ref(false);
const proxyPauseOpen = ref(false);
const proxyDeleteOpen = ref(false);

const {
    pauseResumeBusy,
    deleteBusy,
    signingOverlapBusy,
    signingOverlapError,
    validateBusyId,
    pauseProxy,
    resumeProxy,
    deleteProxy,
    endSigningOverlap,
    validateDestination,
} = useProxyActions(teamSlug, props.proxy.id);
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
                    <!-- T18 (AC33) — renders only when something needs
                    attention, like Paused; coexists with it (AC36 — held
                    traffic and incomplete permission are unrelated facts).
                    Never "skipped"/"failing": those are delivery and pause
                    vocabulary (AC32). No positive counterpart when all are
                    validated. -->
                    <Badge v-if="unvalidatedCount > 0" variant="waiting">
                        <Clock />
                        {{ unvalidatedCount }} destination{{
                            unvalidatedCount === 1 ? '' : 's'
                        }}
                        not yet validated
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
                        @click="resumeProxy()"
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
             selector is page-level and sits in the page header, where it
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

        <IngestUrlCard :ingest-url="props.proxy.ingest_url" />

        <ResponseCard
            :response-status="props.proxy.response_status"
            :response-body="props.proxy.response_body"
        />

        <DestinationsCard
            :destinations="props.destinations"
            :security="props.security.destinations"
            :can-update="canUpdate"
            :validate-busy-id="validateBusyId"
            :window="props.statistics.window"
            :view-events-href="viewEventsHref"
            @validate="validateDestination"
        />

        <SigningCard
            :signing="props.security.signing"
            :can-update="canUpdate"
            :overlap-busy="signingOverlapBusy"
            :overlap-error="signingOverlapError"
            @manage="signingDialogOpen = true"
            @end-overlap="endSigningOverlap()"
        />

        <RetryPolicyCard
            :mode="props.proxy.mode"
            :retry-attempt-limit="props.proxy.retry_attempt_limit"
            :retry-backoff-strategy="props.proxy.retry_backoff_strategy"
        />
    </div>

    <!-- Manage proxy signing dialog (Screen 6; Flows G, H, I) -->
    <ProxySigningDialog
        v-model:open="signingDialogOpen"
        :team-slug="teamSlug"
        :proxy-id="props.proxy.id"
        :proxy-name="props.proxy.name"
        :signing="props.security.signing"
        :can-update="canUpdate"
    />

    <!-- AC10: the consequence is stated before the decision — resuming needs
         no confirmation at all. -->
    <ConfirmDialog
        v-model:open="proxyPauseOpen"
        :title="`Pause “${props.proxy.name}”?`"
        description="Nothing will be sent to its destinations until it is resumed. Events keep aging and expire on schedule whether or not they were sent."
        confirm-label="Pause proxy"
        :busy="pauseResumeBusy"
        @confirm="pauseProxy(() => (proxyPauseOpen = false))"
    />

    <ConfirmDialog
        v-model:open="proxyDeleteOpen"
        :title="`Delete “${props.proxy.name}”?`"
        description="Its ingest URL will stop accepting webhooks and all its destinations are removed. This cannot be undone."
        confirm-label="Delete proxy"
        destructive
        :busy="deleteBusy"
        @confirm="deleteProxy(() => (proxyDeleteOpen = false))"
    />
</template>
