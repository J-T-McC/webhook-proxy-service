import type { ProxyDeliveryStatus } from '@/data/proxyDeliveryStates';
import type { ProxyPayloadState } from '@/data/proxyPayloadStates';
import type { ProcessingMode } from '@/data/proxyProcessingModes';
import type { ProxyResponseStatus } from '@/data/proxyResponseStatuses';
import type { RetryBackoffStrategy } from '@/data/proxyRetryBackoffStrategies';

export type ProxyMode = 'simple' | 'enhanced';
export type HttpMethod = 'POST' | 'PUT';

/**
 * The `security` prop (mirrors `ProxySecurityResource` exactly, T22/T32) —
 * status only, never a value, never a length (AC20, AC33).
 * Present only on `show()`/`edit()` (Technical ruling 3); `Create.vue` never
 * receives this prop at all (`ProxyForm.vue`'s `security` prop is optional).
 * `destinations` (T32; Technical ruling 4) is keyed by destination id and
 * covers every destination the proxy has, including a soft-deleted one with
 * historical traffic — the same id set `Show.vue`'s analytics-sourced
 * Destinations table (T33) can render.
 */
export interface ProxySecurity {
    /**
     * Outbound signing status (T38; mirrors `ProxySecurityResource`'s
     * `signing` key exactly) — one object on the shared prop, never a value
     * and never a per-destination field (PRD-10 `## Amendment B` ruling 1:
     * signing is proxy-level, shared by every destination). `enabled` plays a
     * presence-only role; a proxy that was enabled and then disabled reads
     * identically to one that was never enabled (`SecretStore::disable()`
     * deletes every row).
     */
    signing: {
        enabled: boolean;
        generated_at: string | null;
        overlap_expires_at: string | null;
    };
    destinations: Record<
        number,
        {
            has_credential: boolean;
            credential_changed_at: string | null;
        }
    >;
}

// Re-exported so existing `@/types/proxies` imports keep working; each union is
// derived from its shared data const (single source of truth) — `ProcessingMode`
// from `@/data/proxyProcessingModes`, `ProxyResponseStatus` from
// `@/data/proxyResponseStatuses`, `RetryBackoffStrategy` from
// `@/data/proxyRetryBackoffStrategies` — not hand-maintained here.
export type { ProcessingMode, ProxyResponseStatus, RetryBackoffStrategy };

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
    /** Replay a retained event — all three roles, no ownership limit (ADR-017 Decision 4). */
    canReplayProxy: boolean;
}

export interface ProxyListItem {
    id: number;
    name: string;
    mode: ProxyMode;
    /** Per-proxy processing mode (ADR-011). */
    processing_mode: ProcessingMode;
    /**
     * Item #15 (pause and resume dispatch), AC14: null means never
     * paused/currently resumed; a value is both the two-state signal and
     * the "since when" timestamp. Shown wherever a proxy is presented.
     */
    paused_at: string | null;
    ingest_url: string;
    /** User-defined upstream response config; null = unconfigured (resolver returns 202). */
    response_status: ProxyResponseStatus | null;
    response_body: string | null;
    /**
     * The per-proxy override in force; null whenever the system default
     * governs, including a Simple proxy holding a dormant policy (AC14(b);
     * ADR-018 Decision 4) — never the raw column. The Edit-only carve-out is
     * {@link ProxyFormProxy}.
     */
    retry_attempt_limit: number | null;
    retry_backoff_strategy: RetryBackoffStrategy | null;
    /** This proxy's own AC13 additions to the fixed AC12 default list — never
     * the default list itself (T10/T12). */
    sensitive_fields: string[];
    /** Did the acting user create this proxy (snake_case — a Resource field, ADR-009 Amendment B). */
    is_creator: boolean;
}

export interface ProxyDestination {
    id: number;
    url: string;
    http_method: HttpMethod;
}

/**
 * A destination row as edited in the create/edit form (id absent for new
 * rows). `credential_header_name`/`credential_secret` are the two writable
 * credential fields (T29, T30); `has_credential`/`credential_changed_at` are
 * mount-seeded, read-only display flags (T30, mirrors `DestinationResource`)
 * — never mutated in-session and never meaningfully read server-side (no
 * validation rule matches either key, so `FormRequest::validated()` drops
 * them even if submitted). `credential_replacing`/`credential_removed` are
 * local UI-only state (T30's per-row Replace click, T31's Remove credential
 * click — distinct from `credential_secret` itself, since a proxy can have
 * many destination rows unlike the proxy's single outbound signing secret,
 * Screen 6, ADR-026 having withdrawn the inbound verification secret this
 * comment once compared against) — neither is read server-side;
 * `remove_credential` (the real, submitted
 * signal T31 derives from `credential_removed` at submit time, plan-10
 * § Revision A technical ruling 15) is added by `ProxyForm.vue`'s
 * `transform()`, not carried on this type, since it is never part of the
 * in-session row shape itself.
 */
export interface DestinationRow {
    id?: number | null;
    url: string;
    http_method: string;
    credential_header_name?: string;
    credential_secret?: string;
    has_credential?: boolean;
    credential_changed_at?: string | null;
    credential_replacing?: boolean;
    credential_removed?: boolean;
}

