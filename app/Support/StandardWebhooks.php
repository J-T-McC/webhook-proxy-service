<?php

namespace App\Support;

/**
 * The Standard Webhooks specification (https://www.standardwebhooks.com/),
 * implemented in-house against the spec text rather than a Composer package
 * (plan-10 Technical ruling 6, ADR-022 § Alternatives — read 2026-08-27, since
 * superseded in full by ADR-026 Decision B). Pure class, no DB: `sign()`/
 * `verify()` are deterministic given their arguments, and the one place this
 * class reads the wall clock is the tolerance check inside `verify()`, which
 * the spec itself defines relative to "now".
 *
 * `sign()` is the outbound signing implementation (T7) — it is what
 * `OutboundHeaders` calls to emit the `WebhookProxy-Signature` header on
 * every attempt to a signing-enabled proxy's destinations. Inbound
 * verification was removed from the product in full (ADR-026 Decision B):
 * `StandardWebhooksScheme`, the class that once called `verify()` on the
 * receiving side, no longer exists. `verify()` itself is not dead code — it
 * survives as the receiver-side oracle the outbound signing tests use to
 * prove what this service emits is verifiable by a conforming recipient
 * (`tests/Unit/Support/StandardWebhooksTest.php`,
 * `tests/Unit/Support/OutboundHeadersSigningTest.php`). ADR-026 § *What
 * stays, and why* names this class as the one a developer following a
 * `verification` thread is most likely to over-delete, precisely because
 * `verify()` has no inbound caller any more.
 */
class StandardWebhooks
{
    /**
     * The specification's reference tolerance, applied as an absolute
     * difference either side of `now()`. A class constant, not config —
     * ADR-026 § *The Standard Webhooks construction, restated* makes the
     * tolerance the specification's, not a per-proxy or environment setting
     * (AC53, which stated this originally, was withdrawn by PRD-10 Amendment
     * C; the property itself is unaffected and this is now its normative
     * record).
     */
    final public const TOLERANCE_SECONDS = 300;

    /**
     * `hash_hmac('sha256', "<id>.<timestamp>.<body>", $secret, true)`,
     * base64-encoded (not hex).
     */
    public static function sign(string $id, int $timestamp, string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", self::decodeSecret($secret), true));
    }

    /**
     * Parses a space-delimited `webhook-signature` header value (`v1,<sig>
     * v1,<sig> ...`), skipping any entry whose version prefix is not `v1`
     * rather than failing outright, and succeeds if **any** entry verifies
     * against **any** secret in the live set (`hash_equals` throughout, for
     * constant-time comparison). Rejects a timestamp more than
     * {@see self::TOLERANCE_SECONDS} seconds from now, either direction,
     * before attempting any signature comparison.
     *
     * @param  list<string>  $secrets
     */
    public static function verify(string $id, int $timestamp, string $body, string $signatureHeaderValue, array $secrets): bool
    {
        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        foreach (self::parseEntries($signatureHeaderValue) as $signature) {
            foreach ($secrets as $secret) {
                if (hash_equals(self::sign($id, $timestamp, $body, $secret), $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string> the base64 signature of every `v1,<sig>` entry, in order
     */
    private static function parseEntries(string $signatureHeaderValue): array
    {
        $signatures = [];

        foreach (preg_split('/\s+/', trim($signatureHeaderValue)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }

            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, null);

            if ($version !== 'v1' || $signature === null || $signature === '') {
                continue;
            }

            $signatures[] = $signature;
        }

        return $signatures;
    }

    /**
     * The specification permits a `whsec_`-prefixed or a bare base64 secret;
     * both decode to the same key material. Strict-mode `base64_decode` so a
     * malformed secret (e.g. hex, which shares base64's alphanumeric
     * alphabet but is not valid base64 padding/length) never silently
     * decodes into something else's bytes without at least strict
     * validation — and if it still decodes to a different value, the
     * resulting signature simply will not match (never a different,
     * silently-accepted encoding).
     */
    private static function decodeSecret(string $secret): string
    {
        $stripped = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;

        $decoded = base64_decode($stripped, true);

        return $decoded === false ? '' : $decoded;
    }
}
