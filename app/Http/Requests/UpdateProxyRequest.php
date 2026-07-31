<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\ProxyMode;
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
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.id' => ['sometimes', 'nullable', 'integer'],
            'destinations.*.url' => ['required', 'string', 'url:https'],
            'destinations.*.http_method' => ['required', Rule::enum(HttpMethod::class)],
        ];
    }
}
