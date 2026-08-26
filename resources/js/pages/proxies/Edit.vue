<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProxyForm from '@/pages/proxies/ProxyForm.vue';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';
import type { ProxyFormProxy } from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyFormProxy;
}>();

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

defineOptions({
    layout: (options: {
        currentTeam?: Team | null;
        proxy: ProxyFormProxy;
    }) => ({
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
                processingMode: props.proxy.processing_mode,
                responseStatus: props.proxy.response_status,
                responseBody: props.proxy.response_body,
                retryAttemptLimit: props.proxy.retry_attempt_limit,
                retryBackoffStrategy: props.proxy.retry_backoff_strategy,
                destinations: props.proxy.destinations,
            }"
        />
    </div>
</template>
