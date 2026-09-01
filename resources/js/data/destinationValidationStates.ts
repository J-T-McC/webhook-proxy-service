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
    /**
     * The outcome of the most recent validation send (T19; AC35). Exactly one
     * is ever set: `last_send_status` is the HTTP status a destination that
     * answered returned, `last_send_failure` a key naming why a send never
     * reached one. Both are null for a destination that has never been sent a
     * challenge, and for one whose URL changed since — the outcome described
     * the old address.
     */
    last_send_status: number | null;
    last_send_failure: DestinationValidationSendFailure | null;
    /**
     * The rate limit currently blocking a send, or null when a send is
     * allowed (T16; AC21, design-18 Flow D). When set, the row replaces the
     * Validate button with {@link destinationValidationBlockedCaption} —
     * never a disabled button with no explanation.
     */
    send_blocked: {
        description: string;
        until: string;
    } | null;
}

/**
 * Why the most recent validation send never reached the destination — the
 * value union mirroring the PHP `DestinationValidationSendFailure` enum
 * (`app/Enums/DestinationValidationSendFailure.php`), which is authoritative.
 */
export type DestinationValidationSendFailure =
    'address_refused' | 'unreachable' | 'redirected';

/**
 * design-18's failure-reason copy, verbatim (AC18, AC20, AC35). Plain language
 * and never implementation jargon: the backend stores a key precisely so the
 * sentence lives here with the rest of the validation wording.
 *
 * `address_refused` is deliberately not named as an internal-address rule. The
 * member's remedy is the same either way — fix the URL — so the copy does not
 * distinguish the reason beyond this.
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
 * not already say. Null where the badge says everything.
 *
 * **Cut to this by Owner ruling, 2026-09-01**, in two passes: first shortened
 * from design-18 Screen 2's original wording, then cut again to drop the
 * handholding. AC34 reserves wording and presentation to the Designer and
 * freezes only the obligation — "that each state carries this is not" — and the
 * Owner dropped the Designer gate for this item, so the copy is theirs.
 *
 * What each state still has to carry, and why:
 *
 * - **Validated** and **Unvalidated, never sent** carry nothing. Neither asks
 *   anything of anybody that the badge and the Validate button beside it do not
 *   already say, so AC34 has nothing to require of them.
 * - **Unvalidated, last send failed** must name the reason. This is the one
 *   distinction AC35 exists to make: "Unvalidated" alone cannot tell a member
 *   whether nothing was ever sent or whether a send failed, and those have
 *   different remedies.
 * - **Pending** is the one state AC34 spells out — "that somebody at the
 *   destination must open a link and by when" — so it keeps a clause naming who
 *   must act and the expiry, plus the status the destination returned (AC35).
 * - **Expired** keeps only its date. "Nobody approved in time" is what the
 *   Expired badge means, and "send a new one" is what the button beside it is.
 *
 * Timestamps fall back to the bare wording when absent, so a caption never
 * renders "Invalid Date" against a row backfilled without challenge timestamps.
 */
export function destinationValidationCaption(
    validation: DestinationValidation,
): string | null {
    switch (validation.status) {
        case 'unvalidated':
            return validation.last_send_failure
                ? `Send failed — ${SEND_FAILURE_REASONS[validation.last_send_failure]}.`
                : null;
        case 'pending':
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
