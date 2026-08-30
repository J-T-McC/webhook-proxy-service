<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card } from '@/components/ui/card';
import {
    EVENTUAL_SUCCESS_LABEL,
    LIVE_VS_REPLAY_LABEL,
    RETRY_VOLUME_LABEL,
    TERMINAL_FAILURE_LABEL,
    lastWindowSubtitle,
    liveVsReplayText,
} from '@/data/analyticsLabels';
import type { StatisticsPanel } from '@/types/analytics';
import type { RouteDefinition } from '@/wayfinder';

const props = defineProps<{
    statistics: StatisticsPanel;
    /**
     * The Terminal failure tile's drill-through target (Flow C step 4;
     * design-11 Flow E entry-point table) — the only one of the four tiles
     * that is failure-shaped. Omitted by a caller with no single proxy to
     * drill into (the Dashboard), where the count renders as plain text.
     */
    terminalFailureHref?: RouteDefinition<'get'> | null;
}>();
</script>

<template>
    <Card class="gap-4 p-6">
        <div>
            <h2 class="text-base font-semibold">Retry &amp; replay</h2>
            <p class="text-sm text-muted-foreground">
                {{ lastWindowSubtitle(props.statistics.window) }}
            </p>
        </div>
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-sm text-muted-foreground">
                    {{ EVENTUAL_SUCCESS_LABEL }}
                </dt>
                <dd class="text-lg font-medium">
                    {{ props.statistics.retryReplay.eventualSuccess }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    {{ TERMINAL_FAILURE_LABEL }}
                </dt>
                <dd class="text-lg font-medium">
                    <Link
                        v-if="props.terminalFailureHref"
                        :href="props.terminalFailureHref"
                        class="hover:underline"
                    >
                        {{ props.statistics.retryReplay.terminalFailure }}
                    </Link>
                    <template v-else>{{
                        props.statistics.retryReplay.terminalFailure
                    }}</template>
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    {{ RETRY_VOLUME_LABEL }}
                </dt>
                <dd class="text-lg font-medium">
                    {{ props.statistics.retryReplay.retryVolume }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">
                    {{ LIVE_VS_REPLAY_LABEL }}
                </dt>
                <dd class="text-lg font-medium">
                    {{
                        liveVsReplayText(
                            props.statistics.retryReplay.live,
                            props.statistics.retryReplay.replay,
                        )
                    }}
                </dd>
            </div>
        </dl>
    </Card>
</template>
