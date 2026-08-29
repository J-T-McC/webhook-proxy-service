<?php

namespace App\Http\Controllers;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Services\SecretStore;
use Illuminate\Http\RedirectResponse;

/**
 * Ends a proxy's outbound-signing rotation overlap early (AC58, Flow I;
 * plan-10 §API). The inbound-verification counterpart this controller once
 * paralleled, `ProxyVerificationOverlapController`, was removed by ADR-026
 * Decision B (T53) — signing is the only overlap-bearing secret purpose
 * left. `SecretStore::endOverlap()` (T14) is idempotent — this controller
 * adds no guard of its own beyond authorization.
 */
class ProxySigningOverlapController extends Controller
{
    public function __construct(
        private SecretStore $secretStore,
    ) {}

    /**
     * The leading `{current_team}` route parameter is accepted so implicit
     * binding of `{proxy}` aligns correctly under the team-prefixed group.
     */
    public function destroy(string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('update', $proxy);

        $this->secretStore->endOverlap($proxy, SecretPurpose::Signing);

        return back();
    }
}
