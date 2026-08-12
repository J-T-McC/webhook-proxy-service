<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Attempt Limit
    |--------------------------------------------------------------------------
    |
    | The system-default number of delivery attempts (AC2) applied whenever a
    | proxy's `retry_attempt_limit` column is NULL. A **product value** (Owner
    | ruling Q-06-01b), not an engineering constant. `App\Services\RetryPolicy`
    | is the only consumer allowed to read this key (ADR-015 Decision 3).
    |
    */

    'default_attempt_limit' => (int) env('RETRY_DEFAULT_ATTEMPT_LIMIT', 5),

    /*
    |--------------------------------------------------------------------------
    | Maximum Attempt Limit
    |--------------------------------------------------------------------------
    |
    | The hard cap a per-proxy `retry_attempt_limit` override is clamped to
    | (AC2). A **product value** (Owner ruling Q-06-01b). `RetryPolicy` is the
    | only consumer (ADR-015 Decision 3).
    |
    */

    'max_attempt_limit' => (int) env('RETRY_MAX_ATTEMPT_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | Exponential Backoff — Base Delay (seconds)
    |--------------------------------------------------------------------------
    |
    | Delay before attempt 2 under the exponential strategy: `base ×
    | multiplier^(N-2)`, capped by `exponential_max_delay_seconds` (ADR-015
    | Decision 4). An engineering constant, bounded by the AC18 worst-case-span
    | guard test — not a user-facing lever.
    |
    */

    'exponential_base_seconds' => (int) env('RETRY_EXPONENTIAL_BASE_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Exponential Backoff — Multiplier
    |--------------------------------------------------------------------------
    |
    | Growth factor applied per attempt under the exponential strategy
    | (ADR-015 Decision 4). An engineering constant, bounded by the AC18
    | worst-case-span guard test.
    |
    */

    'exponential_multiplier' => (int) env('RETRY_EXPONENTIAL_MULTIPLIER', 5),

    /*
    |--------------------------------------------------------------------------
    | Exponential Backoff — Max Delay (seconds)
    |--------------------------------------------------------------------------
    |
    | Per-delay cap for the exponential strategy — once the computed delay
    | reaches this value it stays flat for later attempts (ADR-015 Decision
    | 4). An engineering constant, bounded by the AC18 worst-case-span guard
    | test. Default 21600 (6 hours).
    |
    */

    'exponential_max_delay_seconds' => (int) env('RETRY_EXPONENTIAL_MAX_DELAY_SECONDS', 21600),

    /*
    |--------------------------------------------------------------------------
    | Fixed Backoff Interval (seconds)
    |--------------------------------------------------------------------------
    |
    | Constant delay between attempts under the fixed strategy (ADR-015
    | Decision 4). An engineering constant, bounded by the AC18 worst-case-span
    | guard test. Default 300 (5 minutes).
    |
    */

    'fixed_interval_seconds' => (int) env('RETRY_FIXED_INTERVAL_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Sweep Grace Period (seconds)
    |--------------------------------------------------------------------------
    |
    | Extra grace added on top of `next_attempt_at` before the scheduled
    | sweeper (belt/suspenders, ADR-015 Decision 5) treats a `retrying`
    | delivery as overdue and re-drives it. Env-overridable for dev/test
    | convenience only.
    |
    */

    'sweep_grace_seconds' => (int) env('RETRY_SWEEP_GRACE_SECONDS', 120),

];
