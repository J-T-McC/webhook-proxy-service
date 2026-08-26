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
import {
    proxyAggregateDeliveryState,
    proxyAggregateDeliveryStateOption,
} from '@/data/proxyDeliveryStates';
import { proxyPayloadStateOption } from '@/data/proxyPayloadStates';
import { formatByteSize, formatTimestamp } from '@/lib/format';
import proxyRoutes from '@/routes/proxies';
import proxyEventRoutes from '@/routes/proxies/events';
import type { Team } from '@/types';
import type {
    Paginated,
    ProxyDetail,
    ProxyPermissions,
    WebhookEventListItem,
} from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyDetail;
    events: Paginated<WebhookEventListItem>;
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

        <!-- Empty state -->
        <Card
            v-if="props.events.data.length === 0"
            class="items-center gap-3 p-10 text-center"
        >
            <h2 class="text-lg font-medium">No events yet</h2>
            <p class="text-sm text-muted-foreground">
                Events appear here once this proxy's ingest URL receives a
                webhook.
            </p>
            <Button variant="outline" as-child class="mt-2">
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
