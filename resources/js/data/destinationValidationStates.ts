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
}

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
 * The state caption (design-18 Screen 2, reused verbatim on Screen 1) — what
 * this state means and what is expected of whom next (AC34). Timestamps fall
 * back to the bare wording when absent, so a caption never renders
 * "Invalid Date" against a row backfilled without challenge timestamps.
 */
export function destinationValidationCaption(
    validation: DestinationValidation,
): string {
    switch (validation.status) {
        case 'unvalidated':
            return 'No validation challenge has been sent yet.';
        case 'pending':
            return (
                sentPhrase('Sent', validation.challenge_sent_at) +
                ' — waiting on someone at this address to approve it.' +
                (validation.challenge_expires_at
                    ? ` Expires ${formatTimestamp(validation.challenge_expires_at)}.`
                    : '')
            );
        case 'expired':
            return (
                sentPhrase('The link sent', validation.challenge_sent_at) +
                (validation.challenge_expires_at
                    ? ` expired ${formatTimestamp(validation.challenge_expires_at)}`
                    : ' expired') +
                ' — nobody approved it in time. Send a new one to try again.'
            );
        case 'validated':
            return (
                (validation.approved_at
                    ? `Approved ${formatTimestamp(validation.approved_at)}.`
                    : 'Approved.') + ' This destination receives events.'
            );
    }
}

function sentPhrase(prefix: string, sentAt: string | null): string {
    return sentAt ? `${prefix} ${formatTimestamp(sentAt)}` : prefix;
}
