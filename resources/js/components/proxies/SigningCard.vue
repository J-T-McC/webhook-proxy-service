<script setup lang="ts">
import { computed } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatTimestamp } from '@/lib/format';
import type { ProxySecurity } from '@/types/proxies';

/**
 * Screen 4b (AC54, AC57, AC63; Flows G, I) — the proxy-wide outbound signing
 * status, driven entirely by `security.signing` (T38). No per-destination
 * badge anywhere (Amendment B ruling 1) and no trust-domain warning (ruling
 * 2b): this card states the proxy-wide fact once, where the setting lives.
 *
 * The mutating actions (Enable/Manage signing, End overlap now) are
 * `canUpdate`-gated; the status itself always renders.
 */
const props = defineProps<{
    signing: ProxySecurity['signing'];
    canUpdate: boolean;
    overlapBusy: boolean;
    overlapError: string | null;
}>();

defineEmits<{
    manage: [];
    'end-overlap': [];
}>();

const signingOverlapStatus = computed(() => {
    const expiresAt = props.signing.overlap_expires_at;

    return expiresAt ? formatTimestamp(expiresAt) : null;
});
const signingGeneratedStatus = computed(() =>
    props.signing.generated_at
        ? `Enabled — generated ${formatTimestamp(props.signing.generated_at)}`
        : null,
);
</script>

<template>
    <Card class="gap-4 p-6">
        <h2 class="text-base font-semibold">Signing</h2>
        <p class="text-sm text-muted-foreground">
            Whether this proxy signs its dispatches so every destination it
            sends to can verify the request really came from this proxy.
        </p>

        <template v-if="!props.signing.enabled">
            <p class="text-sm text-muted-foreground">
                This proxy does not sign its dispatches yet.
            </p>
            <Button
                v-if="props.canUpdate"
                variant="outline"
                class="w-fit"
                @click="$emit('manage')"
            >
                Enable signing
            </Button>
        </template>

        <template v-else>
            <dl class="flex flex-col gap-3">
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Status</dt>
                    <dd class="text-sm">{{ signingGeneratedStatus }}</dd>
                </div>
            </dl>

            <!-- Rotation status always renders for anyone who can view
                 this proxy; only the mutating actions are canUpdate-gated. -->
            <template v-if="signingOverlapStatus">
                <p class="text-sm">
                    A rotation is in progress — your previous secret is still
                    honoured until {{ signingOverlapStatus }}.
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="props.canUpdate"
                        variant="outline"
                        :disabled="props.overlapBusy"
                        @click="$emit('end-overlap')"
                    >
                        <Spinner v-if="props.overlapBusy" />
                        End overlap now
                    </Button>
                    <Button
                        v-if="props.canUpdate"
                        variant="ghost"
                        @click="$emit('manage')"
                    >
                        Manage signing
                    </Button>
                </div>
                <AlertError
                    v-if="props.overlapError"
                    :errors="[props.overlapError]"
                    title="Could not end the rotation overlap"
                />
            </template>
            <Button
                v-else-if="props.canUpdate"
                variant="ghost"
                class="w-fit"
                @click="$emit('manage')"
            >
                Manage signing
            </Button>
        </template>
    </Card>
</template>
