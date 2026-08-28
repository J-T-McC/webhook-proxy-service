<?php

namespace App\Http\Controllers;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Services\SecretStore;
use Illuminate\Http\RedirectResponse;

/**
 * Ends a proxy's inbound-verification rotation overlap early (AC29, Flow C
 * step 3; plan-10 §API). `SecretStore::endOverlap()` (T14) is idempotent —
 * this controller adds no guard of its own beyond authorization.
 */
class ProxyVerificationOverlapController extends Controller
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

        $this->secretStore->endOverlap($proxy, SecretPurpose::Verification);

        return back();
    }
}
