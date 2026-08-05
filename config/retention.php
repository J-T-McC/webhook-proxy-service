<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention Window (days)
    |--------------------------------------------------------------------------
    |
    | The team-level payload retention window (AC2). 30 days, fixed for every
    | team — NOT a per-team, per-plan, or user-facing lever (AC3). Env-override
    | exists for dev/test convenience only; `RetentionPolicy` (app/Services) is
    | the only consumer allowed to read this key (ADR-012 Decision 2).
    |
    */

    'days' => (int) env('RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Garbage-Collector Purge Batch Size
    |--------------------------------------------------------------------------
    |
    | Per-team, per-run LIMIT on the number of collectable `webhook_events` rows
    | `PurgeExpiredPayloads` selects under holds H0-H4 (ADR-012 Decision 4). Bounds
    | a single run so a bad pass is bounded; the loop re-selects until a batch
    | comes back short.
    |
    */

    'purge_batch' => (int) env('RETENTION_PURGE_BATCH', 500),

    /*
    |--------------------------------------------------------------------------
    | Dispatch Horizon (minutes)
    |--------------------------------------------------------------------------
    |
    | Hold H4: an event with zero `delivery_attempts` rows is eligible for
    | erasure only once it is older than this horizon — bounding the window in
    | which an Async event has been captured but its processing job has not yet
    | started (ADR-012 Decision 4, plan Risk 3).
    |
    */

    'dispatch_horizon_minutes' => (int) env('RETENTION_DISPATCH_HORIZON_MINUTES', 60),

];
