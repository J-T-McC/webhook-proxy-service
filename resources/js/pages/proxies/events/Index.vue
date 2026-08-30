<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import AutoRefreshToggle from '@/components/AutoRefreshToggle.vue';
import EmptyState from '@/components/EmptyState.vue';
import EventFilterChips from '@/components/events/EventFilterChips.vue';
import Pagination from '@/components/Pagination.vue';
import ReplayDialog from '@/components/ReplayDialog.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAutoRefreshPolling } from '@/composables/useAutoRefreshPolling';
import {
    proxyAggregateDeliveryState,
    proxyAggregateDeliveryStateOption,
} from '@/data/proxyDeliveryStates';
import { proxyPayloadStateOption } from '@/data/proxyPayloadStates';
import { formatByteSize, formatTimestamp } from '@/lib/format';
import proxyRoutes from '@/routes/proxies';
import proxyEventRoutes from '@/routes/proxies/events';
import type { Team } from '@/types';
import type { EventListFilters } from '@/types/analytics';
import type {
    Paginated,
    ProxyDetail,
    ProxyPermissions,
    WebhookEventListItem,
} from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyDetail;
    events: Paginated<WebhookEventListItem>;
    filters: EventListFilters;
    permissions: ProxyPermissions;
    fifoHeldByRetry: boolean;
}>();

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
            {
                title: 'Events',
                href: options.currentTeam
                    ? proxyEventRoutes.index({
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

// --- Filter chips (T24; T23/T24 Revision A, `Q-11-04`; AC10, AC21;
// design-11 Screen 4) --------------------------------------------------------
//
// A chip row (window + destination + outcome, up to three at once) renders
// only once `destination`, `outcome` or `day` actually resolved — an arrival
// with none of the three is "arrived directly," visually identical to the
// pre-#11 shipped surface (T21's own reading of AC28), so no chip row
// renders there either. A resolved `day` is **not** a fourth chip — plan
// Technical ruling 10 renders it as the value of the existing Window chip
// (see `windowChipValue` below), so the chip row stays fixed at three.

const hasActiveFilters = computed(
    () =>
        props.filters.destination !== null ||
        props.filters.outcome !== null ||
        props.filters.day !== null,
);

/** The `outcome` query token this filter's resolved `unit` came from (T21). */
function outcomeQueryToken(unit: string): string {
    return unit === 'delivery' ? 'delivery_failed' : 'attempt_failed';
}

/**
 * Rebuilds the Events list URL from the currently active filters, dropping
 * exactly the one named — a chip's remove control is a real re-navigation
 * (design-11 § Interactions: "not a client-side row filter"), never
 * client-side state, so the server-side query and the URL stay the single
 * source of truth for what's shown. Removing `'window'` drops `date`
 * alongside it — the day-narrowed Window chip's `×` removes both together
 * (ruling 10), since a resolved day is that chip's value, not a filter of
 * its own.
 */
function filterHref(remove: 'window' | 'destination' | 'outcome' | 'all') {
    const query: Record<string, string | number> = {};

    if (remove !== 'window' && remove !== 'all') {
        query.window = props.filters.window;

        if (props.filters.day) {
            query.date = props.filters.day;
        }
    }

    if (
        remove !== 'destination' &&
        remove !== 'all' &&
        props.filters.destination
    ) {
        query.destination = props.filters.destination.id;
    }

    if (remove !== 'outcome' && remove !== 'all' && props.filters.outcome) {
        query.outcome = outcomeQueryToken(props.filters.outcome.unit);
    }

    return proxyEventRoutes.index(
        { current_team: teamSlug.value, proxy: props.proxy.id },
        { query },
    ).url;
}

/**
 * The explanatory line shown only while an Outcome chip is active (C1(b)) —
 * the filtered list shows the **events containing** a matching delivery or
 * attempt, not one row per counted delivery or attempt, so a member is never
 * misled into expecting the row count to equal the figure they drilled from.
 */
const outcomeExplanatoryLine = computed(() => {
    if (props.filters.outcome === null) {
        return null;
    }

    const noun =
        props.filters.outcome.unit === 'attempt' ? 'attempt' : 'delivery';

    return `Showing events with at least one matching ${noun} — one event can hold more than one, so this list's row count won't match the figure's count exactly.`;
});

function aggregateDeliveryBadge(event: WebhookEventListItem) {
    const state = proxyAggregateDeliveryState(
        event.deliveries.map((delivery) => delivery.status),
    );

    return proxyAggregateDeliveryStateOption(state);
}

function canReplay(event: WebhookEventListItem): boolean {
    return (
        event.payload_state === 'retained' && props.permissions.canReplayProxy
    );
}

const replayDialogOpen = ref(false);
const replayEventId = ref<number | null>(null);

function openReplay(event: WebhookEventListItem): void {
    replayEventId.value = event.id;
    replayDialogOpen.value = true;
}

// Skipping a tick while the replay dialog is open is deliberate: refreshing
// the rows underneath it can change what the user is about to act on.
const { pollingEnabled, togglePolling } = useAutoRefreshPolling(
    'proxy-events:polling',
    ['events', 'fifoHeldByRetry'],
    () => replayDialogOpen.value,
);
</script>

<template>
    <Head :title="`Events for ${props.proxy.name}`" />

    <div class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <h1 class="text-xl font-semibold">
                Events for &ldquo;{{ props.proxy.name }}&rdquo;
            </h1>

            <AutoRefreshToggle
                :enabled="pollingEnabled"
                @toggle="togglePolling"
            />
        </div>

        <Alert
            v-if="props.fifoHeldByRetry"
            class="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400"
        >
            <Info class="size-4" />
            <AlertDescription class="text-blue-900 dark:text-blue-100">
                This proxy is FIFO. An event is currently retrying and holding
                the line — newer events wait until it succeeds or is set aside
                after its retry limit.
            </AlertDescription>
        </Alert>

        <!-- Renders only once a drill-through actually narrowed the list
             (`destination`, `outcome` or `day` resolved); an unfiltered
             arrival is visually identical to today (AC28). -->
        <EventFilterChips
            v-if="hasActiveFilters"
            :filters="props.filters"
            :href-for="filterHref"
        />
        <p v-if="outcomeExplanatoryLine" class="text-sm text-muted-foreground">
            {{ outcomeExplanatoryLine }}
        </p>

        <EmptyState
            v-if="props.events.data.length === 0"
            :title="
                hasActiveFilters
                    ? 'No events match these filters'
                    : 'No events yet'
            "
            :description="
                hasActiveFilters
                    ? 'Remove a filter above, or clear them all, to see more events.'
                    : `Events appear here once this proxy's ingest URL receives a webhook.`
            "
        >
            <Button
                v-if="hasActiveFilters"
                variant="outline"
                as-child
                class="mt-2"
            >
                <Link :href="filterHref('all')">Clear filters</Link>
            </Button>
            <Button v-else variant="outline" as-child class="mt-2">
                <Link
                    :href="
                        proxyRoutes.show({
                            current_team: teamSlug,
                            proxy: props.proxy.id,
                        })
                    "
                >
                    View ingest URL
                </Link>
            </Button>
        </EmptyState>

        <template v-else>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Received</TableHead>
                        <TableHead>Size</TableHead>
                        <TableHead>Content type</TableHead>
                        <TableHead>Payload</TableHead>
                        <TableHead>Delivery</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="event in props.events.data"
                        :key="event.id"
                    >
                        <TableCell class="font-medium">
                            <Link
                                :href="
                                    proxyEventRoutes.show({
                                        current_team: teamSlug,
                                        proxy: props.proxy.id,
                                        event: event.id,
                                    })
                                "
                                class="hover:underline"
                            >
                                {{ formatTimestamp(event.received_at) }}
                            </Link>
                        </TableCell>
                        <TableCell>{{
                            formatByteSize(event.byte_size)
                        }}</TableCell>
                        <TableCell>{{ event.content_type ?? '—' }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    proxyPayloadStateOption(event.payload_state)
                                        .variant
                                "
                            >
                                {{
                                    proxyPayloadStateOption(event.payload_state)
                                        .label
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="aggregateDeliveryBadge(event).variant"
                            >
                                {{ aggregateDeliveryBadge(event).label }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link
                                        :href="
                                            proxyEventRoutes.show({
                                                current_team: teamSlug,
                                                proxy: props.proxy.id,
                                                event: event.id,
                                            })
                                        "
                                    >
                                        View
                                    </Link>
                                </Button>
                                <Button
                                    v-if="canReplay(event)"
                                    variant="ghost"
                                    size="sm"
                                    @click="openReplay(event)"
                                >
                                    Replay
                                </Button>
                                <span
                                    v-else-if="
                                        event.payload_state === 'cleaned'
                                    "
                                    class="text-sm text-muted-foreground"
                                >
                                    Expired
                                </span>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <Pagination
                :links="props.events.links"
                :last-page="props.events.last_page"
            />
        </template>
    </div>

    <ReplayDialog
        v-model:open="replayDialogOpen"
        :team-slug="teamSlug"
        :proxy-id="props.proxy.id"
        :destinations="props.proxy.destinations"
        :is-fifo="props.proxy.processing_mode === 'fifo'"
        :event-id="replayEventId"
    />
</template>
