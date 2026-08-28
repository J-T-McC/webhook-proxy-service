<?php

namespace App\Verification;

use App\Models\Proxy;
use App\Support\StandardWebhooks;
use Illuminate\Http\Request;

/**
 * `standard-webhooks`: delegates to {@see StandardWebhooks::verify()} over
 * the three specified headers (`webhook-id`, `webhook-timestamp`,
 * `webhook-signature`) and the live secret set (AC52). A missing or
 * malformed header fails; the tolerance check lives inside
 * {@see StandardWebhooks::verify()} (AC53).
 */
class StandardWebhooksScheme implements VerificationSchemeHandler
{
    /**
     * @param  list<string>  $liveSecrets
     */
    public function verify(Proxy $proxy, Request $request, string $rawBody, array $liveSecrets): bool
    {
        return $this->reasonFor($request, $rawBody, $liveSecrets) === null;
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
    public function reasonFor(Request $request, string $rawBody, array $liveSecrets): ?string
    {
        $id = $request->header('webhook-id');
        $timestampHeader = $request->header('webhook-timestamp');
        $signature = $request->header('webhook-signature');

        if ($id === null || $id === '' || $timestampHeader === null || $timestampHeader === ''
            || $signature === null || $signature === '') {
            return 'missing_header';
        }

        if (! ctype_digit($timestampHeader)) {
            return 'malformed_header';
        }

        $timestamp = (int) $timestampHeader;

        if (abs(time() - $timestamp) > StandardWebhooks::TOLERANCE_SECONDS) {
            return 'timestamp_out_of_tolerance';
        }

        return StandardWebhooks::verify($id, $timestamp, $rawBody, $signature, $liveSecrets)
            ? null
            : 'signature_mismatch';
    }
}
