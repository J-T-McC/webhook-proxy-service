import type { DataOption } from '@/types/data';

/**
 * A single user-configurable upstream response-status option. Extends the
 * standard {@link DataOption} with `emptyBody` — whether selecting this status
 * forces an empty response body (AC12: 204 No Content).
 */
export interface ProxyResponseStatusOption extends DataOption<number> {
    /** Selecting this status forces an empty response body (204 No Content). */
    emptyBody: boolean;
}

/**
 * Single source of truth for the closed set of user-configurable upstream
 * response statuses — shared by the proxy form (select options + 204 empty-body
 * coupling) and the proxy detail page (status label + body-state branching), so
 * the same status reads identically in both.
 *
 * Values MUST stay in sync with the PHP validation set `Rule::in([200, 202, 204])`
 * in `StoreProxyRequest` / `UpdateProxyRequest`; the backend is authoritative
 * (do not add a value here without widening the server rule first).
 */
export const PROXY_RESPONSE_STATUSES = [
    { value: 200, label: '200 OK', emptyBody: false },
    { value: 202, label: '202 Accepted', emptyBody: false },
    { value: 204, label: '204 No Content', emptyBody: true },
] as const satisfies readonly ProxyResponseStatusOption[];

/**
 * User-configurable upstream response status — the value union derived from
 * {@link PROXY_RESPONSE_STATUSES} (currently `200 | 202 | 204`). null (elsewhere)
 * = unconfigured; the resolver returns the 202 Accepted default.
 */
export type ProxyResponseStatus =
    (typeof PROXY_RESPONSE_STATUSES)[number]['value'];

/**
 * Label for the unconfigured (default) state, shown as the sentinel select
 * option on the form and as the status label on the detail page when
 * `response_status` is null. Kept verbatim-consistent with the option labels
 * above so the "default" reads the same in both places.
 */
export const PROXY_RESPONSE_STATUS_DEFAULT_LABEL = 'Default (202 Accepted)';

/**
 * The label for a stored response status: null (unconfigured) → the default
 * label; a known status → its option label; anything else → the default label.
 */
export function proxyResponseStatusLabel(
    status: ProxyResponseStatus | null,
): string {
    return (
        PROXY_RESPONSE_STATUSES.find((option) => option.value === status)
            ?.label ?? PROXY_RESPONSE_STATUS_DEFAULT_LABEL
    );
}

/**
 * Does a stored status force an empty body (204 No Content, AC12)? Derived from
 * the option's `emptyBody` flag so the coupling lives with the data, never as a
 * bare `204` literal in the UI. null (unconfigured) does not force an empty body.
 */
export function proxyStatusForcesEmptyBody(
    status: ProxyResponseStatus | null,
): boolean {
    return PROXY_RESPONSE_STATUSES.some(
        (option) => option.emptyBody && option.value === status,
    );
}
