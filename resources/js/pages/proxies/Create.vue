<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { proxiesCrumb } from '@/lib/breadcrumbs';
import ProxyForm from '@/pages/proxies/ProxyForm.vue';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';

const props = defineProps<{
    /** The fixed AC12 default list, single-sourced from
     * `SensitiveFields::DEFAULTS` (T11) — rendered literally, never summarised. */
    defaultSensitiveFieldNames: string[];
}>();

const teamSlug = useTeamSlug();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            proxiesCrumb(props.currentTeam),
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

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
        <ProxyForm
            method="post"
            :action="proxyRoutes.store(teamSlug).url"
            submit-label="Create proxy"
            :cancel-href="proxyRoutes.index(teamSlug).url"
            :default-sensitive-field-names="props.defaultSensitiveFieldNames"
            :initial="{
                name: '',
                mode: 'simple',
                processingMode: 'async',
                responseStatus: null,
                responseBody: null,
                retryAttemptLimit: null,
                retryBackoffStrategy: null,
                sensitiveFields: [],
                destinations: [{ url: '', http_method: 'POST' }],
            }"
        />
    </div>
</template>
