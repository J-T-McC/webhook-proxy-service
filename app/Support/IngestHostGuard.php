<?php

namespace App\Support;

/**
 * Shared host-comparison logic for the delivery-loop guard's direct-cycle
 * checks (`docs/briefs/delivery-loop-guard.md`) — a destination whose host
 * resolves to this service's own ingest host, or which is an IP-literal, is
 * refused. Used at save time by `App\Rules\NotSelfReferencingDestinationUrl`
 * (both checks) and again at send time by `DeliverToDestination::send()`'s
 * backstop (the ingest-host check only — see that method's docblock), so the
 * two never drift apart.
 *
 * The ingest host is always read from `config('ingest.url')` (ADR-006 guard)
 * — never the request `Host` header, which an attacker controls.
 */
final class IngestHostGuard
{
    /**
     * The host segment of a URL, or null when the URL is malformed or has no
     * host component.
     */
    public static function hostFrom(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Whether `$host` is an IP literal (IPv4 or IPv6) rather than a domain
     * name. `parse_url()`'s `PHP_URL_HOST` keeps the surrounding brackets on
     * a bracketed IPv6 literal (`[2001:db8::1]`) — `FILTER_VALIDATE_IP`
     * rejects those brackets, so they are stripped before validating.
     */
    public static function isIpLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Whether `$host` matches this service's own ingest host, matched
     * case-insensitively (hostnames are case-insensitive) and with a single
     * trailing root-label dot normalised off both sides first — review
     * finding: `ingest.example.com.` (a fully-qualified domain name; the
     * trailing dot resolves identically to `ingest.example.com`) otherwise
     * bypasses this check entirely, on both the save-time rule and the
     * send-time backstop. False when `config('ingest.url')` itself has no
     * resolvable host.
     */
    public static function pointsBackToIngest(string $host): bool
    {
        $ingestHost = self::hostFrom((string) config('ingest.url'));

        if ($ingestHost === null) {
            return false;
        }

        return strcasecmp(self::withoutTrailingDot($host), self::withoutTrailingDot($ingestHost)) === 0;
    }

    /**
     * Strips a single trailing root-label dot from an FQDN, if present.
     */
    private static function withoutTrailingDot(string $host): string
    {
        return str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
    }
}
