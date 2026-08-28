<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Server-authoritative validation for creating a proxy (AC2/AC3/AC12).
 *
 * HTTPS-only destination URLs (Owner security decision 2026-07-30, PRD-01): any
 * non-`https://` scheme — `http://`, or a scheme-less/malformed URL — is rejected.
 *
 * Authorization lives on the controller endpoint (ProxyPolicy::create via
 * $this->authorize), not here — this request only validates.
 */
class StoreProxyRequest extends FormRequest
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
            // Per-proxy processing mode (AC4, ADR-011). Always submitted by the form
            // (defaults to async on create); an invalid/absent value is rejected.
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
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.url' => ['required', 'string', 'url:https'],
            'destinations.*.http_method' => ['required', Rule::enum(HttpMethod::class)],
        ];
    }
}
