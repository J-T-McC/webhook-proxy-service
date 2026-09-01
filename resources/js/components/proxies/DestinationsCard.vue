<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    ATTEMPT_SUCCESS_COLUMN_LABEL,
    DELIVERY_SUCCESS_COLUMN_LABEL,
    LATENCY_AVERAGE_COLUMN_LABEL,
    compactRateText,
    formatLatencyMs,
    lastWindowSubtitle,
} from '@/data/analyticsLabels';
import {
    destinationValidationBlockedCaption,
    destinationValidationCaption,
    destinationValidationStatusOption,
} from '@/data/destinationValidationStates';
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
    /**
     * Whether the acting member may update this proxy's destinations —
     * `Show.vue`'s existing `canUpdate` computed (AC44). Gates the Validate
     * button only; the badge and caption stay visible to every member who
     * can view the page (AC31).
     */
    canUpdate: boolean;
    /**
     * The destination id whose validation send is in flight, or null (T16) —
     * disables and spins that row's Validate button only.
     */
    validateBusyId: number | null;
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

const emit = defineEmits<{
    /** Send (or resend) this destination's validation challenge (T16). */
    validate: [destinationId: number];
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

/**
 * The Validation cell per destination id (T15; design-18 Screen 2), built
 * from the same `security.destinations` map as the credential badge. An id
 * the map doesn't carry renders an empty cell rather than inventing a state
 * — same "keeps the lookup total" reasoning as `hasCredential`.
 */
const validationCells = computed(() =>
    Object.fromEntries(
        Object.entries(props.security).map(([id, entry]) => [
            id,
            {
                option: destinationValidationStatusOption(
                    entry.validation.status,
                ),
                caption: destinationValidationCaption(entry.validation),
                status: entry.validation.status,
                blockedCaption: entry.validation.send_blocked
                    ? destinationValidationBlockedCaption(
                          entry.validation.send_blocked,
                      )
                    : null,
            },
        ]),
    ),
);

/**
 * Whether this row gets the Validate control (T16; AC14, AC3/AC6, AC44) —
 * any non-Validated live destination, for a member who may update it. A
 * Validated row has no button at all (nothing to send, nothing to undo), and
 * a soft-deleted row has nothing to validate toward: it receives no traffic
 * regardless.
 */
function showsValidateAction(destination: DestinationBreakdownRow): boolean {
    return (
        props.canUpdate &&
        !destination.isDeleted &&
        validationCells.value[destination.id]?.status !== 'validated'
    );
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
        <!-- No declared column widths: the captions are now short enough
        that the browser's own layout gets this right, and the table
        container's existing `overflow-x-auto` scrolls when the total
        exceeds the viewport. -->
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Destination</TableHead>
                    <TableHead>Validation</TableHead>
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
                            <!-- Capped, or this unbreakable token takes the
                            width the Validation column needs. `min-w-0` is
                            what lets `truncate` fire on a flex item. -->
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span
                                            class="block max-w-[20rem] min-w-0 truncate font-mono text-sm"
                                            >{{ destination.url }}</span
                                        >
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-xs">
                                        <p
                                            class="text-left break-all whitespace-normal"
                                        >
                                            {{ destination.url }}
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                            <Badge
                                v-if="hasCredential(destination)"
                                variant="outline"
                            >
                                Credential
                            </Badge>
                        </div>
                    </TableCell>
                    <TableCell>
                        <!-- Reads before the delivery figures: it is the
                        precondition for them meaning anything. Icon + label
                        + caption, never colour alone (design-18 Screen 2). -->
                        <div
                            v-if="validationCells[destination.id]"
                            class="flex flex-col gap-1"
                        >
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="
                                        validationCells[destination.id].option
                                            .variant
                                    "
                                >
                                    <component
                                        :is="
                                            validationCells[destination.id]
                                                .option.icon
                                        "
                                    />
                                    {{
                                        validationCells[destination.id].option
                                            .label
                                    }}
                                </Badge>
                            </div>
                            <!-- Null for the states that ask nothing. -->
                            <p
                                v-if="validationCells[destination.id].caption"
                                class="text-xs whitespace-normal text-muted-foreground"
                            >
                                {{ validationCells[destination.id].caption }}
                            </p>
                            <template v-if="showsValidateAction(destination)">
                                <!-- A tripped limit replaces the button, never
                                disables it (AC21). -->
                                <p
                                    v-if="
                                        validationCells[destination.id]
                                            .blockedCaption
                                    "
                                    class="text-xs whitespace-normal text-muted-foreground"
                                >
                                    {{
                                        validationCells[destination.id]
                                            .blockedCaption
                                    }}
                                </p>
                                <template v-else>
                                    <Button
                                        class="self-start"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="
                                            validateBusyId === destination.id
                                        "
                                        @click="
                                            emit('validate', destination.id)
                                        "
                                    >
                                        <Spinner
                                            v-if="
                                                validateBusyId ===
                                                destination.id
                                            "
                                        />
                                        Validate
                                    </Button>
                                </template>
                            </template>
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
