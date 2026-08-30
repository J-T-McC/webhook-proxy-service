<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { AnalyticsWindowValue } from '@/types/analytics';
import type { RouteDefinition } from '@/wayfinder';

/** The three windows the page-level selector switches between (AC17). */
const WINDOW_VALUES: AnalyticsWindowValue[] = ['24h', '7d', '30d'];

const props = defineProps<{
    /** The window currently in effect, as the server resolved it. */
    window: AnalyticsWindowValue;
    /**
     * Builds this page's own `?window=` target. Every selector entry is a
     * full-page navigation (design-11 § Interactions) — never client-side
     * state, so the server recomputes every figure for the newly selected
     * window.
     */
    hrefFor: (value: AnalyticsWindowValue) => RouteDefinition<'get'>;
}>();
</script>

<template>
    <nav class="flex items-center gap-2" aria-label="Time window">
        <Button
            v-for="value in WINDOW_VALUES"
            :key="value"
            as-child
            :variant="value === props.window ? 'default' : 'outline'"
            size="sm"
        >
            <Link
                :href="props.hrefFor(value)"
                :aria-current="value === props.window ? 'true' : undefined"
            >
                {{ value }}
            </Link>
        </Button>
    </nav>
</template>
