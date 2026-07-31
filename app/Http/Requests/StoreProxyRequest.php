<?php

namespace App\Http\Requests;

use App\Models\Proxy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Server-authoritative validation for creating a proxy (AC2/AC3/AC12).
 *
 * HTTPS-only destination URLs (Owner security decision 2026-07-30, PRD-01): any
 * non-`https://` scheme — `http://`, or a scheme-less/malformed URL — is rejected.
 */
class StoreProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Proxy::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mode' => ['required', 'in:simple,enhanced'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.url' => ['required', 'string', 'url:https'],
            'destinations.*.http_method' => ['required', 'in:POST,PUT'],
        ];
    }
}
