<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { RefreshCw, RefreshCwOff } from '@lucide/vue';
import { computed } from 'vue';
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
import { useAutoRefreshPolling } from '@/composables/useAutoRefreshPolling';
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

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

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

            <Button
                type="button"
                variant="ghost"
                size="icon"
                :aria-pressed="pollingEnabled"
                :aria-label="
                    pollingEnabled
                        ? 'Turn off auto-refresh'
                        : 'Turn on auto-refresh'
                "
                :title="
                    pollingEnabled
                        ? 'Auto-refreshing every 5 seconds'
                        : 'Auto-refresh is off'
                "
                @click="togglePolling"
            >
                <RefreshCw v-if="pollingEnabled" class="size-4" />
                <RefreshCwOff v-else class="size-4 text-muted-foreground" />
            </Button>
        </div>

        <!-- Empty state -->
        <Card
            v-if="props.events.data.length === 0"
            class="items-center gap-3 p-10 text-center"
        >
            <h2 class="text-lg font-medium">No events yet</h2>
            <p class="text-sm text-muted-foreground">
                Events appear here once any of your team's proxies receive a
                webhook.
            </p>
        </Card>

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

            <!-- Pagination -->
            <nav
                v-if="props.events.last_page > 1"
                class="flex flex-wrap gap-1"
                aria-label="Pagination"
            >
                <Button
                    v-for="link in props.events.links"
                    :key="link.label"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    :disabled="!link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    @click="link.url && router.get(link.url)"
                >
                    <span v-html="link.label" />
                </Button>
            </nav>
        </template>
    </div>
</template>
