<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Enums\SecretPurpose;
use App\Enums\VerificationScheme;
use App\Models\Proxy;
use App\Services\SecretStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Server-authoritative validation for updating a proxy (AC16a/AC16b).
 *
 * Same rules as create, incl. the HTTPS-only destination URL invariant (Owner
 * security decision 2026-07-30, PRD-01). `destinations.*.id` (optional) keys the
 * reconciliation of existing live rows in the controller.
 *
 * Authorization lives on the controller endpoint (ProxyPolicy::update via
 * $this->authorize), not here — this request only validates.
 */
class UpdateProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::enum(ProxyMode::class)],
            // Per-proxy processing mode (AC4, ADR-011). Always submitted by the form;
            // an invalid/absent value is rejected.
            'processing_mode' => ['required', Rule::enum(ProcessingMode::class)],
            // Optional user-defined upstream response (AC4). The status is restricted
            // to the fixed set {200, 202, 204}; anything else is rejected. NULL/absent
            // is allowed (unconfigured → resolver returns the 202 default).
            'response_status' => ['nullable', 'integer', Rule::in([200, 202, 204])],
            // 204 = No Content couples to an empty body (AC12): a 204 configuration
            // with a non-empty body is rejected. `prohibited_if` fails when the field
            // is present-and-non-empty while response_status is 204.
            'response_body' => ['nullable', 'string', 'prohibited_if:response_status,204', 'max:'.config('ingest.response_body_max_bytes')],
            // Per-proxy retry policy (AC14(a), AC14(b); ADR-018 Decision 3) —
            // enhanced-mode-only: a value present on a `mode = simple`
            // submission is rejected, mirroring the `response_body`/204
            // `prohibited_if` idiom. Kept deliberately even though the
            // controller now preserves rather than clears a dormant value on
            // a Simple-mode save (review-06 Minor 8(a) proposed relaxing this
            // rule; plan-07 §Technical ruling 2 declines): it is the second,
            // independent guard that a Simple-mode save can never change a
            // dormant policy it cannot see. NULL/absent always passes
            // regardless of mode (system default).
            'retry_attempt_limit' => ['nullable', 'integer', 'min:1', 'max:10', 'prohibited_if:mode,simple'],
            'retry_backoff_strategy' => ['nullable', Rule::enum(RetryBackoffStrategy::class), 'prohibited_if:mode,simple'],
            // Per-proxy AC13 additions to the fixed AC12 default list (T4/T5's
            // SensitiveFields/SensitiveFieldMatcher). 'regex:/\S/' rejects a
            // blank/whitespace-only entry; trimming and de-duplication by
            // normalised form happen server-side in the controller, not here
            // (ProxyController::sensitiveFieldAdditions()).
            'sensitive_fields' => ['nullable', 'array', 'max:100'],
            'sensitive_fields.*' => ['string', 'max:128', 'regex:/\S/'],
            // Inbound verification (AC23, AC24, AC26; plan-10 §Validation). A
            // `null`/absent scheme means "not required" (AC24) — the frontend
            // normalises its "none" Select sentinel to absence before submit.
            'verification_scheme' => ['nullable', Rule::enum(VerificationScheme::class)],
            // Only `shared-secret` names a header the sender must use;
            // `standard-webhooks` fixes its own three header names, so a
            // submitted header name under that scheme is rejected outright
            // (`prohibited_unless`) rather than silently ignored.
            'verification_header_name' => [
                'required_if:verification_scheme,shared-secret',
                'prohibited_unless:verification_scheme,shared-secret',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/',
            ],
            // Write-only (AC26): absent means "leave unchanged" — required
            // only when a scheme is selected AND this proxy has no live
            // verification secret yet (a first-time scheme selection).
            'verification_secret' => [
                'nullable',
                'string',
                'min:8',
                'max:1024',
                Rule::requiredIf(fn (): bool => VerificationScheme::tryFrom((string) $this->input('verification_scheme')) !== null
                    && ! $this->proxyHasLiveVerificationSecret()),
            ],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.id' => ['sometimes', 'nullable', 'integer'],
            'destinations.*.url' => ['required', 'string', 'url:https'],
            'destinations.*.http_method' => ['required', Rule::enum(HttpMethod::class)],
            // Per-destination credential (AC30, AC33; plan-10 §Validation, T29).
            // The header name defaults to `Authorization` on the form, not here
            // (the schema allows it to be absent whenever no secret is present).
            // Reuses the same HTTP field-name pattern as `verification_header_name`.
            'destinations.*.credential_header_name' => [
                'required_with:destinations.*.credential_secret',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/',
            ],
            // Write-only (AC33): absent/empty means "leave unchanged" — a
            // present, non-empty value replaces the stored credential
            // immediately, reconciled by the row's existing `id`-based
            // matching in the controller. No `min` length constraint (unlike
            // `verification_secret`'s `min:8`), so an empty string can reach
            // the controller and must be treated the same as absent there.
            'destinations.*.credential_secret' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * Whether the proxy this request is updating already has a live
     * `verification` secret (T14/`SecretStore::liveFor()` — the single
     * reader of `proxy_secrets`, plan-10 Technical ruling 14). Governs
     * `verification_secret`'s write-only "required only on first-time
     * selection" rule above; an absent field on an already-configured proxy
     * means "leave unchanged" (AC26).
     */
    private function proxyHasLiveVerificationSecret(): bool
    {
        $proxy = $this->route('proxy');

        if (! $proxy instanceof Proxy) {
            return false;
        }

        return app(SecretStore::class)->liveFor($proxy, SecretPurpose::Verification) !== [];
    }
}
