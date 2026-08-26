<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    ATTEMPT_SUCCESS_LABEL,
    DELIVERY_SUCCESS_LABEL,
    attemptCaption,
    bridgeSentence,
    deliveryCaption,
    formatRate,
} from '@/data/analyticsLabels';
import { dashboard } from '@/routes';
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
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">Dashboard</h1>
            <nav class="flex items-center gap-2" aria-label="Time window">
                <Button
                    v-for="value in WINDOW_VALUES"
                    :key="value"
                    as-child
                    variant="outline"
                    size="sm"
                    :class="
                        value === props.statistics.window ? 'bg-accent' : ''
                    "
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
        <Card class="gap-3 p-6">
            <h2 class="text-sm font-medium">Deliveries</h2>
            <dl class="flex flex-col gap-1">
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
                <div class="mt-4">
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

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
        </div>
        <div
            class="relative min-h-[100vh] flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border"
        >
            <PlaceholderPattern />
        </div>
    </div>
</template>
