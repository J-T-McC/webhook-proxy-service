<?php

namespace App\Services;

use App\Enums\SecretPurpose;
use App\Enums\VerificationResult;
use App\Enums\VerificationScheme;
use App\Models\Proxy;
use App\Verification\SharedSecretScheme;
use App\Verification\StandardWebhooksScheme;
use App\Verification\VerificationSchemeHandler;
use Illuminate\Http\Request;
use LogicException;

/**
 * The resolution-time gate (ADR-022 Decision 1, ADR-018 Decision 1's rule
 * applied here): establishes `$proxy->verification_scheme !== null` before
 * asking `SecretStore` for anything, so a proxy with verification off never
 * queries `proxy_secrets` (AC24). Dispatches to the matching
 * `VerificationSchemeHandler` (T17) with `SecretStore::liveFor()`'s live
 * set; every member is tried, and which one matched leaves no trace
 * (ADR-022 Decision 3) — this class never returns or logs it.
 */
class InboundVerifier
{
    public function __construct(
        private readonly SecretStore $secretStore,
        private readonly SharedSecretScheme $sharedSecretScheme,
        private readonly StandardWebhooksScheme $standardWebhooksScheme,
    ) {}

    public function verify(Proxy $proxy, Request $request, string $rawBody): VerificationResult
    {
        if ($proxy->verification_scheme === null) {
            return VerificationResult::NotRequired;
        }

        $liveSecrets = $this->secretStore->liveFor($proxy, SecretPurpose::Verification);

        $verified = $this->handlerFor($proxy->verification_scheme)
            ->verify($proxy, $request, $rawBody, $liveSecrets);

        return $verified ? VerificationResult::Verified : VerificationResult::Failed;
    }

    /**
     * ADR-022 Decision 5's reason code for the rejection — only ever called
     * by `IngestController` after {@see self::verify()} has already
     * returned `VerificationResult::Failed` for these same arguments.
     * Recomputes independently (a second, small `SecretStore::liveFor()`
     * read) rather than caching anything from that call, so this class
     * carries no state between the two.
     *
     * @throws LogicException if called other than immediately after a
     *                        `Failed` result for the same arguments.
     */
    public function reasonFor(Proxy $proxy, Request $request, string $rawBody): string
    {
        $scheme = $proxy->verification_scheme;

        if ($scheme === null) {
            throw new LogicException('reasonFor() called for a proxy with no verification scheme configured.');
        }

        $liveSecrets = $this->secretStore->liveFor($proxy, SecretPurpose::Verification);

        $reason = match ($scheme) {
            VerificationScheme::SharedSecret => $this->sharedSecretScheme->reasonFor($proxy, $request, $liveSecrets),
            VerificationScheme::StandardWebhooks => $this->standardWebhooksScheme->reasonFor($request, $rawBody, $liveSecrets),
        };

        return $reason ?? throw new LogicException('reasonFor() called without a corresponding verification failure.');
    }

    private function handlerFor(VerificationScheme $scheme): VerificationSchemeHandler
    {
        return match ($scheme) {
            VerificationScheme::SharedSecret => $this->sharedSecretScheme,
            VerificationScheme::StandardWebhooks => $this->standardWebhooksScheme,
        };
    }
}
