import type { BadgeVariants } from '@/components/ui/badge';
import type { DataOption } from '@/types/data';

/**
 * A single delivery-state option. Extends the standard {@link DataOption}
 * with `variant` — the `Badge` variant this state renders with.
 */
export interface ProxyDeliveryStateOption extends DataOption<string> {
    variant: NonNullable<BadgeVariants['variant']>;
}

/**
 * Single source of truth for a **per-destination** delivery's status — shared
 * by the event detail page's per-destination rows (design-06 Screen 3).
 *
 * Values MUST stay in sync with the PHP `DeliveryStatus` enum
 * (`app/Enums/DeliveryStatus.php`) — the backend is authoritative. `pending`
 * (queued, no attempt sent yet) has no distinct badge state in design-06 — it
 * reads identically to `retrying` here (both are simply "not yet delivered,
 * more attempts to come" from the user's perspective); `failed` is always the
 * terminal outcome (the backend only reaches it once the retry limit is
 * spent), so it renders as "Terminally failed", never a transient failure.
 *
 * `skipped` (#18, ADR-028) is terminal but deliberately NOT destructive: the
 * destination was never contacted, so nothing failed and there is nothing to
 * debug at the destination end. The fix is to validate the destination, which
 * is why the label names the cause rather than the outcome.
 */
export const PROXY_DELIVERY_STATUSES = [
    { value: 'succeeded', label: 'Delivered', variant: 'moved' },
    { value: 'retrying', label: 'Retrying', variant: 'waiting' },
    { value: 'pending', label: 'Retrying', variant: 'waiting' },
    { value: 'failed', label: 'Terminally failed', variant: 'destructive' },
    { value: 'skipped', label: 'Not sent — destination unvalidated', variant: 'waiting' },
] as const satisfies readonly ProxyDeliveryStateOption[];

/**
 * A per-destination delivery's status — the value union derived from
 * {@link PROXY_DELIVERY_STATUSES} (currently `'succeeded' | 'retrying' |
 * 'pending' | 'failed'`).
 */
export type ProxyDeliveryStatus =
    (typeof PROXY_DELIVERY_STATUSES)[number]['value'];

/**
 * The badge option (label + variant) for a per-destination delivery status.
 */
export function proxyDeliveryStatusOption(
    status: ProxyDeliveryStatus,
): ProxyDeliveryStateOption {
    return (
        PROXY_DELIVERY_STATUSES.find((option) => option.value === status) ??
        PROXY_DELIVERY_STATUSES[1]
    );
}

/** Whether a per-destination delivery status is terminal (mirrors the PHP `DeliveryStatus::isTerminal()`). */
export function proxyDeliveryStatusIsTerminal(
    status: ProxyDeliveryStatus,
): boolean {
    return status === 'succeeded' || status === 'failed';
}

/**
 * Single source of truth for the **aggregate** delivery-state badge shown on
 * the events list (design-06 Screen 2) — a client-side rollup across an
 * event's per-destination deliveries (design-06 flagged judgment call 2, PM
 * ratified). Distinct label set from {@link PROXY_DELIVERY_STATUSES}: the
 * per-destination "Terminally failed" reads as plain "Failed" once
 * aggregated, since the row itself is not a delivery.
 */
export const PROXY_AGGREGATE_DELIVERY_STATES = [
    { value: 'delivered', label: 'Delivered', variant: 'moved' },
    { value: 'retrying', label: 'Retrying', variant: 'waiting' },
    { value: 'failed', label: 'Failed', variant: 'destructive' },
] as const satisfies readonly ProxyDeliveryStateOption[];

/**
 * The aggregate delivery state — the value union derived from
 * {@link PROXY_AGGREGATE_DELIVERY_STATES} (currently `'delivered' |
 * 'retrying' | 'failed'`).
 */
export type ProxyAggregateDeliveryState =
    (typeof PROXY_AGGREGATE_DELIVERY_STATES)[number]['value'];

/**
 * Precedence helper for the aggregate delivery badge (design-06 flagged
 * judgment call 2): **terminal-failure beats retrying beats delivered**. An
 * event with no deliveries at all (should not occur — every event gets at
 * least one original delivery row, T8) reads as `delivered`, the vacuous/
 * no-negative-signal default.
 */
export function proxyAggregateDeliveryState(
    statuses: ProxyDeliveryStatus[],
): ProxyAggregateDeliveryState {
    if (statuses.some((status) => status === 'failed')) {
        return 'failed';
    }

    if (
        statuses.some((status) => status === 'retrying' || status === 'pending')
    ) {
        return 'retrying';
    }

    return 'delivered';
}

/**
 * The badge option (label + variant) for an aggregate delivery state.
 */
export function proxyAggregateDeliveryStateOption(
    state: ProxyAggregateDeliveryState,
): ProxyDeliveryStateOption {
    return (
        PROXY_AGGREGATE_DELIVERY_STATES.find(
            (option) => option.value === state,
        ) ?? PROXY_AGGREGATE_DELIVERY_STATES[0]
    );
}
