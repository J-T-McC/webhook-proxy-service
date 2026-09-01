<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import DestinationRows from '@/components/DestinationRows.vue';
import InputError from '@/components/InputError.vue';
import DeliveryFieldset from '@/components/proxies/form/DeliveryFieldset.vue';
import DetailsFieldset from '@/components/proxies/form/DetailsFieldset.vue';
import ResponseFieldset from '@/components/proxies/form/ResponseFieldset.vue';
import SensitiveFieldsFieldset from '@/components/proxies/form/SensitiveFieldsFieldset.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { TooltipProvider } from '@/components/ui/tooltip';
import { proxyFormPayload } from '@/lib/proxyFormPayload';
import type { ProxySecurity } from '@/types/proxies';
import type { ProxyFormInitial } from '@/types/proxyForm';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    /** The fixed AC12 default list (T11) — rendered literally, one badge per
     * entry, never summarised (Screen 2, correction C4). */
    defaultSensitiveFieldNames: string[];
    /** The `security` prop (T22) — absent on Create (no proxy resource exists
     * yet, plan-10 Technical ruling 3), present on Edit. Screen 3's credential
     * subsection reads it. */
    security?: ProxySecurity | null;
    initial: ProxyFormInitial;
}>();

const form = useForm({
    name: props.initial.name,
    mode: props.initial.mode,
    processing_mode: props.initial.processingMode,
    response_status: props.initial.responseStatus?.toString() ?? '',
    response_body: props.initial.responseBody ?? '',
    retry_attempt_limit: props.initial.retryAttemptLimit?.toString() ?? '',
    retry_backoff_strategy: props.initial.retryBackoffStrategy ?? '',
    sensitive_fields: [...props.initial.sensitiveFields],
    // Screen 3 (T30): the header name defaults to 'Authorization' for a row
    // with no credential of its own (design-10: "New row, this session" /
    // "Existing, no credential" both read Header name (Authorization)); the
    // secret is always write-only (never pre-filled — there is nothing to
    // pre-fill it with, AC33) regardless of whether this row already has a
    // credential.
    destinations: props.initial.destinations.map((row) => ({
        ...row,
        credential_header_name: row.credential_header_name ?? 'Authorization',
        credential_secret: '',
    })),
});

const formEl = ref<HTMLFormElement | null>(null);

function submit(): void {
    form.transform(proxyFormPayload).submit(props.method, props.action, {
        preserveScroll: true,
        onError: () => {
            // Move focus to the first field in error (name, a response field, or
            // a destination row).
            nextTick(() => {
                formEl.value
                    ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                    ?.focus();
            });
        },
    });
}
</script>

<template>
    <form ref="formEl" class="w-full" @submit.prevent="submit">
        <TooltipProvider>
            <div class="space-y-6">
                <DetailsFieldset
                    v-model="form.name"
                    :error="form.errors.name"
                    :disabled="form.processing"
                />

                <ResponseFieldset
                    v-model:status="form.response_status"
                    v-model:body="form.response_body"
                    :status-error="form.errors.response_status"
                    :body-error="form.errors.response_body"
                    :disabled="form.processing"
                />

                <DeliveryFieldset
                    v-model:mode="form.mode"
                    v-model:processing-mode="form.processing_mode"
                    v-model:retry-attempt-limit="form.retry_attempt_limit"
                    v-model:retry-backoff-strategy="form.retry_backoff_strategy"
                    :initial-mode="props.initial.mode"
                    :initial-retry-attempt-limit="
                        props.initial.retryAttemptLimit
                    "
                    :initial-retry-backoff-strategy="
                        props.initial.retryBackoffStrategy
                    "
                    :errors="form.errors"
                    :disabled="form.processing"
                />

                <SensitiveFieldsFieldset
                    v-model="form.sensitive_fields"
                    :default-names="props.defaultSensitiveFieldNames"
                    :error="form.errors.sensitive_fields"
                    :disabled="form.processing"
                />

                <!-- Destinations -->
                <Card class="gap-6 p-6">
                    <DestinationRows
                        v-model="form.destinations"
                        :errors="form.errors"
                        :disabled="form.processing"
                        :security="props.security?.destinations"
                    />
                    <InputError :message="form.errors.destinations" />
                </Card>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ submitLabel }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="cancelHref">Cancel</Link>
                </Button>
            </div>
        </TooltipProvider>
    </form>
</template>
