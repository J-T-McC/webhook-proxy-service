<?php

namespace App\Http\Resources;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Services\SecretStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `security` prop (plan-10 §API, Technical ruling 3) — status only,
 * never a value, never a length (AC20, AC26, AC28). A sibling prop on
 * `ProxyController::show()`/`edit()`, never a key on `ProxyResource` (which
 * also serves `index()`, unaffected by this feature) and never rendered on
 * `create()` (no proxy exists yet to have a status).
 *
 * Only the `verification` sub-object is built here (T22); `signing` and
 * `destinations` are added in later, out-of-scope-for-this-batch tasks
 * (T32, T41) per the plan's own milestone split.
 *
 * @mixin Proxy
 */
class ProxySecurityResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Status metadata comes from SecretStore::statusFor() (T22's own
        // addition) — never a direct proxy_secrets query — keeping
        // SecretStore the single reader of that table (plan-10 Technical
        // ruling 14). Mirrors ProxyResource's existing app(RetryPolicy::class)
        // inline-resolve convention rather than a constructor dependency,
        // since JsonResource collections are instantiated by the framework.
        $status = app(SecretStore::class)->statusFor($this->resource, SecretPurpose::Verification);

        return [
            'verification' => [
                // null when verification is not required (AC24) — the
                // closed two-case scheme, never a third value (AC50).
                'scheme' => $this->verification_scheme?->value,
                // Visible only meaningfully under shared-secret, but always
                // present here; the client renders it only for that scheme.
                'header_name' => $this->verification_header_name,
                // Presence only — never the secret's value or length.
                'secret_set' => $status !== null,
                'secret_changed_at' => $status?->changedAt,
                'overlap_expires_at' => $status?->overlapExpiresAt,
            ],
        ];
    }
}
