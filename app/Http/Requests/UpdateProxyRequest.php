<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
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
            // Per-proxy retry policy (AC2, AC20) — enhanced-mode-only (Q-06-01
            // ruling): a value present on a `mode = simple` submission is
            // rejected, mirroring the `response_body`/204 `prohibited_if` idiom.
            // NULL/absent always passes regardless of mode (system default), and
            // is how an enhanced→simple mode switch clears any previously
            // configured values in the controller (T30).
            'retry_attempt_limit' => ['nullable', 'integer', 'min:1', 'max:10', 'prohibited_if:mode,simple'],
            'retry_backoff_strategy' => ['nullable', Rule::enum(RetryBackoffStrategy::class), 'prohibited_if:mode,simple'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.id' => ['sometimes', 'nullable', 'integer'],
            'destinations.*.url' => ['required', 'string', 'url:https'],
            'destinations.*.http_method' => ['required', Rule::enum(HttpMethod::class)],
        ];
    }
}
