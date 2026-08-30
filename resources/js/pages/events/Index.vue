<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AutoRefreshToggle from '@/components/AutoRefreshToggle.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAutoRefreshPolling } from '@/composables/useAutoRefreshPolling';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { webhookQueueStatusOption } from '@/data/webhookQueueStates';
import { formatByteSize, formatTimestamp } from '@/lib/format';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';
import type { Paginated, WebhookEventQueueItem } from '@/types/proxies';

const props = defineProps<{
    events: Paginated<WebhookEventQueueItem>;
}>();

defineOptions({
    layout: (options: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Event queue',
                href: options.currentTeam ? '#' : '/',
            },
        ],
    }),
});

const teamSlug = useTeamSlug();

const { pollingEnabled, togglePolling } = useAutoRefreshPolling(
    'event-queue:polling',
    ['events'],
);
</script>

<template>
    <Head title="Event queue" />

    <div class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <h1 class="text-xl font-semibold">Event queue</h1>

            <AutoRefreshToggle
                :enabled="pollingEnabled"
                @toggle="togglePolling"
            />
        </div>

        <EmptyState
            v-if="props.events.data.length === 0"
            title="No events yet"
            description="Events appear here once any of your team's proxies receive a webhook."
        />

        <template v-else>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Received</TableHead>
                        <TableHead>Proxy</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Content type</TableHead>
                        <TableHead>Size</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="event in props.events.data"
                        :key="event.id"
                    >
                        <TableCell class="font-medium">
                            {{ formatTimestamp(event.received_at) }}
                        </TableCell>
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <Link
                                    :href="
                                        proxyRoutes.show({
                                            current_team: teamSlug,
                                            proxy: event.proxy.id,
                                        })
                                    "
                                    class="hover:underline"
                                >
                                    {{
                                        event.proxy.name ??
                                        `Proxy #${event.proxy.id}`
                                    }}
                                </Link>
                                <Badge
                                    v-if="event.proxy.paused"
                                    variant="waitingOutline"
                                >
                                    Paused
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    webhookQueueStatusOption(event.status)
                                        .variant
                                "
                            >
                                {{
                                    webhookQueueStatusOption(event.status).label
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ event.content_type ?? '—' }}</TableCell>
                        <TableCell>{{
                            formatByteSize(event.byte_size)
                        }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <Pagination
                :links="props.events.links"
                :last-page="props.events.last_page"
            />
        </template>
    </div>
</template>
