<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProxyForm from '@/pages/proxies/ProxyForm.vue';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';
import type { DestinationRow, ProxyMode } from '@/types/proxies';

interface EditProxy {
    id: number;
    name: string;
    mode: ProxyMode;
    destinations: DestinationRow[];
}

const props = defineProps<{
    proxy: EditProxy;
}>();

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

defineOptions({
    layout: (options: { currentTeam?: Team | null; proxy: EditProxy }) => ({
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
                title: 'Edit',
                href: options.currentTeam
                    ? proxyRoutes.edit({
                          current_team: options.currentTeam.slug,
                          proxy: options.proxy.id,
                      })
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head :title="`Edit ${props.proxy.name}`" />

    <div class="p-4">
        <ProxyForm
            method="put"
            :action="
                proxyRoutes.update({
                    current_team: teamSlug,
                    proxy: props.proxy.id,
                }).url
            "
            submit-label="Save changes"
            :cancel-href="
                proxyRoutes.show({
                    current_team: teamSlug,
                    proxy: props.proxy.id,
                }).url
            "
            :initial="{
                name: props.proxy.name,
                mode: props.proxy.mode,
                destinations: props.proxy.destinations,
            }"
        />
    </div>
</template>
