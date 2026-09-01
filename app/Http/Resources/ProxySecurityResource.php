<?php

namespace App\Http\Resources;

use App\Actions\SendDestinationValidationChallenge;
use App\Enums\SecretPurpose;
use App\Models\Destination;
use App\Models\Proxy;
use App\Services\SecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Attributes\PreserveKeys;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `security` prop (plan-10 §API, Technical ruling 3) — status only,
 * never a value, never a length (AC20, AC33). A sibling prop on
 * `ProxyController::show()`/`edit()`, never a key on `ProxyResource` (which
 * also serves `index()`, unaffected by this feature) and never rendered on
 * `create()` (no proxy exists yet to have a status).
 *
 * `destinations` (T32; AC30, AC33; plan-10 Technical ruling 4); `signing`
 * (T38; AC54, AC57, AC58; plan-10 Technical ruling 4) here — status only, one
 * object on the shared prop, never a per-destination field, since Amendment B
 * re-grains signing to the proxy.
 *
 * `#[PreserveKeys]` is load-bearing, not decorative: `destinations`' keys
 * are destination ids — all-numeric — and `JsonResource`'s own
 * `removeMissingValues()` silently `array_values()`s (discarding the keys
 * entirely, turning the map into an unkeyed list) any nested array whose
 * keys are ALL numeric, unless this attribute is present. Verified directly
 * against a real HTTP response before adding this, not assumed.
 *
 * @mixin Proxy
 */
#[PreserveKeys]
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
        $store = app(SecretStore::class);
        $signingStatus = $store->statusFor($this->resource, SecretPurpose::Signing);

        return [
            // T38 — status only, one object on the shared prop (no per-destination
            // signing flag exists under Amendment B). `enabled` is presence-only;
            // a proxy that was enabled and then disabled (`SecretStore::disable()`
            // deletes every row, ADR-021 Decision 5) reads identically to one that
            // was never enabled — both have no live `signing` row.
            'signing' => [
                'enabled' => $signingStatus !== null,
                'generated_at' => $signingStatus?->changedAt,
                'overlap_expires_at' => $signingStatus?->overlapExpiresAt,
            ],
            // Destination credential presence (T32; Technical ruling 4) —
            // deliberately `withTrashed()`: the Show page's Destinations
            // table (T33) renders the union of live destinations and any
            // soft-deleted one with historical traffic (plan-11's
            // `DeliveryStatistics::destinationBreakdown()`), so the id set
            // this map must cover is a superset of the live relation alone.
            // Never on `DestinationBreakdownRow`/`DeliveryStatistics` — that
            // would make the analytics service read secret columns and
            // reopen a shape plan-11 certified.
            'destinations' => $this->destinations()
                ->withTrashed()
                // `validation_nonce` is deliberately not selected: the link it
                // feeds must never reach any member surface (#18 AC24), and not
                // loading the column makes leaking it impossible rather than
                // merely avoided.
                ->get(['id', 'team_id', 'credential_set_at', 'validation_state', 'validated_at', 'validation_challenge_sent_at', 'validation_challenge_expires_at', 'validation_last_send_status', 'validation_last_send_failure'])
                ->mapWithKeys(fn (Destination $destination): array => [
                    $destination->id => [
                        // Presence only, derived from the timestamp rather
                        // than reading (and decrypting) `credential_secret`
                        // itself — the same discipline `DestinationResource`
                        // (T30) and `SecretStore::statusFor()` already
                        // establish.
                        'has_credential' => $destination->credential_set_at !== null,
                        'credential_changed_at' => $destination->credential_set_at,
                        // T15 (AC31, AC32) — the display status, with Expired
                        // derived server-side by `validationStatus()` so every
                        // surface tells Expired from Unvalidated the same way
                        // (AC34) and no client re-implements the expiry rule.
                        'validation' => [
                            'status' => $destination->validationStatus()->value,
                            'approved_at' => $destination->validated_at,
                            'challenge_sent_at' => $destination->validation_challenge_sent_at,
                            'challenge_expires_at' => $destination->validation_challenge_expires_at,
                            // T16 (AC21, Flow D) — when a send limit blocks
                            // this destination, which one in plain language
                            // and when it clears, so the row can replace the
                            // Validate button with the reason rather than a
                            // dead control. Null when a send is allowed.
                            // T19 (AC35) — the outcome of the last send, so
                            // "never arrived", "arrived and was rejected" and
                            // "nobody has opened it" read differently. Exactly
                            // one is ever set; the failure is a key, never the
                            // member-facing sentence, which design-18 fixes
                            // and the frontend owns.
                            'last_send_status' => $destination->validation_last_send_status,
                            'last_send_failure' => $destination->validation_last_send_failure?->value,
                            'send_blocked' => $this->sendBlocked($destination),
                        ],
                    ],
                ])
                ->all(),
        ];
    }

    /**
     * The rate-limit fact for one destination's Validate control, or null when
     * a send is allowed. `until` is an absolute time rather than a duration:
     * the row renders "Try again at {time}", and a duration would go stale the
     * moment it was serialized.
     *
     * @return array{description: string, until: CarbonImmutable}|null
     */
    private function sendBlocked(Destination $destination): ?array
    {
        $blocked = app(SendDestinationValidationChallenge::class)->blockedBy($destination);

        if ($blocked === null) {
            return null;
        }

        return [
            'description' => $blocked['description'],
            'until' => now()->addSeconds($blocked['available_in']),
        ];
    }
}
