<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { formatSeriesDate, windowLabel } from '@/data/analyticsLabels';
import type { EventListFilters } from '@/types/analytics';

/**
 * The Events list's active-filter chips (T24; T23/T24 Revision A,
 * `Q-11-04`; AC10, AC21; design-11 Screen 4) — window, destination and/or
 * outcome, up to three at once. A resolved `day` is **not** a fourth chip:
 * plan Technical ruling 10 renders it as the value of the existing Window
 * chip, so the row stays fixed at three.
 *
 * Rendering this component at all is the caller's decision — an arrival with
 * none of the three resolved is "arrived directly," visually identical to
 * the pre-#11 shipped surface (T21's own reading of AC28), so no chip row
 * renders there.
 */
const props = defineProps<{
    filters: EventListFilters;
    /**
     * Rebuilds the Events list URL from the currently active filters,
     * dropping exactly the one named. A chip's remove control is a real
     * re-navigation (design-11 § Interactions: "not a client-side row
     * filter"), never client-side state, so the server-side query and the
     * URL stay the single source of truth for what's shown.
     */
    hrefFor: (remove: 'window' | 'destination' | 'outcome' | 'all') => string;
}>();

/**
 * The Window chip's rendered value — the day-narrowed date (same formatter
 * as the trend table's Date column, ruling 10/Implementation Note 20) when
 * `filters.day` resolved, otherwise the usual "last {window}" text.
 */
const windowChipValue = computed(() =>
    props.filters.day
        ? formatSeriesDate(props.filters.day)
        : `last ${windowLabel(props.filters.window)}`,
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" aria-label="Active filters">
        <!-- Removing the Window chip drops `date` alongside it (ruling 10),
             since a resolved day is that chip's value, not a filter of its
             own — `hrefFor('window')` is what encodes that. -->
        <Badge variant="secondary" class="gap-1.5 py-1 pr-1.5 pl-2.5">
            Window: {{ windowChipValue }}
            <button
                type="button"
                aria-label="Remove window filter"
                class="rounded-full opacity-70 hover:opacity-100"
                @click="router.get(props.hrefFor('window'))"
            >
                ×
            </button>
        </Badge>
        <Badge
            v-if="props.filters.destination"
            variant="secondary"
            class="gap-1.5 py-1 pr-1.5 pl-2.5"
        >
            Destination: {{ props.filters.destination.httpMethod }}
            {{ props.filters.destination.url }}
            <button
                type="button"
                :aria-label="`Remove destination filter: ${props.filters.destination.url}`"
                class="rounded-full opacity-70 hover:opacity-100"
                @click="router.get(props.hrefFor('destination'))"
            >
                ×
            </button>
        </Badge>
        <Badge
            v-if="props.filters.outcome"
            variant="secondary"
            class="gap-1.5 py-1 pr-1.5 pl-2.5"
        >
            Outcome: {{ props.filters.outcome.label }}
            <button
                type="button"
                aria-label="Remove outcome filter"
                class="rounded-full opacity-70 hover:opacity-100"
                @click="router.get(props.hrefFor('outcome'))"
            >
                ×
            </button>
        </Badge>
    </div>
</template>