export interface ProxyDetail {
    id: number;
    name: string;
    mode: ProxyMode;
    /** Per-proxy processing mode (ADR-011). */
    processing_mode: ProcessingMode;
    /**
     * Item #15 (pause and resume dispatch), AC14: null means never
     * paused/currently resumed; a value is both the two-state signal and
     * the "since when" timestamp.
     */
    paused_at: string | null;
    ingest_url: string;
    /** User-defined upstream response config; null = unconfigured (resolver returns 202). */
    response_status: ProxyResponseStatus | null;
    response_body: string | null;
    /**
     * The per-proxy override in force; null whenever the system default
     * governs, including a Simple proxy holding a dormant policy (AC14(b);
     * ADR-018 Decision 4) — never the raw column. The Edit-only carve-out is
     * {@link ProxyFormProxy}.
     */
    retry_attempt_limit: number | null;
    retry_backoff_strategy: RetryBackoffStrategy | null;
    /** This proxy's own AC13 additions to the fixed AC12 default list — never
     * the default list itself (T10/T12). */
    sensitive_fields: string[];
    destinations: ProxyDestination[];
    /** Did the acting user create this proxy (snake_case — a Resource field, ADR-009 Amendment B). */
    is_creator: boolean;
}

/**
 * The proxy prop shape for the Edit form only (Amendment A; mirrors
 * `ProxyFormResource` exactly, T5/T6) — the single sanctioned carve-out from
 * {@link ProxyDetail}/{@link ProxyListItem}'s read-surface suppression rule.
 * Unlike those two, whose retry fields are `null` for a Simple proxy even
 * when it holds a dormant policy, this shape always carries the raw
 * persisted values regardless of mode, so the Edit form can pre-fill a
 * dormant policy the member left behind. `Edit.vue`'s prop type is this
 * interface, never `ProxyDetail`/`ProxyListItem`.
 */
export interface ProxyFormProxy {
    id: number;
    name: string;
    mode: ProxyMode;
    /** Per-proxy processing mode (ADR-011). */
    processing_mode: ProcessingMode;
    /** User-defined upstream response config; null = unconfigured (resolver returns 202). */
    response_status: ProxyResponseStatus | null;
    response_body: string | null;
    /** Raw persisted values, regardless of mode (Amendment A) — never null-suppressed. */
    retry_attempt_limit: number | null;
    retry_backoff_strategy: RetryBackoffStrategy | null;
    /** This proxy's own AC13 additions to the fixed AC12 default list — never
     * the default list itself (T10/T12). */
    sensitive_fields: string[];
    destinations: DestinationRow[];
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

/** Origin of a `deliveries` row (`App\Enums\DispatchKind`). */
export type DispatchKind = 'original' | 'replay';

/** Lifecycle status of a delivery attempt (`App\Enums\AttemptStatus`). */
export type AttemptStatus = 'dispatched' | 'succeeded' | 'failed';

/**
 * One delivery-attempt row on the events read surface (mirrors
 * `DeliveryAttemptResource` exactly, T25) — immutable attempt facts only,
 * never payload content.
 */
export interface DeliveryAttempt {
    attempt_number: number;
    status: AttemptStatus;
    http_status: number | null;
    error_summary: string | null;
    started_at: string | null;
    duration_ms: number | null;
}

/**
 * One `deliveries` row — an original send or a replay batch (mirrors
 * `DeliveryResource` exactly, T25/T12-rider-2). Never `body`/`headers`
 * (AC22/AC25).
 *
 * `id`/`dispatch_uuid`/`created_at`/`next_attempt_at`/`attempt_limit` are
 * `null` only for a pre-#6 legacy-fallback row (an event captured before
 * this feature, no real `deliveries` row exists — `WebhookEventResource`'s
 * derived presentation). `attempts` is `undefined` when the caller didn't
 * eager-load `deliveryAttempts` (the events **list** page, T26 — the
 * `whenLoaded` key is omitted entirely from the JSON), and `null` for a
 * legacy-fallback row.
 *
 * `created_at` (review-06 Minor 5, rider 2) is the row's own creation
 * timestamp — the events-detail replay-group label and newest-first
 * ordering (`events/Show.vue`) derive from it directly.
 */
export interface Delivery {
    id: number | null;
    dispatch_uuid: string | null;
    kind: DispatchKind;
    status: ProxyDeliveryStatus;
    created_at: string | null;
    next_attempt_at: string | null;
    attempt_limit: number | null;
    destination: {
        http_method: HttpMethod;
        url: string;
    };
    attempts?: DeliveryAttempt[] | null;
}

/**
 * The events read-surface descriptor shape shared by the list and detail
 * pages (mirrors `WebhookEventResource` exactly, T25). Never `body`/
 * `headers` under any state — content is served only by the fetch-on-reveal
 * payload endpoint (T28/T34).
 */
export interface WebhookEventListItem {
    id: number;
    received_at: string;
    byte_size: number;
    content_type: string | null;
    method: string;
    payload_state: ProxyPayloadState;
    deliveries: Delivery[];
}

/** Same resource shape as {@link WebhookEventListItem} (T25's single resource, both endpoints). */
export type WebhookEventDetail = WebhookEventListItem;
