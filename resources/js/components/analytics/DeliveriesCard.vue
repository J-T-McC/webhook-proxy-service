<script setup lang="ts">
import { computed } from 'vue';
import { Card } from '@/components/ui/card';
import {
    ATTEMPT_SUCCESS_LABEL,
    DELIVERY_SUCCESS_LABEL,
    attemptCaption,
    bridgeSentence,
    deliveryCaption,
    formatRate,
} from '@/data/analyticsLabels';
import type { StatisticsPanel } from '@/types/analytics';

const props = defineProps<{
    statistics: StatisticsPanel;
    /**
     * Shown in place of the figures when the grain has no traffic in the
     * window. Omitted by a caller whose zero state is already handled
     * elsewhere on the page (the Dashboard's "No proxies yet" card), where
     * the figures render as zeroes rather than as a message.
     */
    emptyMessage?: string | null;
}>();

/**
 * The bridge sentence naming the gap between delivery- and attempt-level
 * success (AC14(d)) — `null` when there is nothing to bridge, so the
 * paragraph is omitted rather than rendered empty.
 */
const bridgeText = computed(() =>
    bridgeSentence(props.statistics.bridgeFailedAttempts),
);

const showEmptyMessage = computed(
    () => !props.statistics.hasTraffic && Boolean(props.emptyMessage),
);
</script>

<template>
    <Card class="gap-4 p-6">
        <h2 class="text-base font-semibold">Deliveries</h2>

        <p v-if="showEmptyMessage" class="text-sm text-muted-foreground">
            {{ props.emptyMessage }}
        </p>

        <template v-else>
            <dl class="flex flex-col gap-5">
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ DELIVERY_SUCCESS_LABEL }}
                    </dt>
                    <dd>
                        <span class="text-3xl font-semibold">
                            {{ formatRate(props.statistics.delivery.rate) }}
                        </span>
                        <p class="text-sm text-muted-foreground">
                            {{
                                deliveryCaption(
                                    props.statistics.delivery.succeeded,
                                    props.statistics.delivery.total,
                                    props.statistics.window,
                                )
                            }}
                        </p>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        {{ ATTEMPT_SUCCESS_LABEL }}
                    </dt>
                    <dd>
                        <span class="text-lg font-medium">
                            {{ formatRate(props.statistics.attempt.rate) }}
                        </span>
                        <p class="text-sm text-muted-foreground">
                            {{
                                attemptCaption(
                                    props.statistics.attempt.succeeded,
                                    props.statistics.attempt.total,
                                    props.statistics.window,
                                )
                            }}
                        </p>
                    </dd>
                </div>
            </dl>
            <p v-if="bridgeText" class="text-sm text-muted-foreground italic">
                {{ bridgeText }}
            </p>
        </template>
    </Card>
</template>
