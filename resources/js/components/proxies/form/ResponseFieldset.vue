<script setup lang="ts">
import { Info } from '@lucide/vue';
import { computed, watch } from 'vue';
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
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    PROXY_RESPONSE_STATUS_DEFAULT_LABEL,
    PROXY_RESPONSE_STATUSES,
    proxyStatusForcesEmptyBody,
} from '@/data/proxyResponseStatuses';
import type { ProxyResponseStatus } from '@/types/proxies';

/**
 * The upstream acknowledgement contract — status code and optional body.
 * Both are held as strings (see `ProxyFormData`); an empty status means
 * "unconfigured" and normalises back to null on submit.
 */
const status = defineModel<string>('status', { required: true });
const body = defineModel<string>('body', { required: true });

const props = defineProps<{
    statusError?: string;
    bodyError?: string;
    disabled?: boolean;
}>();

// Status is the closed set from PROXY_RESPONSE_STATUSES plus a "default"
// sentinel (the unconfigured state → 202). The sentinel maps to '' so submit
// still sends null.
const STATUS_DEFAULT = 'default';
const statusSelect = computed({
    get: () => (status.value === '' ? STATUS_DEFAULT : status.value),
    set: (value: string) => {
        status.value = value === STATUS_DEFAULT ? '' : value;
    },
});

// The selected status as its typed value (null when unconfigured); the select
// is closed to the shared set + the '' sentinel, so the numeric coercion is
// exact.
const selectedStatus = computed<ProxyResponseStatus | null>(() =>
    status.value === '' ? null : (Number(status.value) as ProxyResponseStatus),
);

// 204 = No Content couples to an empty body (AC12): selecting a status flagged
// emptyBody disables the body field and clears any previously entered body.
const bodyDisabled = computed(() =>
    proxyStatusForcesEmptyBody(selectedStatus.value),
);
watch(selectedStatus, (value) => {
    if (proxyStatusForcesEmptyBody(value)) {
        body.value = '';
    }
});
</script>

<template>
    <Card class="gap-6 p-6">
        <h2 class="text-base font-semibold">Response</h2>
        <div class="grid gap-2">
            <Label for="response_status">Status code</Label>
            <Select v-model="statusSelect" :disabled="props.disabled">
                <SelectTrigger
                    id="response_status"
                    class="w-full sm:w-64"
                    :aria-invalid="props.statusError ? 'true' : undefined"
                    aria-describedby="response-status-help response-status-error"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="STATUS_DEFAULT">
                        {{ PROXY_RESPONSE_STATUS_DEFAULT_LABEL }}
                    </SelectItem>
                    <SelectItem
                        v-for="option in PROXY_RESPONSE_STATUSES"
                        :key="option.value"
                        :value="option.value.toString()"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p id="response-status-help" class="text-sm text-muted-foreground">
                Sent immediately, before delivery — independent of destination
                outcome.
            </p>
            <span id="response-status-error">
                <InputError :message="props.statusError" />
            </span>
        </div>

        <div class="grid gap-2">
            <div class="flex items-center gap-2">
                <Label for="response_body">Body</Label>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label="More about the response body"
                        >
                            <Info class="size-3.5" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        <p>
                            Useful for a verification challenge echo some
                            senders require during setup.
                        </p>
                    </TooltipContent>
                </Tooltip>
            </div>
            <Input
                id="response_body"
                v-model="body"
                type="text"
                placeholder="(empty)"
                :disabled="props.disabled || bodyDisabled"
                :aria-invalid="props.bodyError ? 'true' : undefined"
                aria-describedby="response-body-help response-body-error"
            />
            <p id="response-body-help" class="text-sm text-muted-foreground">
                Optional. Disabled when Status code is 204.
            </p>
            <span id="response-body-error">
                <InputError :message="props.bodyError" />
            </span>
        </div>
    </Card>
</template>
