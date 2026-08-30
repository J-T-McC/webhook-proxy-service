<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CopyField from '@/components/CopyField.vue';
import EmptyState from '@/components/EmptyState.vue';
import Pagination from '@/components/Pagination.vue';
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
import { useTeamSlug } from '@/composables/useTeamSlug';
import { proxyProcessingModeLabel } from '@/data/proxyProcessingModes';
import { proxiesCrumb } from '@/lib/breadcrumbs';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';
import type {
    Paginated,
    ProxyListItem,
    ProxyPermissions,
} from '@/types/proxies';

const props = defineProps<{
    proxies: Paginated<ProxyListItem>;
    permissions: ProxyPermissions;
}>();

// Affordances derive client-side from the shared page-level permissions + each
// row's is_creator flag (ADR-009 Amendment B5) — no per-row policy call. The
// server ProxyPolicy still enforces the mutation.
function canUpdate(proxy: ProxyListItem): boolean {
    return (
        props.permissions.canUpdateProxy &&
        (proxy.is_creator || props.permissions.canUpdateAnyProxy)
    );
}

function canDelete(proxy: ProxyListItem): boolean {
    return (
        props.permissions.canDeleteProxy &&
        (proxy.is_creator || props.permissions.canDeleteAnyProxy)
    );
}

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [proxiesCrumb(props.currentTeam)],
    }),
});

const teamSlug = useTeamSlug();

const deleteTarget = ref<ProxyListItem | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);

function requestDelete(proxy: ProxyListItem): void {
    deleteTarget.value = proxy;
    deleteOpen.value = true;
}

function confirmDelete(): void {
    const target = deleteTarget.value;

    if (!target) {
        return;
    }

    deleting.value = true;

    router.delete(
        proxyRoutes.destroy({ current_team: teamSlug.value, proxy: target.id })
            .url,
        {
            preserveScroll: true,
            onFinish: () => {
                deleting.value = false;
                deleteOpen.value = false;
                deleteTarget.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Proxies" />

    <div class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Proxies</h1>
            <Button v-if="permissions.canCreateProxy" as-child>
                <Link :href="proxyRoutes.create(teamSlug)">New proxy</Link>
            </Button>
        </div>

        <EmptyState
            v-if="proxies.data.length === 0"
            title="No proxies yet"
            description="Create a proxy to get an ingest URL and start fanning out webhooks."
        >
            <Button v-if="permissions.canCreateProxy" as-child class="mt-2">
                <Link :href="proxyRoutes.create(teamSlug)"
                    >Create your first proxy</Link
                >
            </Button>
        </EmptyState>

        <template v-else>
            <p class="text-sm text-muted-foreground">
                These ingest URLs are secrets — anyone with one can post
                webhooks.
            </p>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Mode</TableHead>
                        <TableHead>Processing</TableHead>
                        <TableHead>Ingest URL</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="proxy in proxies.data" :key="proxy.id">
                        <TableCell class="font-medium">
                            <div class="flex items-center gap-2">
                                <Link
                                    :href="
                                        proxyRoutes.show({
                                            current_team: teamSlug,
                                            proxy: proxy.id,
                                        })
                                    "
                                    class="hover:underline"
                                >
                                    {{ proxy.name }}
                                </Link>
                                <Badge
                                    v-if="proxy.paused_at"
                                    variant="waitingOutline"
                                >
                                    Paused
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell>
                            <Badge variant="secondary">
                                {{
                                    proxy.mode === 'enhanced'
                                        ? 'Enhanced'
                                        : 'Simple'
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <Badge variant="secondary">
                                {{
                                    proxyProcessingModeLabel(
                                        proxy.processing_mode,
                                    )
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell class="min-w-[18rem]">
                            <CopyField
                                :value="proxy.ingest_url"
                                :copy-label="`Copy ingest URL for ${proxy.name}`"
                            />
                        </TableCell>
                        <TableCell>
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link
                                        :href="
                                            proxyRoutes.show({
                                                current_team: teamSlug,
                                                proxy: proxy.id,
                                            })
                                        "
                                    >
                                        View
                                    </Link>
                                </Button>
                                <Button
                                    v-if="canUpdate(proxy)"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link
                                        :href="
                                            proxyRoutes.edit({
                                                current_team: teamSlug,
                                                proxy: proxy.id,
                                            })
                                        "
                                    >
                                        Edit
                                    </Link>
                                </Button>
                                <Button
                                    v-if="canDelete(proxy)"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete proxy ${proxy.name}`"
                                    @click="requestDelete(proxy)"
                                >
                                    Delete
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <Pagination :links="proxies.links" :last-page="proxies.last_page" />
        </template>
    </div>

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="`Delete “${deleteTarget?.name}”?`"
        description="Its ingest URL will stop accepting webhooks and all its destinations are removed. This cannot be undone."
        confirm-label="Delete proxy"
        destructive
        :busy="deleting"
        @confirm="confirmDelete"
    />
</template>
