<script setup lang="ts">
import { computed } from 'vue';
import { Card } from '@/components/ui/card';
import {
    proxyRetryAttemptLimitDisplay,
    proxyRetryBackoffStrategyDisplay,
} from '@/data/proxyRetryBackoffStrategies';
import type { ProxyMode, RetryBackoffStrategy } from '@/types/proxies';

/**
 * Read-only view of the effective retry policy (design-06 Screen 1 / Flow G).
 * A simple-mode proxy's `retry_attempt_limit`/`retry_backoff_strategy` are
 * suppressed to null on this payload by `ProxyResource` (T5), gated
 * server-side on `mode === Enhanced`
 * (`RetryPolicy::configuredAttemptLimitFor()`/`configuredStrategyFor()`, T1,
 * ADR-018 Decision 4) — never a raw-column read here, and never leaking a
 * dormant value even if one is persisted (AC14(b)). So this card always
 * renders the same "(default)" values as an unconfigured enhanced proxy — the
 * display helpers don't need to branch on mode for the value itself, only for
 * the extra note.
 */
const props = defineProps<{
    mode: ProxyMode;
    retryAttemptLimit: number | null;
    retryBackoffStrategy: RetryBackoffStrategy | null;
}>();

const retryAttemptsDisplay = computed(() =>
    proxyRetryAttemptLimitDisplay(props.retryAttemptLimit),
);
const retryBackoffDisplay = computed(() =>
    proxyRetryBackoffStrategyDisplay(props.retryBackoffStrategy),
);
</script>

<template>
    <Card class="gap-4 p-6">
        <h2 class="text-base font-semibold">Retry policy</h2>
        <p class="text-sm text-muted-foreground">
            Governs automatic re-attempts to your destinations after a failed
            delivery.
        </p>
        <dl class="flex flex-col gap-3">
            <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2">
                <dt class="text-sm text-muted-foreground">Attempts</dt>
                <dd class="text-sm">{{ retryAttemptsDisplay }}</dd>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2">
                <dt class="text-sm text-muted-foreground">Backoff</dt>
                <dd class="text-sm">{{ retryBackoffDisplay }}</dd>
            </div>
        </dl>
        <p v-if="props.mode === 'simple'" class="text-sm text-muted-foreground">
            Simple-mode proxies use the fixed system default. Configuring
            attempts and backoff is an Enhanced-mode capability.
        </p>
    </Card>
</template>
