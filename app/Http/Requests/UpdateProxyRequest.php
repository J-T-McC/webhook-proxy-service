<?php

namespace App\Http\Requests;

use App\Models\Proxy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Server-authoritative validation for updating a proxy (AC16a/AC16b).
 *
 * Same rules as create, incl. the HTTPS-only destination URL invariant (Owner
 * security decision 2026-07-30, PRD-01). `destinations.*.id` (optional) keys the
 * reconciliation of existing live rows in the controller.
 */
class UpdateProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proxy = $this->route('proxy');

        return $proxy instanceof Proxy
            && ($this->user()?->can('update', $proxy) ?? false);
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
            'destinations.*.id' => ['sometimes', 'nullable', 'integer'],
            'destinations.*.url' => ['required', 'string', 'url:https'],
            'destinations.*.http_method' => ['required', 'in:POST,PUT'],
        ];
    }
}
