import type { BadgeVariants } from '@/components/ui/badge';
import type { DataOption } from '@/types/data';

/**
 * A single event queue status option. Extends the standard {@link DataOption}
 * with `variant` — the `Badge` variant this state renders with.
 */
export interface WebhookQueueStatusOption extends DataOption<string> {
    variant: NonNullable<BadgeVariants['variant']>;
}

/**
 * Single source of truth for the event queue view's three-value display
 * status (`EventQueueController`/`WebhookEventQueueResource`). `expired`
 * shares its label and variant with the existing "Expired" payload-state
 * badge (`proxyPayloadStates.ts`) — the same word should read identically
 * everywhere it appears. This is a DISPLAY value, not the raw
 * `App\Enums\WebhookEventStatus` column — that enum has only `pending`/
 * `dispatched`; `expired` is computed server-side from
 * `payload_cleaned_at`.
 */
export const WEBHOOK_QUEUE_STATUSES = [
    { value: 'pending', label: 'Pending', variant: 'outline' },
    { value: 'dispatched', label: 'Dispatched', variant: 'secondary' },
    { value: 'expired', label: 'Expired', variant: 'outline' },
] as const satisfies readonly WebhookQueueStatusOption[];

/**
 * The event queue's display status — the value union derived from
 * {@link WEBHOOK_QUEUE_STATUSES} (currently `'pending' | 'dispatched' |
 * 'expired'`).
 */
export type WebhookQueueStatus =
    (typeof WEBHOOK_QUEUE_STATUSES)[number]['value'];

/**
 * The badge option (label + variant) for an event queue status. Falls back
 * to "Pending" for a value outside the known set.
 */
export function webhookQueueStatusOption(
    status: WebhookQueueStatus,
): WebhookQueueStatusOption {
    return (
        WEBHOOK_QUEUE_STATUSES.find((option) => option.value === status) ??
        WEBHOOK_QUEUE_STATUSES[0]
    );
}
