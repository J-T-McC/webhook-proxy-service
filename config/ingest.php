<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest Base URL
    |--------------------------------------------------------------------------
    |
    | The absolute base URL used to build a proxy's public ingest endpoint
    | (`{ingest.url}/ingest/{token}`). This is the *sole* source of the ingest
    | host — the request `Host` header is never used to build ingest URLs
    | (ADR-006 Host-header injection guard). Falls back to the application URL
    | when `INGEST_URL` is not set.
    |
    */

    'url' => env('INGEST_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Ingest Body-Size Cap (bytes)
    |--------------------------------------------------------------------------
    |
    | Maximum accepted ingest request body size. Requests over this cap are
    | rejected with 413. PLACEHOLDER — deliberately very high (50 MB), NOT a
    | risk-tuned value; revisit before MVP / public exposure.
    |
    */

    'max_body_bytes' => (int) env('INGEST_MAX_BODY_BYTES', 52_428_800),

    /*
    |--------------------------------------------------------------------------
    | Per-Token Ingest Rate Limit (requests / minute)
    |--------------------------------------------------------------------------
    |
    | Per-token throttle applied to the ingest endpoint. Over-rate requests are
    | rejected with 429. PLACEHOLDER — deliberately very high, NOT risk-tuned;
    | revisit before MVP / public exposure.
    |
    */

    'rate_limit_per_minute' => (int) env('INGEST_RATE_LIMIT_PER_MINUTE', 6_000),

    /*
    |--------------------------------------------------------------------------
    | Ingest Proxy-Lookup Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Backstop expiry for `ProxyLookup`'s cached proxy row, NOT the mechanism
    | that keeps it correct. `ProxyObserver` forgets the entry on every save,
    | delete and restore, which is what makes a pause or a soft delete take
    | effect at once rather than at the end of this window. The TTL only bounds
    | an entry that somehow outlives its row.
    |
    */

    'proxy_cache_ttl_seconds' => (int) env('INGEST_PROXY_CACHE_TTL_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Response-Body Size Cap (bytes)
    |--------------------------------------------------------------------------
    |
    | Maximum accepted size for a proxy's user-defined ingest *response* body
    | (the acknowledgement returned immediately on ingest, independent of
    | delivery). Distinct from `max_body_bytes`, which caps the inbound request
    | body. Enforced as a validation rule when configuring a proxy. Default is
    | 8 KiB — a response contract is an acknowledgement / challenge-echo, not a
    | data channel.
    |
    */

    'response_body_max_bytes' => (int) env('INGEST_RESPONSE_BODY_MAX_BYTES', 8192),

    /*
    |--------------------------------------------------------------------------
    | FIFO Claim Lease (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a FIFO advancer's claim on a `fifo_dispatches` row is considered
    | live (ADR-011). `AdvanceProxyFifoQueue` stamps `lease_expires_at = now() +
    | this`; `SweepStalledFifoDispatches` treats a `claimed` row past its lease
    | as an orphaned claim (crashed worker) and resets it to `pending`. Should
    | comfortably exceed the worst-case single-event settlement time.
    |
    | Must stay ABOVE the `default` Horizon supervisor's `timeout` (ADR-020
    | §Decision 4, link L2, enforced by tests/Unit/Config/QueueTimingTest.php)
    | — a live advancer's claim must never become reapable while it is still
    | running. Read only through `AdvanceProxyFifoQueue::leaseSeconds()`
    | (ADR-020 Decision 5): a blank, zero, negative or non-numeric value throws
    | rather than silently coercing to a lease of zero.
    |
    */

    'fifo_lease_seconds' => (int) env('INGEST_FIFO_LEASE_SECONDS', 90),

    /*
    |--------------------------------------------------------------------------
    | Webhooks Delivery Queue
    |--------------------------------------------------------------------------
    |
    | The dedicated queue name per-destination delivery jobs
    | (`DeliverToDestination`) are pushed onto, so fan-out delivery has its own
    | worker pool separate from advancing/sweeping work. Since ADR-020, every
    | delivery — Async and FIFO alike — is dispatched by reference onto this
    | queue; FIFO ordering is unaffected because it is enforced between
    | events, never between destinations within one event.
    |
    */

    'webhooks_queue' => env('INGEST_WEBHOOKS_QUEUE', 'webhooks'),

    /*
    |--------------------------------------------------------------------------
    | Delivery Loop Guard: Max Hops
    |--------------------------------------------------------------------------
    |
    | The delivery-loop guard's indirect-cycle bound (docs/briefs/delivery-loop
    | -guard.md): the `WebhookProxy-Hops` header this app stamps on every
    | outbound delivery (App\Support\OutboundHeaders::build(), inbound value
    | plus one) is compared here on ingest. A request arriving with an inbound
    | hop count at or above this limit is rejected with 508 Loop Detected,
    | before capture — no `webhook_event` row, no dispatch. Read via
    | App\Support\HopCount and IngestController.
    |
    | Clamped to a floor of 1, NOT `env()`'s built-in default: `env()`'s
    | second argument only applies when the key is absent, never when it is
    | present-and-blank — `INGEST_MAX_HOPS=` casts to `(int) '' === 0`, and a
    | limit of 0 rejects every inbound request with 508 before capture,
    | a silent, total ingest outage (review finding, same defect class as
    | review-05 Finding 1's `RETENTION_DAYS=` blank-cast). Deliberately a
    | clamp, not `PurgeExpiredPayloads::requirePositiveBatchSize()`'s
    | throwing idiom: that guard protects an irreversible mass-erasure path
    | where refusing to run is the safe direction; here, throwing would turn
    | every ingest request into a 500 — a worse outage than the one being
    | prevented. Keeping webhooks flowing is the safe direction on this path.
    | Do not "correct" this back to a bare `(int) env(...)` cast or to the
    | throwing idiom.
    |
    */

    'max_hops' => max(1, (int) env('INGEST_MAX_HOPS', 3)),

];
