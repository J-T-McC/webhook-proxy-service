<?php

namespace App\Verification;

use App\Models\Proxy;
use Illuminate\Http\Request;

/**
 * `shared-secret`: the member-named header's value must exactly
 * (constant-time) match a member of the live secret set. Nothing is
 * computed over the body (AC51).
 */
class SharedSecretScheme implements VerificationSchemeHandler
{
    /**
     * @param  list<string>  $liveSecrets
     */
    public function verify(Proxy $proxy, Request $request, string $rawBody, array $liveSecrets): bool
    {
        return $this->reasonFor($proxy, $request, $liveSecrets) === null;
    }

    /**
     * ADR-022 Decision 5's reason code for why this scheme would reject the
     * request, or `null` if it would verify. Pure — recomputed from the
     * arguments rather than cached from a prior {@see self::verify()} call
     * — so `InboundVerifier` may call this independently, only after
     * `verify()` has already returned `false` for the same arguments.
     *
     * @param  list<string>  $liveSecrets
     */
    public function reasonFor(Proxy $proxy, Request $request, array $liveSecrets): ?string
    {
        $headerName = $proxy->verification_header_name;
        $value = $headerName === null ? null : $request->header($headerName);

        if ($value === null) {
            return 'missing_header';
        }

        foreach ($liveSecrets as $secret) {
            if (hash_equals($secret, $value)) {
                return null;
            }
        }

        return 'secret_mismatch';
    }
}
