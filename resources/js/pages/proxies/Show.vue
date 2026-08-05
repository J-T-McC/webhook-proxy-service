<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CopyField from '@/components/CopyField.vue';
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
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';
import type { ProxyDetail, ProxyPermissions } from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyDetail;
    permissions: ProxyPermissions;
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
        ],
    }),
});

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

// Response card — read-only view of the upstream acknowledgement contract.
// Status labels copied verbatim from ProxyForm.vue's SelectItem labels so the
// same status reads identically on the edit form and the detail page.
const responseStatusLabel = computed(() => {
    switch (props.proxy.response_status) {
        case 200:
            return '200 OK';
        case 202:
            return '202 Accepted';
        case 204:
            return '204 No Content';
        default:
            return 'Default (202 Accepted)';
    }
});

// A real body block renders only for 200/202 with a non-empty string; every
// other case (unconfigured, 204, or an empty/null body) shows muted text.
const hasResponseBody = computed(
    () =>
        (props.proxy.response_status === 200 ||
            props.proxy.response_status === 202) &&
        props.proxy.response_body !== null &&
        props.proxy.response_body !== '',
);

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

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">{{ props.proxy.name }}</h1>
                <Badge variant="secondary">
                    {{
                        props.proxy.mode === 'enhanced' ? 'Enhanced' : 'Simple'
                    }}
                </Badge>
            </div>
            <div class="flex items-center gap-2">
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

        <!-- Ingest URL card -->
        <Card class="gap-3 p-6">
            <h2 class="text-sm font-medium">Ingest URL</h2>
            <CopyField :value="props.proxy.ingest_url" />
            <p class="text-sm text-muted-foreground">
                Anyone with this URL can post webhooks to this proxy. Keep it
                secret.
            </p>
        </Card>

        <!-- Response card -->
        <Card class="gap-3 p-6">
            <h2 class="text-sm font-medium">Response</h2>
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
                            v-else-if="props.proxy.response_status === 204"
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

        <!-- Destinations card -->
        <Card class="gap-4 p-6">
            <h2 class="text-sm font-medium">Destinations</h2>
            <ul class="divide-y">
                <li
                    v-for="destination in props.proxy.destinations"
                    :key="destination.id"
                    class="flex items-center gap-3 py-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <Badge variant="outline">{{
                            destination.http_method
                        }}</Badge>
                        <span class="truncate font-mono text-sm">{{
                            destination.url
                        }}</span>
                    </div>
                </li>
            </ul>
        </Card>
    </div>

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
