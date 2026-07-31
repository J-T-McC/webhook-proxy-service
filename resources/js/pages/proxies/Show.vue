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
import destinationRoutes from '@/routes/proxies/destinations';
import type { Team } from '@/types';
import type { ProxyDestination, ProxyDetail } from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyDetail;
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
        ],
    }),
});

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
const isLastDestination = computed(() => props.proxy.destinations.length <= 1);

const destinationTarget = ref<ProxyDestination | null>(null);
const proxyDeleteOpen = ref(false);
const busy = ref(false);

function confirmRemoveDestination(): void {
    const target = destinationTarget.value;

    if (!target) {
        return;
    }

    busy.value = true;

    router.delete(
        destinationRoutes.destroy({
            current_team: teamSlug.value,
            proxy: props.proxy.id,
            destination: target.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                destinationTarget.value = null;
            },
        },
    );
}

function confirmDeleteProxy(): void {
    busy.value = true;

    router.delete(
        proxyRoutes.destroy({ current_team: teamSlug.value, proxy: props.proxy.id }).url,
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
                    {{ props.proxy.mode === 'enhanced' ? 'Enhanced' : 'Simple' }}
                </Badge>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" as-child>
                    <Link :href="proxyRoutes.edit({ current_team: teamSlug, proxy: props.proxy.id })">
                        Edit
                    </Link>
                </Button>
                <Button
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
            <p class="text-muted-foreground text-sm">
                Anyone with this URL can post webhooks to this proxy. Keep it secret.
            </p>
        </Card>

        <!-- Destinations card -->
        <Card class="gap-4 p-6">
            <h2 class="text-sm font-medium">Destinations</h2>
            <p
                v-if="isLastDestination"
                id="last-destination-hint"
                class="text-muted-foreground text-sm"
            >
                A proxy must keep at least one destination.
            </p>
            <ul class="divide-y">
                <li
                    v-for="destination in props.proxy.destinations"
                    :key="destination.id"
                    class="flex items-center justify-between gap-3 py-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <Badge variant="outline">{{ destination.http_method }}</Badge>
                        <span class="truncate font-mono text-sm">{{ destination.url }}</span>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="isLastDestination"
                        :aria-label="`Remove destination ${destination.url}`"
                        :aria-describedby="isLastDestination ? 'last-destination-hint' : undefined"
                        @click="destinationTarget = destination"
                    >
                        Remove
                    </Button>
                </li>
            </ul>
        </Card>
    </div>

    <!-- Remove destination confirmation -->
    <AlertDialog
        :open="destinationTarget !== null"
        @update:open="(value) => { if (!value) destinationTarget = null; }"
    >
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Remove this destination?</AlertDialogTitle>
                <AlertDialogDescription>
                    Webhooks will no longer be delivered to
                    {{ destinationTarget?.url }}.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    class="bg-destructive text-white hover:bg-destructive/90"
                    :disabled="busy"
                    @click="confirmRemoveDestination"
                >
                    Remove destination
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

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
