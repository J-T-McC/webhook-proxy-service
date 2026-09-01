import { Check, Circle, Clock, History } from '@lucide/vue';
import type { Component } from 'vue';

import type { BadgeVariants } from '@/components/ui/badge';
import { formatTimestamp } from '@/lib/format';
import type { DataOption } from '@/types/data';

/**
 * A single validation-state option. Extends the standard {@link DataOption}
 * with `variant` (the `Badge` variant) and `icon` — state is never carried by
 * colour alone (design-18), so every badge pairs its hue with an icon and a
 * label.
 */
export interface DestinationValidationStateOption extends DataOption<string> {
    variant: NonNullable<BadgeVariants['variant']>;
    icon: Component;
}

/**
 * Single source of truth for a destination's product-facing validation state
 * (PRD-18 AC1), shared by the proxy Show page's Destinations table (design-18
 * Screen 2) and the destination form rows (Screen 1).
 *
 * Values MUST stay in sync with the PHP `DestinationValidationStatus` enum
 * (`app/Enums/DestinationValidationStatus.php`) — the backend is
 * authoritative, and it is the one that derives `expired` (a `pending`
 * challenge whose window closed); the client never re-implements that rule.
 *
 * Variants follow the badge convention (colour means state): `waiting` for a
 * challenge sitting with the destination, `moved` for an approved one, and a
 * neutral `outline` for the two states where nothing is in flight
 * (`unvalidated`, `expired`) — distinct from delivery states and from pause
 * (AC32), which have their own vocabulary and never share these labels.
 */
export const DESTINATION_VALIDATION_STATUSES = [
    {
        value: 'unvalidated',
        label: 'Unvalidated',
        variant: 'outline',
        icon: Circle,
    },
    { value: 'pending', label: 'Pending', variant: 'waiting', icon: Clock },
    { value: 'expired', label: 'Expired', variant: 'outline', icon: History },
    { value: 'validated', label: 'Validated', variant: 'moved', icon: Check },
] as const satisfies readonly DestinationValidationStateOption[];

/**
 * A destination's validation status — the value union derived from
 * {@link DESTINATION_VALIDATION_STATUSES}.
 */
export type DestinationValidationStatus =
    (typeof DESTINATION_VALIDATION_STATUSES)[number]['value'];

/**
 * The per-destination `validation` object on the `security` prop (mirrors
 * `ProxySecurityResource` exactly, T15). Timestamps only — the challenge
 * link and its nonce are never present in any response (AC24).
 */
export interface DestinationValidation {
    status: DestinationValidationStatus;
    approved_at: string | null;
    challenge_sent_at: string | null;
    challenge_expires_at: string | null;
    /** The last send's outcome (AC35). Exactly one is ever set; both null if
     * nothing has been sent since the URL last changed. */
    last_send_status: number | null;
    last_send_failure: DestinationValidationSendFailure | null;
    /** The limit blocking a send, or null (AC21). When set, the row shows
     * {@link destinationValidationBlockedCaption} instead of the button. */
    send_blocked: {
        description: string;
        until: string;
    } | null;
}

/** Mirrors the PHP `DestinationValidationSendFailure` enum, which is
 * authoritative. */
export type DestinationValidationSendFailure =
    'address_refused' | 'unreachable' | 'redirected';

/**
 * design-18's failure-reason copy (AC18, AC20, AC35). `address_refused` is
 * deliberately not named as an internal-address rule — the remedy is the same
 * either way.
 */
const SEND_FAILURE_REASONS: Record<DestinationValidationSendFailure, string> = {
    unreachable: "couldn't reach this address",
    address_refused: "this address can't be used",
    redirected: 'this address redirected',
};

/**
 * The badge option (label + variant + icon) for a validation status.
 */
export function destinationValidationStatusOption(
    status: DestinationValidationStatus,
): DestinationValidationStateOption {
    return (
        DESTINATION_VALIDATION_STATUSES.find(
            (option) => option.value === status,
        ) ?? DESTINATION_VALIDATION_STATUSES[0]
    );
}

/**
 * The state caption — the minimum a member needs that the badge beside it does
 * not already say, so it is null for Validated and for a never-sent
 * Unvalidated. Wording is design-18 Screen 2's, as cut by the Owner; see
 * docs/fixes/destinations-table-validation-column-width.md for what went and
 * what each state still has to carry. Timestamps fall back to bare wording
 * when absent, so no caption renders "Invalid Date".
 */
export function destinationValidationCaption(
    validation: DestinationValidation,
): string | null {
    switch (validation.status) {
        case 'unvalidated':
            // Failed-send and never-sent share a badge but not a remedy (AC35).
            return validation.last_send_failure
                ? `Send failed — ${SEND_FAILURE_REASONS[validation.last_send_failure]}.`
                : null;
        case 'pending':
            // AC34 names Pending: who must act, and by when.
            return (
                (validation.last_send_status !== null
                    ? `Responded ${validation.last_send_status}. `
                    : '') +
                'Awaiting approval at this address' +
                (validation.challenge_expires_at
                    ? ` — expires ${formatTimestamp(validation.challenge_expires_at)}.`
                    : '.')
            );
        case 'expired':
            return validation.challenge_expires_at
                ? `Expired ${formatTimestamp(validation.challenge_expires_at)}.`
                : null;
        case 'validated':
            return null;
    }
}

/**
 * The rate-limited line that replaces the Validate button (design-18 Screen
 * 2, Flow D) — names which limit was reached and the exact time it clears,
 * never a dead control and never a silent no-op.
 */
export function destinationValidationBlockedCaption(blocked: {
    description: string;
    until: string;
}): string {
    return `${blocked.description} reached. Try again ${formatTimestamp(blocked.until)}.`;
}
