<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
    LATENCY_AVERAGE_COLUMN_LABEL,
    compactRateText,
    formatLatencyMs,
    lastWindowSubtitle,
} from '@/data/analyticsLabels';
import type {
    AnalyticsWindowValue,
    DestinationBreakdownRow,
} from '@/types/analytics';
import type { ProxySecurity } from '@/types/proxies';
import type { RouteDefinition } from '@/wayfinder';

/**
 * design-11 Screen 3 — driven from the analytics `destinations` rows (T18's
 * `DestinationBreakdownRow[]`), never from `proxy.destinations` (that
 * relation is live-only and shared with index()/edit(), plan Implementation
 * Note 11), so a deleted destination with historical traffic still gets a
 * row.
 */
const props = defineProps<{
    destinations: DestinationBreakdownRow[];
    /**
     * The proxy's destination-credential map (T32) — status only, never a
     * value and never a length.
     */
    security: ProxySecurity['destinations'];
    window: AnalyticsWindowValue;
    /**
     * The "View events" action target (Flow D step 3) — proxy · destination
     * · window, **no** outcome filter (this row's figures are rates over all
     * of that destination's traffic, not a failure count). Carries the same
     * link for a deleted destination as a live one — soft delete preserves
     * the id, and the destination needs only to be identifiable, not
     * manageable, for drill-through to work (design-11 Screen 3,
     * `Q-11-03(9)`'s destination half).
     */
    viewEventsHref: (
        destination: DestinationBreakdownRow,
    ) => RouteDefinition<'get'>;
}>();

/**
 * Screen 5's `Credential` badge (T33; AC30; plan Technical ruling 4) — looked
 * up by the row's existing id in the `security.destinations` map (T32),
 * never a field on `DestinationBreakdownRow` itself (that DTO is untouched
 * by this feature). Defaults to `false` for an id the map doesn't carry
 * (there is none in practice — T32's map is built `withTrashed()` over every
 * destination the proxy has — but this keeps the lookup total).
 */
function hasCredential(destination: DestinationBreakdownRow): boolean {
    return props.security[destination.id]?.has_credential ?? false;
}
</script>

<template>
    <Card class="gap-4 p-6">
        <div>
            <h2 class="text-base font-semibold">Destinations</h2>
            <p class="text-sm text-muted-foreground">
                {{ lastWindowSubtitle(props.window) }}
            </p>
        </div>
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Destination</TableHead>
                    <TableHead>{{ DELIVERY_SUCCESS_COLUMN_LABEL }}</TableHead>
                    <TableHead>{{ ATTEMPT_SUCCESS_COLUMN_LABEL }}</TableHead>
                    <TableHead>{{ LATENCY_AVERAGE_COLUMN_LABEL }}</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="destination in props.destinations"
                    :key="destination.id"
                >
                    <TableCell>
                        <div class="flex min-w-0 items-center gap-3">
                            <Badge variant="outline">{{
                                destination.httpMethod
                            }}</Badge>
                            <span class="truncate font-mono text-sm">{{
                                destination.url
                            }}</span>
                            <Badge
                                v-if="hasCredential(destination)"
                                variant="outline"
                            >
                                Credential
                            </Badge>
                        </div>
                    </TableCell>
                    <TableCell>{{
                        compactRateText(destination.delivery)
                    }}</TableCell>
                    <TableCell>{{
                        compactRateText(destination.attempt)
                    }}</TableCell>
                    <TableCell>{{
                        formatLatencyMs(destination.latencyAverageMs)
                    }}</TableCell>
                    <TableCell class="text-right">
                        <div class="flex items-center justify-end gap-2">
                            <Badge
                                v-if="destination.isDeleted"
                                variant="secondary"
                            >
                                Deleted
                            </Badge>
                            <Button variant="ghost" size="sm" as-child>
                                <Link :href="props.viewEventsHref(destination)">
                                    View events
                                </Link>
                            </Button>
                        </div>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </Card>
</template>
