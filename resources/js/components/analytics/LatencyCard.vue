<script setup lang="ts">
import { Card } from '@/components/ui/card';
import {
    LATENCY_AVERAGE_LABEL,
    LATENCY_CAPTION,
    LATENCY_P95_LABEL,
    formatLatencyMs,
    lastWindowSubtitle,
} from '@/data/analyticsLabels';
import type { StatisticsPanel } from '@/types/analytics';

const props = defineProps<{
    statistics: StatisticsPanel;
}>();
</script>

<template>
    <Card class="gap-4 p-6">
        <div>
            <h2 class="text-base font-semibold">Latency</h2>
            <p class="text-sm text-muted-foreground">
                {{ lastWindowSubtitle(props.statistics.window) }}
            </p>
        </div>
        <dl class="flex flex-col gap-3">
            <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2">
                <dt class="text-sm text-muted-foreground">
                    {{ LATENCY_AVERAGE_LABEL }}
                </dt>
                <dd class="text-lg font-medium">
                    {{ formatLatencyMs(props.statistics.latency.averageMs) }}
                </dd>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2">
                <dt class="text-sm text-muted-foreground">
                    {{ LATENCY_P95_LABEL }}
                </dt>
                <dd class="text-lg font-medium">
                    {{ formatLatencyMs(props.statistics.latency.p95Ms) }}
                </dd>
            </div>
        </dl>
        <p class="text-sm text-muted-foreground">{{ LATENCY_CAPTION }}</p>
    </Card>
</template>
