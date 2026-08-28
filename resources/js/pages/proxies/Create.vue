<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProxyForm from '@/pages/proxies/ProxyForm.vue';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';

const props = defineProps<{
    /** The fixed AC12 default list, single-sourced from
     * `SensitiveFields::DEFAULTS` (T11) — rendered literally, never summarised. */
    defaultSensitiveFieldNames: string[];
    /** `StandardWebhooks::TOLERANCE_SECONDS` (T7), single-sourced (AC53). */
    standardWebhooksTolerance: number;
}>();

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
            :default-sensitive-field-names="props.defaultSensitiveFieldNames"
            :standard-webhooks-tolerance="props.standardWebhooksTolerance"
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
