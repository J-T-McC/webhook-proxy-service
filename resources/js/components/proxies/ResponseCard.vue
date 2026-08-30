<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import {
    proxyResponseStatusLabel,
    proxyStatusForcesEmptyBody,
} from '@/data/proxyResponseStatuses';
import type { ProxyResponseStatus } from '@/types/proxies';

/**
 * Read-only view of the upstream acknowledgement contract. The status label
 * and the 204 empty-body coupling come from the shared response-status const
 * (@/data/proxyResponseStatuses), the same source the edit form's select
 * options derive from, so a status reads identically in both.
 */
const props = defineProps<{
    responseStatus: ProxyResponseStatus | null;
    responseBody: string | null;
}>();

const responseStatusLabel = computed(() =>
    proxyResponseStatusLabel(props.responseStatus),
);

// Whether the stored status forces an empty body (204 No Content) — drives the
// "No content" branch below without a bare 204 literal.
const statusForcesEmptyBody = computed(() =>
    proxyStatusForcesEmptyBody(props.responseStatus),
);

// A real body block renders only for a body-allowing status (not unconfigured,
// not empty-body) with a non-empty string; every other case (unconfigured, 204,
// or an empty/null body) shows muted text.
const hasResponseBody = computed(
    () =>
        props.responseStatus !== null &&
        !statusForcesEmptyBody.value &&
        props.responseBody !== null &&
        props.responseBody !== '',
);
</script>

<template>
    <Card class="gap-4 p-6">
        <h2 class="text-base font-semibold">Response</h2>
        <p class="text-sm text-muted-foreground">
            Returned to the sender immediately when the webhook is received —
            independent of whether delivery to your destinations succeeds.
        </p>
        <dl class="flex flex-col gap-3">
            <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2">
                <dt class="text-sm text-muted-foreground">Status</dt>
                <dd>
                    <Badge variant="secondary">{{ responseStatusLabel }}</Badge>
                </dd>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2">
                <dt class="text-sm text-muted-foreground">Body</dt>
                <dd class="min-w-0 flex-1">
                    <span
                        v-if="props.responseStatus === null"
                        class="text-sm text-muted-foreground italic"
                    >
                        No custom body configured — the default response has no
                        body.
                    </span>
                    <span
                        v-else-if="statusForcesEmptyBody"
                        class="text-sm text-muted-foreground italic"
                    >
                        No content (204)
                    </span>
                    <div
                        v-else-if="hasResponseBody"
                        class="max-h-48 overflow-y-auto rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm break-words whitespace-pre-wrap dark:bg-input/30"
                        v-text="props.responseBody"
                    />
                    <span v-else class="text-sm text-muted-foreground italic">
                        (empty)
                    </span>
                </dd>
            </div>
        </dl>
    </Card>
</template>
