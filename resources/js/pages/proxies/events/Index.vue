<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import ReplayDialog from '@/components/ReplayDialog.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { windowLabel } from '@/data/analyticsLabels';
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

// --- Filter chips (T24; AC10, AC21; design-11 Screen 4) ---------------------
//
// A chip row (window + destination + outcome, up to three at once) renders
// only once `destination` or `outcome` actually resolved — an arrival with
// neither is "arrived directly," visually identical to the pre-#11 shipped
// surface (T21's own reading of AC28), so no chip row renders there either.

const hasActiveFilters = computed(
    () => props.filters.destination !== null || props.filters.outcome !== null,
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
 * source of truth for what's shown.
 */
function filterHref(remove: 'window' | 'destination' | 'outcome' | 'all') {
    const query: Record<string, string | number> = {};

    if (remove !== 'window' && remove !== 'all') {
        query.window = props.filters.window;
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
</script>

<template>
    <Head :title="`Events for ${props.proxy.name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">
            Events for &ldquo;{{ props.proxy.name }}&rdquo;
        </h1>

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

        <!-- Filter chips (T24) — window, destination and/or outcome, up to
             three at once (design-11 Screen 4). Renders only once a
             drill-through actually narrowed the list (`destination` or
             `outcome` resolved); an unfiltered arrival is visually
             identical to today (AC28). -->
        <div
            v-if="hasActiveFilters"
            class="flex flex-wrap items-center gap-2"
            aria-label="Active filters"
        >
            <Badge variant="secondary" class="gap-1.5 py-1 pr-1.5 pl-2.5">
                Window: last {{ windowLabel(props.filters.window) }}
                <button
                    type="button"
                    aria-label="Remove window filter"
                    class="rounded-full opacity-70 hover:opacity-100"
                    @click="router.get(filterHref('window'))"
                >
                    ×
                </button>
            </Badge>
            <Badge
                v-if="props.filters.destination"
                variant="secondary"
                class="gap-1.5 py-1 pr-1.5 pl-2.5"
            >
                Destination: {{ props.filters.destination.httpMethod }}
                {{ props.filters.destination.url }}
                <button
                    type="button"
                    :aria-label="`Remove destination filter: ${props.filters.destination.url}`"
                    class="rounded-full opacity-70 hover:opacity-100"
                    @click="router.get(filterHref('destination'))"
                >
                    ×
                </button>
            </Badge>
            <Badge
                v-if="props.filters.outcome"
                variant="secondary"
                class="gap-1.5 py-1 pr-1.5 pl-2.5"
            >
                Outcome: {{ props.filters.outcome.label }}
                <button
                    type="button"
                    aria-label="Remove outcome filter"
                    class="rounded-full opacity-70 hover:opacity-100"
                    @click="router.get(filterHref('outcome'))"
                >
                    ×
                </button>
            </Badge>
        </div>
        <p v-if="outcomeExplanatoryLine" class="text-sm text-muted-foreground">
            {{ outcomeExplanatoryLine }}
        </p>

        <!-- Empty state -->
        <Card
            v-if="props.events.data.length === 0"
            class="items-center gap-3 p-10 text-center"
        >
            <h2 class="text-lg font-medium">
                {{
                    hasActiveFilters
                        ? 'No events match these filters'
                        : 'No events yet'
                }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    hasActiveFilters
                        ? 'Remove a filter above, or clear them all, to see more events.'
                        : "Events appear here once this proxy's ingest URL receives a webhook."
                }}
            </p>
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
        </Card>

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

            <!-- Pagination -->
            <nav
                v-if="props.events.last_page > 1"
                class="flex flex-wrap gap-1"
                aria-label="Pagination"
            >
                <Button
                    v-for="link in props.events.links"
                    :key="link.label"
                    variant="outline"
                    size="sm"
                    :disabled="!link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    :class="link.active ? 'bg-accent' : ''"
                    @click="link.url && router.get(link.url)"
                >
                    <span v-html="link.label" />
                </Button>
            </nav>
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
