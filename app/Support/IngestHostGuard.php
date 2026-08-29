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
     * name.
     */
    public static function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Whether `$host` matches this service's own ingest host, matched
     * case-insensitively (hostnames are case-insensitive). False when
     * `config('ingest.url')` itself has no resolvable host.
     */
    public static function pointsBackToIngest(string $host): bool
    {
        $ingestHost = self::hostFrom((string) config('ingest.url'));

        return $ingestHost !== null && strcasecmp($host, $ingestHost) === 0;
    }
}
