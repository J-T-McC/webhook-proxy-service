export type ProxyMode = 'simple' | 'enhanced';
export type HttpMethod = 'POST' | 'PUT';

export interface ProxyListItem {
    id: number;
    name: string;
    mode: ProxyMode;
    ingest_url: string;
}

export interface ProxyDestination {
    id: number;
    url: string;
    http_method: HttpMethod;
}

export interface ProxyDetail {
    id: number;
    name: string;
    mode: ProxyMode;
    ingest_url: string;
    destinations: ProxyDestination[];
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
