<?php

namespace App\Data;

use App\Services\SecretStore;
use Carbon\CarbonInterface;

/**
 * Non-secret status metadata for one `(proxy, purpose)` slot in
 * `proxy_secrets` — never a value, never a length (AC26, AC57).
 * {@see SecretStore::statusFor()} is the only producer, so `proxy_secrets`
 * still has exactly one reader/writer (plan-10 Technical ruling 14) even
 * though this DTO's consumer (`ProxySecurityResource`, T22) lives outside
 * that service. This app's `AppServiceProvider` calls
 * `Date::use(CarbonImmutable::class)`, so every Eloquent date cast
 * (including `ProxySecret::created_at`/`expires_at`) resolves to that class
 * at runtime — typed here against the shared `CarbonInterface` (both
 * `Carbon` and `CarbonImmutable` implement it) because Larastan's own
 * inference of a model's date-cast property still widens to a
 * `CarbonImmutable|Carbon` union despite that provider call.
 */
readonly class SecretStatus
{
    public function __construct(
        /** When the current row became current (Screen 4/Screen 1's "changed {date}"). */
        public CarbonInterface $changedAt,
        /** The running overlap's expiry, or null when no overlap is active. */
        public ?CarbonInterface $overlapExpiresAt,
    ) {
        //
    }
}
