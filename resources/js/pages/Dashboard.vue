<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AnalyticsWindowNav from '@/components/analytics/AnalyticsWindowNav.vue';
import DeliveriesCard from '@/components/analytics/DeliveriesCard.vue';
import LatencyCard from '@/components/analytics/LatencyCard.vue';
import ProxyBreakdownTable from '@/components/analytics/ProxyBreakdownTable.vue';
import RetryReplayCard from '@/components/analytics/RetryReplayCard.vue';
import TrendCard from '@/components/analytics/TrendCard.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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

function windowHref(value: AnalyticsWindowValue) {
    return dashboard(teamSlug.value, { query: { window: value } });
}

function proxyShowHref(proxyId: number) {
    return proxyRoutes.show(
        { current_team: teamSlug.value, proxy: proxyId },
        { query: { window: props.statistics.window } },
    );
}

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
            <AnalyticsWindowNav
                :window="props.statistics.window"
                :href-for="windowHref"
            />
        </div>

        <DeliveriesCard :statistics="props.statistics" />

        <TrendCard :statistics="props.statistics" />

        <RetryReplayCard :statistics="props.statistics" />

        <LatencyCard :statistics="props.statistics" />

        <ProxyBreakdownTable
            :proxies="props.proxies"
            :window="props.statistics.window"
            :show-href="proxyShowHref"
            :failures-href="proxyFailuresHref"
        />
    </div>
</template>
