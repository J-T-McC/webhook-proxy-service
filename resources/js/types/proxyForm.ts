import type {
    DestinationRow,
    ProcessingMode,
    ProxyMode,
    ProxyResponseStatus,
    RetryBackoffStrategy,
} from '@/types/proxies';

/**
 * The proxy create/edit form's in-session shape.
 *
 * The response and retry fields are held as **strings** because they back
 * text inputs and selects: an empty string means "unconfigured" and is
 * normalised back to `null` on submit (see `@/lib/proxyFormPayload`), so
 * leaving a field at its default persists NULL rather than a literal.
 */
export interface ProxyFormData {
    name: string;
    mode: ProxyMode;
    processing_mode: ProcessingMode;
    response_status: string;
    response_body: string;
    retry_attempt_limit: string;
    retry_backoff_strategy: string;
    sensitive_fields: string[];
    destinations: DestinationRow[];
}

/**
 * The proxy's **persisted** configuration, as the form is seeded from it at
 * mount. Never mutated in session — the retry fieldset re-seeds from this
 * when a member switches Simple back to Enhanced (plan-07 § Technical ruling
 * 4(b), Revision A), which is only correct because it holds persisted values
 * and never anything typed this session.
 */
export interface ProxyFormInitial {
    name: string;
    mode: ProxyMode;
    processingMode: ProcessingMode;
    responseStatus: ProxyResponseStatus | null;
    responseBody: string | null;
    retryAttemptLimit: number | null;
    retryBackoffStrategy: RetryBackoffStrategy | null;
    /** This proxy's own AC13 additions — never the default list. */
    sensitiveFields: string[];
    destinations: DestinationRow[];
}
