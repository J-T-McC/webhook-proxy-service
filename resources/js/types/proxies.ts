import type { ProxyResponseStatus } from '@/data/proxyResponseStatuses';

export type ProxyMode = 'simple' | 'enhanced';
export type HttpMethod = 'POST' | 'PUT';

// Re-exported so existing `@/types/proxies` imports keep working; the union is
// derived from the shared response-status const (single source of truth) in
// `@/data/proxyResponseStatuses`, not hand-maintained here.
export type { ProxyResponseStatus };

/**
 * Page-level proxy affordances for the acting user (camelCase — a DTO share, not a
 * Resource). The client composes each proxy's edit/delete visibility from these
 * booleans plus the per-record `is_creator` flag (ADR-009 Amendment B) —
 * `canUpdateProxy && (is_creator || canUpdateAnyProxy)`, likewise for delete.
 */
export interface ProxyPermissions {
    canCreateProxy: boolean;
    canViewProxy: boolean;
    canUpdateProxy: boolean;
    canDeleteProxy: boolean;
    canUpdateAnyProxy: boolean;
    canDeleteAnyProxy: boolean;
}

export interface ProxyListItem {
    id: number;
    name: string;
    mode: ProxyMode;
    ingest_url: string;
    /** User-defined upstream response config; null = unconfigured (resolver returns 202). */
    response_status: ProxyResponseStatus | null;
    response_body: string | null;
    /** Did the acting user create this proxy (snake_case — a Resource field, ADR-009 Amendment B). */
    is_creator: boolean;
}

export interface ProxyDestination {
    id: number;
    url: string;
    http_method: HttpMethod;
}

/** A destination row as edited in the create/edit form (id absent for new rows). */
export interface DestinationRow {
    id?: number | null;
    url: string;
    http_method: string;
}

export interface ProxyDetail {
    id: number;
    name: string;
    mode: ProxyMode;
    ingest_url: string;
    /** User-defined upstream response config; null = unconfigured (resolver returns 202). */
    response_status: ProxyResponseStatus | null;
    response_body: string | null;
    destinations: ProxyDestination[];
    /** Did the acting user create this proxy (snake_case — a Resource field, ADR-009 Amendment B). */
    is_creator: boolean;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}
