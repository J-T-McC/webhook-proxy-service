import type { BadgeVariants } from '@/components/ui/badge';
import type { DataOption } from '@/types/data';

/**
 * A single payload-state option. Extends the standard {@link DataOption} with
 * `variant` — the `Badge` variant this state renders with, so the same
 * value/label/variant triple is never re-declared per component.
 */
export interface ProxyPayloadStateOption extends DataOption<string> {
    variant: NonNullable<BadgeVariants['variant']>;
}

/**
 * Single source of truth for the three states a stored payload can be in
 * (PRD-05 AC21; design-06 Screen 2/3) — shared by the events list badge and
 * the event detail badge, so the same state reads identically in both.
 *
 * Values MUST stay in sync with the PHP `StoredPayloadState` enum
 * (`app/Enums/StoredPayloadState.php`) — the backend is authoritative (do not
 * add a value here without adding the enum case first). `never_captured` is
 * vocabulary-complete but not expected in practice (design-06 Screen 2 note —
 * every event's raw payload is captured unconditionally at ingest, #3 AC7);
 * it exists so the badge fails safe rather than mis-rendering as "Expired".
 */
export const PROXY_PAYLOAD_STATES = [
    { value: 'retained', label: 'Retained', variant: 'secondary' },
    { value: 'cleaned', label: 'Expired', variant: 'outline' },
    { value: 'never_captured', label: 'Not captured', variant: 'outline' },
] as const satisfies readonly ProxyPayloadStateOption[];

/**
 * A stored payload's state — the value union derived from
 * {@link PROXY_PAYLOAD_STATES} (currently `'retained' | 'cleaned' |
 * 'never_captured'`).
 */
export type ProxyPayloadState = (typeof PROXY_PAYLOAD_STATES)[number]['value'];

/**
 * The badge option (label + variant) for a stored payload state. Falls back
 * to the "Not captured" option for a value outside the known set, matching
 * this badge's own fail-safe posture rather than rendering nothing.
 */
export function proxyPayloadStateOption(
    state: ProxyPayloadState,
): ProxyPayloadStateOption {
    return (
        PROXY_PAYLOAD_STATES.find((option) => option.value === state) ??
        PROXY_PAYLOAD_STATES[2]
    );
}
