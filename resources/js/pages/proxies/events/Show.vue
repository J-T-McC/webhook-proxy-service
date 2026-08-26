<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import PayloadViewer from '@/components/PayloadViewer.vue';
import ReplayDialog from '@/components/ReplayDialog.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { proxyDeliveryStatusOption } from '@/data/proxyDeliveryStates';
import { proxyPayloadStateOption } from '@/data/proxyPayloadStates';
import { formatByteSize, formatTimestamp } from '@/lib/format';
import proxyRoutes from '@/routes/proxies';
import proxyEventRoutes from '@/routes/proxies/events';
import type { Team } from '@/types';
import type {
    Delivery,
    DispatchKind,
    ProxyDetail,
    ProxyPermissions,
    WebhookEventDetail,
} from '@/types/proxies';

const props = defineProps<{
    proxy: ProxyDetail;
    event: WebhookEventDetail;
    permissions: ProxyPermissions;
}>();

defineOptions({
    layout: (options: {
        currentTeam?: Team | null;
        proxy: ProxyDetail;
        event: WebhookEventDetail;
    }) => ({
        breadcrumbs: [
            {
                title: 'Proxies',
                href: options.currentTeam
                    ? proxyRoutes.index(options.currentTeam.slug)
                    : '/',
            },
            {
                title: options.proxy.name,
                href: options.currentTeam
                    ? proxyRoutes.show({
                          current_team: options.currentTeam.slug,
                          proxy: options.proxy.id,
                      })
                    : '/',
            },
            {
                title: 'Events',
                href: options.currentTeam
                    ? proxyEventRoutes.index({
                          current_team: options.currentTeam.slug,
                          proxy: options.proxy.id,
                      })
                    : '/',
            },
            {
                title: formatTimestamp(options.event.received_at),
                href: options.currentTeam
                    ? proxyEventRoutes.show({
                          current_team: options.currentTeam.slug,
                          proxy: options.proxy.id,
                          event: options.event.id,
                      })
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const payloadBadge = computed(() =>
    proxyPayloadStateOption(props.event.payload_state),
);

const canReplay = computed(
    () =>
        props.event.payload_state === 'retained' &&
        props.permissions.canReplayProxy,
);

const replayDialogOpen = ref(false);

const payloadUrl = computed(
    () =>
        proxyEventRoutes.payload({
            current_team: teamSlug.value,
            proxy: props.proxy.id,
            event: props.event.id,
        }).url,
);

/**
 * One group per logical dispatch (the original send, or one manual replay
 * batch) — grouped by `dispatch_uuid` (design-06 Screen 3's "Original
 * delivery" / "Replay — {time}" grouping, AC12 traceability). A pre-#6 event
 * (legacy fallback, T25) has every row share `dispatch_uuid: null`, which
 * groups them together into a single synthetic "Original delivery" group,
 * exactly as intended (there is only ever one legacy-fallback batch).
 *
 * The group's own "{time}" label and newest-first ordering for Replay groups
 * (review-06 Minor 5, rider 2) derive directly from `Delivery.created_at` —
 * every delivery row in a group is created together, ahead of dispatch, by
 * the same batch (`DeliverStep`), so any row's `created_at` is the group's
 * creation time. This replaces the earlier derivation from the earliest
 * attempt's `started_at` (which degraded to no time suffix — a bare
 * "Replay" — for a FIFO replay still queued behind a held line, with zero
 * attempts yet) and from the group's highest `Delivery.id` for ordering.
 */
interface DeliveryGroup {
    key: string;
    kind: DispatchKind;
    deliveries: Delivery[];
    time: string | null;
}

const deliveryGroups = computed<DeliveryGroup[]>(() => {
    const byKey = new Map<string, Delivery[]>();

    for (const delivery of props.event.deliveries) {
        const key = delivery.dispatch_uuid ?? '__legacy__';
        const group = byKey.get(key);

        if (group) {
            group.push(delivery);
        } else {
            byKey.set(key, [delivery]);
        }
    }

    const groups = Array.from(byKey.entries()).map(([key, deliveries]) => {
        const createdAts = deliveries
            .map((delivery) => delivery.created_at)
            .filter((value): value is string => !!value)
            .sort();

        return {
            key,
            kind: deliveries[0].kind,
            deliveries,
            time: createdAts.length > 0 ? createdAts[0] : null,
        };
    });

    const original = groups.filter((group) => group.kind === 'original');
    const replays = groups
        .filter((group) => group.kind === 'replay')
        .sort((a, b) => (b.time ?? '').localeCompare(a.time ?? ''));

    return [...original, ...replays];
});

function groupLabel(group: DeliveryGroup): string {
    if (group.kind === 'original') {
        return 'Original delivery';
    }

    return group.time ? `Replay — ${formatTimestamp(group.time)}` : 'Replay';
}

function attemptsFor(delivery: Delivery) {
    return [...(delivery.attempts ?? [])].sort(
        (a, b) => a.attempt_number - b.attempt_number,
    );
}

/**
 * Event-scoped FIFO head-of-line note (Flow B step 4; Screen 3): `true` iff
 * this proxy is FIFO **and** this event currently has a delivery in the
 * `retrying` state. `ProxyEventController@show` (T27) exposes no dedicated
 * "is this event's head currently retrying" flag, so this is derived
 * client-side from data the page already carries — on a FIFO proxy only one
 * dispatch can be `retrying` at a time (ADR-016: a retrying head holds the
 * line), so a `retrying` delivery on *this* event's page is, by construction,
 * that held head.
 */
const isFifoHeldByRetry = computed(
    () =>
        props.proxy.processing_mode === 'fifo' &&
        props.event.deliveries.some(
            (delivery) => delivery.status === 'retrying',
        ),
);

function attemptSummaryFor(delivery: Delivery): string | null {
    const attempts = attemptsFor(delivery);

    if (delivery.attempt_limit === null) {
        // Legacy-fallback row (T25): no attempt history is known, only the
        // derived status itself.
        return null;
    }

    if (attempts.length === 0) {
        return 'Awaiting first attempt';
    }

    if (delivery.status === 'succeeded') {
        const succeeded = [...attempts]
            .reverse()
            .find((attempt) => attempt.status === 'succeeded');

        return succeeded?.started_at
            ? `Delivered ${formatTimestamp(succeeded.started_at)}`
            : 'Delivered';
    }

    if (delivery.status === 'failed') {
        return `Attempt ${attempts.length} of ${delivery.attempt_limit} — retries exhausted`;
    }

    return `Attempt ${attempts.length} of ${delivery.attempt_limit} — waiting for its next attempt`;
}
</script>

<template>
    <Head
        :title="`Event received ${formatTimestamp(props.event.received_at)}`"
    />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <Link
            :href="
                proxyEventRoutes.index({
                    current_team: teamSlug,
                    proxy: props.proxy.id,
                })
            "
            class="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:underline"
        >
            <ArrowLeft class="size-4" />
            Back to events
        </Link>

        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">
                    Received {{ formatTimestamp(props.event.received_at) }}
                </h1>
                <Badge :variant="payloadBadge.variant">{{
                    payloadBadge.label
                }}</Badge>
            </div>
            <Button
                v-if="canReplay"
                variant="outline"
                @click="replayDialogOpen = true"
            >
                Replay
            </Button>
        </div>

        <!-- Details card -->
        <Card class="gap-3 p-6">
            <h2 class="text-sm font-medium">Details</h2>
            <dl class="flex flex-col gap-3">
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Received</dt>
                    <dd class="text-sm">
                        {{ formatTimestamp(props.event.received_at) }}
                    </dd>
                </div>
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Size</dt>
                    <dd class="text-sm">
                        {{ formatByteSize(props.event.byte_size) }}
                    </dd>
                </div>
                <div
                    class="flex flex-col sm:flex-row sm:items-baseline sm:gap-2"
                >
                    <dt class="text-sm text-muted-foreground">Content type</dt>
                    <dd class="text-sm">
                        {{ props.event.content_type ?? '—' }}
                    </dd>
                </div>
            </dl>
        </Card>

        <!-- Payload card -->
        <Card class="gap-3 p-6">
            <h2 class="text-sm font-medium">Payload</h2>
            <PayloadViewer
                v-if="props.event.payload_state === 'retained'"
                :url="payloadUrl"
            />
            <p
                v-else-if="props.event.payload_state === 'cleaned'"
                class="text-sm text-muted-foreground italic"
            >
                Payload expired on schedule — retained for your team's 30-day
                window. Nothing left to view.
            </p>
            <p v-else class="text-sm text-muted-foreground italic">
                No payload was captured for this event.
            </p>
        </Card>

        <!-- Delivery card -->
        <Card class="gap-4 p-6">
            <h2 class="text-sm font-medium">Delivery</h2>

            <Alert
                v-if="isFifoHeldByRetry"
                class="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400"
            >
                <Info class="size-4" />
                <AlertDescription class="text-blue-900 dark:text-blue-100">
                    This proxy is FIFO. This event is currently retrying and
                    holding the line — newer events wait until it succeeds or is
                    set aside after its retry limit.
                </AlertDescription>
            </Alert>

            <div
                v-for="group in deliveryGroups"
                :key="group.key"
                class="flex flex-col gap-2"
            >
                <h3 class="text-sm font-medium text-muted-foreground">
                    {{ groupLabel(group) }}
                </h3>
                <ul class="divide-y">
                    <li
                        v-for="delivery in group.deliveries"
                        :key="`${group.key}-${delivery.destination.url}-${delivery.destination.http_method}`"
                        class="flex flex-col gap-2 py-3"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <Badge variant="outline">{{
                                delivery.destination.http_method
                            }}</Badge>
                            <span class="truncate font-mono text-sm">{{
                                delivery.destination.url
                            }}</span>
                            <Badge
                                :variant="
                                    proxyDeliveryStatusOption(delivery.status)
                                        .variant
                                "
                            >
                                {{
                                    proxyDeliveryStatusOption(delivery.status)
                                        .label
                                }}
                            </Badge>
                        </div>
                        <p
                            v-if="attemptSummaryFor(delivery)"
                            class="text-sm text-muted-foreground"
                        >
                            {{ attemptSummaryFor(delivery) }}
                        </p>

                        <Collapsible v-if="attemptsFor(delivery).length > 0">
                            <CollapsibleTrigger as-child>
                                <Button variant="ghost" size="sm" class="w-fit">
                                    Show
                                    {{ attemptsFor(delivery).length }} attempt{{
                                        attemptsFor(delivery).length === 1
                                            ? ''
                                            : 's'
                                    }}
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <ul class="flex flex-col gap-1 py-2 pl-1">
                                    <li
                                        v-for="attempt in attemptsFor(delivery)"
                                        :key="attempt.attempt_number"
                                        class="text-sm text-muted-foreground"
                                    >
                                        Attempt {{ attempt.attempt_number }} —
                                        {{ attempt.status }}
                                        <template v-if="attempt.http_status">
                                            ({{ attempt.http_status }})
                                        </template>
                                        <template v-if="attempt.error_summary">
                                            — {{ attempt.error_summary }}
                                        </template>
                                        <template v-if="attempt.started_at">
                                            —
                                            {{
                                                formatTimestamp(
                                                    attempt.started_at,
                                                )
                                            }}
                                        </template>
                                    </li>
                                </ul>
                            </CollapsibleContent>
                        </Collapsible>
                    </li>
                </ul>
            </div>
        </Card>
    </div>

    <ReplayDialog
        v-model:open="replayDialogOpen"
        :team-slug="teamSlug"
        :proxy-id="props.proxy.id"
        :destinations="props.proxy.destinations"
        :is-fifo="props.proxy.processing_mode === 'fifo'"
        :event-id="props.event.id"
    />
</template>
