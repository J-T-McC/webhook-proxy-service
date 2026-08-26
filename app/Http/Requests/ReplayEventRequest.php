<?php

namespace App\Http\Requests;

use App\Models\Proxy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Server-authoritative validation for the replay endpoint's destination
 * selection (AC10; ADR-017 Decision 1). `destinations.*` is restricted to the
 * current route-bound proxy's **live** (non-trashed) destination ids — no
 * ad-hoc URLs, no trashed targets, no other proxy's destinations. Duplicate
 * ids are rejected (`distinct`).
 *
 * Authorization lives on the controller endpoint (ProxyPolicy::replay via
 * $this->authorize), not here — this request only validates, matching the
 * house `StoreProxyRequest`/`UpdateProxyRequest` split.
 */
class ReplayEventRequest extends FormRequest
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
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('destinations', 'id')
                    ->where('proxy_id', $this->proxy()?->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * The route-bound proxy, if any (absent only outside real HTTP request
     * context, e.g. a rules()-only unit test).
     */
    private function proxy(): ?Proxy
    {
        $proxy = $this->route('proxy');

        return $proxy instanceof Proxy ? $proxy : null;
    }
}
