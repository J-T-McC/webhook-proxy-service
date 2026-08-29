<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProxyForm from '@/pages/proxies/ProxyForm.vue';
import proxyRoutes from '@/routes/proxies';
import type { Team } from '@/types';
import type { ProxyFormProxy, ProxySecurity } from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyFormProxy;
    /** The fixed AC12 default list, single-sourced from
     * `SensitiveFields::DEFAULTS` (T11) — rendered literally, never summarised. */
    defaultSensitiveFieldNames: string[];
    /** The `security` prop (T22) — status only, never a value/length. */
    security: ProxySecurity;
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

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
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
            :default-sensitive-field-names="props.defaultSensitiveFieldNames"
            :security="props.security"
            :initial="{
                name: props.proxy.name,
                mode: props.proxy.mode,
                processingMode: props.proxy.processing_mode,
                responseStatus: props.proxy.response_status,
                responseBody: props.proxy.response_body,
                retryAttemptLimit: props.proxy.retry_attempt_limit,
                retryBackoffStrategy: props.proxy.retry_backoff_strategy,
                sensitiveFields: props.proxy.sensitive_fields,
                destinations: props.proxy.destinations,
            }"
        />
    </div>
</template>
