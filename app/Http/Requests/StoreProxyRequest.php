<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\ProxyMode;
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
            // Optional user-defined upstream response (AC4). A non-2xx status is
            // rejected; NULL/absent is allowed (unconfigured → resolver returns 202).
            'response_status' => ['nullable', 'integer', 'between:200,299'],
            'response_body' => ['nullable', 'string', 'max:'.config('ingest.response_body_max_bytes')],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.url' => ['required', 'string', 'url:https'],
            'destinations.*.http_method' => ['required', Rule::enum(HttpMethod::class)],
        ];
    }
}
