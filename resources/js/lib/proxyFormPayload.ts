import type { ProxyFormData } from '@/types/proxyForm';

/**
 * Normalises the proxy form's in-session state into the payload the server
 * accepts. Passed to Inertia's `form.transform()`, so it runs on every
 * submission and never mutates the form itself.
 */
export function proxyFormPayload(data: ProxyFormData) {
    return {
        ...data,
        // Blank → null (unconfigured); a set status is sent as a number.
        response_status:
            data.response_status === '' ? null : Number(data.response_status),
        response_body: data.response_body === '' ? null : data.response_body,
        // Same idiom for the retry fields: blank/sentinel → null (unconfigured).
        // A Simple-mode submission ALWAYS sends null for both, regardless of
        // the fields' in-memory state — required because the Edit form's
        // initial state is seeded from the persisted values whatever the
        // proxy's mode (T5/T6), while the retry fieldset's mode watcher only
        // clears fields on an in-session change, never on mount. Without this,
        // opening Edit on a Simple proxy holding a dormant retry policy and
        // saving without touching Mode would submit the dormant values
        // alongside mode: 'simple' and be 422'd by prohibited_if on a field
        // the form does not render (plan Risk 4). This is a normalisation,
        // not a gate — the server's omission rule (T1) is authoritative
        // regardless of what a Simple submission carries.
        retry_attempt_limit:
            data.mode === 'simple' || data.retry_attempt_limit === ''
                ? null
                : Number(data.retry_attempt_limit),
        retry_backoff_strategy:
            data.mode === 'simple' || data.retry_backoff_strategy === ''
                ? null
                : data.retry_backoff_strategy,
        // T31 (correction B3; plan-10 § Revision A, technical ruling 15) —
        // the Remove credential signal is derived here, at submit time, not
        // stored as a submitted field on the row itself: `true` whenever
        // this row's Remove credential was clicked this session AND the
        // member has not since typed a new secret into the now-blank field
        // (a later, deliberate act that supersedes the staged removal —
        // "typing into an unconfigured row has always meant 'set this
        // secret'"). `credential_secret` keeps exactly one meaning
        // regardless (a new value, or absent means leave unchanged); this
        // never rewrites it.
        destinations: data.destinations.map((row) => ({
            ...row,
            remove_credential:
                row.credential_removed === true && row.credential_secret === '',
        })),
    };
}
