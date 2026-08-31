<?php

namespace App\Http\Resources;

use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The per-destination shape shared by the proxy show/edit Inertia pages.
 *
 * Carries the credential's status fields (T30; AC30, AC33) directly — never
 * the value, never its length. This rides on `DestinationResource` itself
 * rather than the sibling `security` prop: unlike signing status
 * (SecretStore-derived, plan-10 Technical ruling 3), the credential
 * is a plain column on `destinations`, and the Edit form (the only consumer
 * of these three keys) already receives this resource's live-only
 * destinations through `ProxyFormResource`. The Show page's Destinations
 * table (Screen 5, T32/T33) does NOT read these fields — it must also cover
 * a soft-deleted destination with historical traffic, which this live-only
 * relation excludes, so it uses the separate `security.destinations` map
 * instead (plan-10 Technical ruling 4).
 *
 * @mixin Destination
 */
class DestinationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'http_method' => $this->http_method->value,
            // Already-visible, non-secret column — safe to expose (AC30).
            'credential_header_name' => $this->credential_header_name,
            // Presence only, derived from the timestamp rather than reading
            // (and decrypting) `credential_secret` itself — the same
            // not-touching-the-value discipline `SecretStore::statusFor()`
            // already establishes. All three credential columns are always
            // written/cleared together (T29, T31), so this is exact.
            'has_credential' => $this->credential_set_at !== null,
            'credential_changed_at' => $this->credential_set_at,
            // Display status only (T15 vocabulary; AC31 — shown wherever a
            // destination is presented; review-18 finding 8). Never the
            // nonce, never the challenge timestamps — surfaces needing those
            // read the `security.destinations` map instead.
            'validation_status' => $this->validationStatus()->value,
        ];
    }
}
