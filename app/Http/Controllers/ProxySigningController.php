<?php

namespace App\Http\Controllers;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Services\SecretStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Enable/regenerate and disable a proxy's outbound signing secret (AC56,
 * AC57, AC58; plan-10 § API, Technical ruling 5). Proxy-scoped only — there
 * is no destination-scoped route anywhere for signing, per the Owner's
 * proxy-level ruling (ADR-023).
 *
 * `store()` is deliberately the only JSON-returning endpoint this whole
 * feature has: it is the one place the plaintext secret exists in a
 * response body at all (its one-time reveal, ADR-021 Decision 6.3), and an
 * Inertia prop or the session store are both places a value could linger
 * beyond that single response. `Cache-Control: no-store, private` keeps it
 * out of any HTTP cache along the way.
 */
class ProxySigningController extends Controller
{
    public function __construct(
        private SecretStore $secretStore,
    ) {}

    /**
     * Enable or regenerate — the same action either way (AC56): always
     * generates a fresh secret, never returns a previously-generated one.
     * The leading `{current_team}` route parameter is accepted so implicit
     * binding of `{proxy}` aligns correctly under the team-prefixed group.
     */
    public function store(string $current_team, Proxy $proxy): JsonResponse
    {
        $this->authorize('update', $proxy);

        $secret = $this->secretStore->generate($proxy, SecretPurpose::Signing);
        $status = $this->secretStore->statusFor($proxy, SecretPurpose::Signing);

        return response()->json([
            'secret' => $secret,
            'generated_at' => $status?->changedAt,
        ], 200, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Disable signing entirely: deletes every `signing`-purpose row for the
     * proxy (ADR-021 Decision 5) — a subsequent re-enable always generates a
     * fresh secret, never the disabled one.
     */
    public function destroy(string $current_team, Proxy $proxy): RedirectResponse
    {
        $this->authorize('update', $proxy);

        $this->secretStore->disable($proxy, SecretPurpose::Signing);

        return back();
    }
}
