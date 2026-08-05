<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import DestinationRows from '@/components/DestinationRows.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    PROXY_RESPONSE_STATUS_DEFAULT_LABEL,
    PROXY_RESPONSE_STATUSES,
    proxyStatusForcesEmptyBody,
} from '@/data/proxyResponseStatuses';
import type {
    DestinationRow,
    ProxyMode,
    ProxyResponseStatus,
} from '@/types/proxies';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    initial: {
        name: string;
        mode: ProxyMode;
        responseStatus: ProxyResponseStatus | null;
        responseBody: string | null;
        destinations: DestinationRow[];
    };
}>();

// The response fields are held as strings for the inputs; empty status means
// "unconfigured" and is normalised back to null on submit (below), so leaving it
// at the default persists NULL (the resolver then returns the default 202).
const form = useForm({
    name: props.initial.name,
    mode: props.initial.mode,
    response_status: props.initial.responseStatus?.toString() ?? '',
    response_body: props.initial.responseBody ?? '',
    destinations: props.initial.destinations.map((row) => ({ ...row })),
});

// Status is the closed set from PROXY_RESPONSE_STATUSES plus a "default" sentinel
// (the unconfigured state → 202). The sentinel maps to '' so submit still sends
// null.
const STATUS_DEFAULT = 'default';
const statusSelect = computed({
    get: () =>
        form.response_status === '' ? STATUS_DEFAULT : form.response_status,
    set: (value: string) => {
        form.response_status = value === STATUS_DEFAULT ? '' : value;
    },
});

// The selected status as its typed value (null when unconfigured); the select is
// closed to the shared set + the '' sentinel, so the numeric coercion is exact.
const selectedStatus = computed<ProxyResponseStatus | null>(() =>
    form.response_status === ''
        ? null
        : (Number(form.response_status) as ProxyResponseStatus),
);

// 204 = No Content couples to an empty body (AC12): selecting a status flagged
// emptyBody disables the body field and clears any previously entered body.
const bodyDisabled = computed(() =>
    proxyStatusForcesEmptyBody(selectedStatus.value),
);
watch(selectedStatus, (status) => {
    if (proxyStatusForcesEmptyBody(status)) {
        form.response_body = '';
    }
});

const formEl = ref<HTMLFormElement | null>(null);

function submit(): void {
    form.transform((data) => ({
        ...data,
        // Blank → null (unconfigured); a set status is sent as a number.
        response_status:
            data.response_status === '' ? null : Number(data.response_status),
        response_body: data.response_body === '' ? null : data.response_body,
    })).submit(props.method, props.action, {
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
    <form
        ref="formEl"
        class="mx-auto w-full max-w-2xl"
        @submit.prevent="submit"
    >
        <Card class="gap-6 p-6">
            <!-- Details -->
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    type="text"
                    placeholder="Stripe → billing services"
                    :disabled="form.processing"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                    aria-describedby="name-help name-error"
                />
                <p id="name-help" class="text-sm text-muted-foreground">
                    A name to recognise this proxy.
                </p>
                <span id="name-error">
                    <InputError :message="form.errors.name" />
                </span>
            </div>

            <div class="grid gap-2">
                <Label for="mode">Mode</Label>
                <Select v-model="form.mode" :disabled="form.processing">
                    <SelectTrigger id="mode" class="w-full sm:w-64">
                        <SelectValue placeholder="Select a mode" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="simple">Simple</SelectItem>
                        <SelectItem value="enhanced">Enhanced</SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-sm text-muted-foreground">
                    Enhanced-mode behaviours (mapping, storage, retries) are not
                    yet functional; Simple delivers the webhook to every
                    destination.
                </p>
                <InputError :message="form.errors.mode" />
            </div>

            <!-- Upstream response (acknowledgement, returned before delivery) -->
            <div class="grid gap-2">
                <Label for="response_status">Response status code</Label>
                <Select v-model="statusSelect" :disabled="form.processing">
                    <SelectTrigger
                        id="response_status"
                        class="w-full sm:w-64"
                        :aria-invalid="
                            form.errors.response_status ? 'true' : undefined
                        "
                        aria-describedby="response-status-help response-status-error"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="STATUS_DEFAULT">
                            {{ PROXY_RESPONSE_STATUS_DEFAULT_LABEL }}
                        </SelectItem>
                        <SelectItem
                            v-for="status in PROXY_RESPONSE_STATUSES"
                            :key="status.value"
                            :value="status.value.toString()"
                        >
                            {{ status.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p
                    id="response-status-help"
                    class="text-sm text-muted-foreground"
                >
                    The HTTP status returned to the sender the moment the
                    webhook is received — an acknowledgement, sent immediately
                    and independently of whether delivery to your destinations
                    succeeds. Choose 200, 202, or 204; 204 (No Content) sends an
                    empty body. Leave as Default to return 202 Accepted.
                </p>
                <span id="response-status-error">
                    <InputError :message="form.errors.response_status" />
                </span>
            </div>

            <div class="grid gap-2">
                <Label for="response_body">Response body</Label>
                <Input
                    id="response_body"
                    v-model="form.response_body"
                    type="text"
                    placeholder="(empty)"
                    :disabled="form.processing || bodyDisabled"
                    :aria-invalid="
                        form.errors.response_body ? 'true' : undefined
                    "
                    aria-describedby="response-body-help response-body-error"
                />
                <p
                    id="response-body-help"
                    class="text-sm text-muted-foreground"
                >
                    An optional fixed body returned with the acknowledgement
                    (for example a verification challenge echo). It is a static
                    reply, not a delivery report, and never reflects your
                    destinations' responses. Leave blank for an empty body; 204
                    (No Content) always sends an empty body, so this field is
                    disabled when 204 is selected.
                </p>
                <span id="response-body-error">
                    <InputError :message="form.errors.response_body" />
                </span>
            </div>

            <!-- Destinations -->
            <DestinationRows
                v-model="form.destinations"
                :errors="form.errors"
                :disabled="form.processing"
            />
            <InputError :message="form.errors.destinations" />

            <!-- Actions -->
            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ submitLabel }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="cancelHref">Cancel</Link>
                </Button>
            </div>
        </Card>
    </form>
</template>
