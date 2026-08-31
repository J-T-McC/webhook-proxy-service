<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Challenge Lifetime (days)
    |--------------------------------------------------------------------------
    |
    | How long a validation link remains usable (PRD-18 AC22). Seven days by
    | Product Manager ruling: the person who approves a destination is usually
    | not the person who added it, and often not in the same company, so a
    | 24-hour window would expire for organisational rather than technical
    | reasons — and every expiry costs another request to an external host.
    |
    | There is no extension and no separate resend: a fresh Validate mints a new
    | nonce, which voids the previous link.
    |
    */

    'challenge_ttl_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Outbound Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Timeout for the validation challenge request. Shorter than delivery's, on
    | purpose: a challenge is a small fixed payload to an endpoint that has not
    | yet proven it wants traffic, and a member is waiting on the result.
    |
    */

    'timeout_seconds' => 10,

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | PRD-18 AC21. These bound the abuse the Validate button represents: it
    | sends to an arbitrary URL, which is the vector this whole feature exists
    | to close. Product Manager defaults — tightenable on Principal Engineer
    | advice, but not raisable without the Project Owner.
    |
    */

    'rate_limits' => [
        'per_destination_per_5_minutes' => 1,
        'per_destination_per_day' => 10,
        'per_team_per_day' => 100,
    ],

];
