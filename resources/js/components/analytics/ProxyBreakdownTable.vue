<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    ATTEMPT_SUCCESS_COLUMN_LABEL,
    DELIVERY_SUCCESS_COLUMN_LABEL,
    TERMINAL_FAILURES_COLUMN_LABEL,
    compactRateText,
    lastWindowSubtitle,
} from '@/data/analyticsLabels';
import type {
    AnalyticsWindowValue,
    ProxyBreakdownRow,
} from '@/types/analytics';
import type { RouteDefinition } from '@/wayfinder';

const props = defineProps<{
    proxies: ProxyBreakdownRow[];
    window: AnalyticsWindowValue;
    /**
     * Proxy Show, carrying the currently selected window (Flow B step 4) —
     * the next page opens on the same period rather than resetting to the
     * default.
     */
    showHref: (proxyId: number) => RouteDefinition<'get'>;
    /**
     * The Terminal-failures cell's drill-through target (Flow B step 5;
     * design-11 Flow E entry-point table).
     */
    failuresHref: (proxyId: number) => RouteDefinition<'get'>;
}>();

// Sorting (design-11 § Interactions: client-side, on data already on the
// page — no new request). Default is alphabetical by name, ascending (Flow B
// step 2; flagged design call 5 — never worst-first).
type ProxySortColumn = 'name' | 'delivery' | 'attempt' | 'terminalFailures';

const proxySortColumn = ref<ProxySortColumn>('name');
const proxySortDirection = ref<'asc' | 'desc'>('asc');

function toggleProxySort(column: ProxySortColumn): void {
    if (proxySortColumn.value === column) {
        proxySortDirection.value =
            proxySortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        proxySortColumn.value = column;
        proxySortDirection.value = 'asc';
    }
}

function proxySortAria(
    column: ProxySortColumn,
): 'ascending' | 'descending' | 'none' {
    if (proxySortColumn.value !== column) {
        return 'none';
    }

    return proxySortDirection.value === 'asc' ? 'ascending' : 'descending';
}

function proxySortValue(
    row: ProxyBreakdownRow,
    column: ProxySortColumn,
): string | number {
    switch (column) {
        case 'name':
            return row.name.toLowerCase();
        case 'delivery':
            // A `null` rate (no traffic) sorts before every real rate.
            return row.delivery.rate ?? -1;
        case 'attempt':
            return row.attempt.rate ?? -1;
        case 'terminalFailures':
            return row.terminalFailures;
    }
}

const sortedProxies = computed(() => {
    const direction = proxySortDirection.value === 'asc' ? 1 : -1;

    return [...props.proxies].sort((a, b) => {
        const valueA = proxySortValue(a, proxySortColumn.value);
        const valueB = proxySortValue(b, proxySortColumn.value);

        if (valueA < valueB) {
            return -1 * direction;
        }

        if (valueA > valueB) {
            return 1 * direction;
        }

        return 0;
    });
});
</script>

<template>
    <Card class="gap-4 p-6">
        <div>
            <h2 class="text-base font-semibold">Proxies</h2>
            <p class="text-sm text-muted-foreground">
                {{ lastWindowSubtitle(props.window) }}
            </p>
        </div>
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead :aria-sort="proxySortAria('name')">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 font-medium"
                            @click="toggleProxySort('name')"
                        >
                            Proxy
                            <span
                                v-if="proxySortColumn === 'name'"
                                aria-hidden="true"
                            >
                                {{ proxySortDirection === 'asc' ? '▲' : '▼' }}
                            </span>
                        </button>
                    </TableHead>
                    <TableHead :aria-sort="proxySortAria('delivery')">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 font-medium"
                            @click="toggleProxySort('delivery')"
                        >
                            {{ DELIVERY_SUCCESS_COLUMN_LABEL }}
                            <span
                                v-if="proxySortColumn === 'delivery'"
                                aria-hidden="true"
                            >
                                {{ proxySortDirection === 'asc' ? '▲' : '▼' }}
                            </span>
                        </button>
                    </TableHead>
                    <TableHead :aria-sort="proxySortAria('attempt')">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 font-medium"
                            @click="toggleProxySort('attempt')"
                        >
                            {{ ATTEMPT_SUCCESS_COLUMN_LABEL }}
                            <span
                                v-if="proxySortColumn === 'attempt'"
                                aria-hidden="true"
                            >
                                {{ proxySortDirection === 'asc' ? '▲' : '▼' }}
                            </span>
                        </button>
                    </TableHead>
                    <TableHead :aria-sort="proxySortAria('terminalFailures')">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 font-medium"
                            @click="toggleProxySort('terminalFailures')"
                        >
                            {{ TERMINAL_FAILURES_COLUMN_LABEL }}
                            <span
                                v-if="proxySortColumn === 'terminalFailures'"
                                aria-hidden="true"
                            >
                                {{ proxySortDirection === 'asc' ? '▲' : '▼' }}
                            </span>
                        </button>
                    </TableHead>
                    <TableHead class="text-right">
                        <span class="sr-only">View</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="proxy in sortedProxies" :key="proxy.id">
                    <TableCell>
                        <div class="flex items-center gap-2">
                            <Link
                                v-if="proxy.canDrillThrough"
                                :href="props.showHref(proxy.id)"
                                class="font-medium hover:underline"
                            >
                                {{ proxy.name }}
                            </Link>
                            <span v-else class="font-medium">{{
                                proxy.name
                            }}</span>
                            <Badge v-if="proxy.isDeleted" variant="secondary">
                                Deleted
                            </Badge>
                        </div>
                    </TableCell>
                    <TableCell>{{ compactRateText(proxy.delivery) }}</TableCell>
                    <TableCell>{{ compactRateText(proxy.attempt) }}</TableCell>
                    <TableCell>
                        <Link
                            v-if="proxy.canDrillThrough"
                            :href="props.failuresHref(proxy.id)"
                            class="hover:underline"
                        >
                            {{ proxy.terminalFailures }}
                        </Link>
                        <span v-else>{{ proxy.terminalFailures }}</span>
                    </TableCell>
                    <TableCell class="text-right">
                        <Button
                            v-if="proxy.canDrillThrough"
                            variant="ghost"
                            size="sm"
                            as-child
                        >
                            <Link :href="props.showHref(proxy.id)"> View </Link>
                        </Button>
                        <span v-else class="text-sm text-muted-foreground"
                            >—</span
                        >
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </Card>
</template>
