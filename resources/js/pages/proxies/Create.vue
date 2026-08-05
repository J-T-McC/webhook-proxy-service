<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProxyForm from '@/pages/proxies/ProxyForm.vue';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Proxies',
                href: props.currentTeam
                    ? proxyRoutes.index(props.currentTeam.slug)
                    : '/',
            },
            {
                title: 'New proxy',
                href: props.currentTeam
                    ? proxyRoutes.create(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head title="New proxy" />

    <div class="p-4">
        <ProxyForm
            method="post"
            :action="proxyRoutes.store(teamSlug).url"
            submit-label="Create proxy"
            :cancel-href="proxyRoutes.index(teamSlug).url"
            :initial="{
                name: '',
                mode: 'simple',
                responseStatus: null,
                responseBody: null,
                destinations: [{ url: '', http_method: 'POST' }],
            }"
        />
    </div>
</template>
