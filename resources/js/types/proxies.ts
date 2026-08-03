export type ProxyMode = 'simple' | 'enhanced';
export type HttpMethod = 'POST' | 'PUT';

/** Page-level proxy affordances for the acting user (camelCase — a DTO share, not a Resource). */
export interface ProxyPermissions {
    canCreateProxy: boolean;
    canViewProxy: boolean;
}

/** Per-record edit/delete affordances, computed from the policy on ProxyResource. */
export interface ProxyCan {
    update: boolean;
    delete: boolean;
}

export interface ProxyListItem {
    id: number;
    name: string;
    mode: ProxyMode;
    ingest_url: string;
    can: ProxyCan;
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
    destinations: ProxyDestination[];
    can: ProxyCan;
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
