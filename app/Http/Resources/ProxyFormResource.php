<?php

namespace App\Http\Resources;

use App\Models\Proxy;
use Illuminate\Http\Request;

/**
 * The proxy prop shape for the Edit form only (Amendment A, ADR-018 Decision 4).
 *
 * The single sanctioned carve-out from `ProxyResource`'s read-surface
 * suppression rule (AC14(b)): the Edit form must pre-fill both retry fields
 * with their raw persisted values regardless of mode, so a member who
 * downgraded to Simple and returns to Edit sees the dormant policy they left
 * behind, not a blank field. `ProxyController::edit()` is the ONLY caller —
 * a second caller of this resource is a review finding, not a refactor.
 *
 * AC14(b)'s four binding conditions this satisfies:
 *   (i)   the values are never lost by a Simple-mode save (T1's omission rule);
 *   (ii)  they are visible ONLY on the Edit form, nowhere else (AC14(b) lead);
 *   (iii) an upgrade-with-tuning save persists the tuned value, not the stale
 *         pre-filled one (server-side, T2);
 *   (iv)  re-saving an already-Simple proxy leaves them untouched (T1/T2).
 *
 * @mixin Proxy
 */
class ProxyFormResource extends ProxyResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'retry_attempt_limit' => $this->retry_attempt_limit,
            'retry_backoff_strategy' => $this->retry_backoff_strategy?->value,
        ];
    }
}
