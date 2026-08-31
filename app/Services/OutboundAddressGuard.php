<?php

namespace App\Services;

use App\Exceptions\RefusedOutboundAddress;
use App\Support\IngestHostGuard;
use Closure;

/**
 * Refuses an outbound request to an address the product must never reach, and
 * pins the connection to the address it checked (#18 AC20, ADR-028's sibling
 * ADR-027 decision 3; Q-18-01 answer 3).
 *
 * **Why pinning, and not just validation.** A hostname checked now and
 * connected to a moment later can resolve to two different addresses — an
 * attacker who controls the DNS record answers public to the check and private
 * to the connection. Validating the URL, or even resolving it and validating
 * the result, does not close that: only connecting to the *same address that
 * was checked* does. {@see resolve()} returns that address so the caller can
 * hand it to cURL's `CURLOPT_RESOLVE`.
 *
 * **Scope.** Validation sends only (AC40). Ordinary delivery is deliberately
 * untouched, so destinations grandfathered by #18's migration keep working even
 * at private addresses until their URL changes. The stated consequence is that
 * a NEW destination at a private address can never be validated.
 *
 * **Fails closed.** An unresolvable host, a malformed URL, a host with no
 * addresses, or any single returned address inside a refused range refuses the
 * whole send. A host with several addresses is refused if *any* of them is
 * refused, not merely if the one we would have picked is — otherwise the choice
 * of address becomes the security boundary.
 *
 * Redirects are refused rather than followed (AC19), which is what makes this
 * sufficient: pinning cannot extend to a second hop, and a validation challenge
 * has no legitimate reason to be redirected. That refusal lives at the call
 * site, since it is an HTTP-client option rather than an address question.
 */
class OutboundAddressGuard
{
    /**
     * Ranges PHP's own `FILTER_FLAG_NO_PRIV_RANGE` and `FILTER_FLAG_NO_RES_RANGE`
     * do not cover, in CIDR form. The flags handle loopback, link-local, the
     * RFC 1918 private ranges, unique-local IPv6 and the reserved blocks; these
     * are the gaps.
     *
     * `100.64.0.0/10` matters specifically: it is carrier-grade NAT, and
     * Alibaba Cloud's instance metadata sits at `100.100.100.200` inside it.
     * AWS/GCP/Azure metadata at `169.254.169.254` is already covered as
     * link-local.
     *
     * @var list<string>
     */
    private const EXTRA_REFUSED_RANGES = [
        '100.64.0.0/10',   // carrier-grade NAT; Alibaba metadata
        '192.0.0.0/24',    // IETF protocol assignments
        '198.18.0.0/15',   // benchmarking
        '224.0.0.0/4',     // multicast
    ];

    /**
     * @param  Closure(string): list<string>|null  $resolver  Overridable so tests can
     *                                                        drive resolution — including the rebinding case, where the
     *                                                        same host answers differently on two calls.
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * The address this URL's host resolves to, once every address it resolves
     * to has been found acceptable.
     *
     * @throws RefusedOutboundAddress
     */
    public function resolve(string $url): string
    {
        $host = IngestHostGuard::hostFrom($url);

        if ($host === null) {
            throw RefusedOutboundAddress::malformed($url);
        }

        $addresses = IngestHostGuard::isIpLiteral($host)
            ? [trim($host, '[]')]
            : $this->resolveHost($host);

        if ($addresses === []) {
            throw RefusedOutboundAddress::unresolvable($host);
        }

        foreach ($addresses as $address) {
            if (! $this->isPermitted($address)) {
                throw RefusedOutboundAddress::refusedRange($host, $address);
            }
        }

        return $addresses[0];
    }

    /**
     * Every address a host resolves to, IPv4 and IPv6 alike. Both families are
     * gathered because refusing on the basis of one while the transport might
     * pick the other would leave the gap open.
     *
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $v4 = gethostbynamel($host);
        $v6 = @dns_get_record($host, DNS_AAAA);

        $addresses = is_array($v4) ? $v4 : [];

        foreach (is_array($v6) ? $v6 : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether a single address may be connected to.
     *
     * An IPv4-mapped IPv6 address (`::ffff:10.0.0.1`) is unwrapped and judged
     * as the IPv4 address it carries — otherwise the mapping is a trivial way
     * to smuggle a private address past an IPv6 check.
     */
    private function isPermitted(string $address): bool
    {
        $address = $this->unwrapIpv4Mapped($address);

        $valid = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($valid === false) {
            return false;
        }

        foreach (self::EXTRA_REFUSED_RANGES as $range) {
            if ($this->inRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `::ffff:192.0.2.1` and `::ffff:c000:201` both carry an IPv4 address;
     * return it so the IPv4 rules apply. Anything else is returned unchanged.
     */
    private function unwrapIpv4Mapped(string $address): string
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        if (substr($packed, 0, 12) !== "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return $address;
        }

        $unwrapped = inet_ntop(substr($packed, 12));

        return $unwrapped === false ? $address : $unwrapped;
    }

    /**
     * Whether an IPv4 address falls inside a CIDR range. IPv6 addresses are
     * never in these ranges, which are all IPv4.
     */
    private function inRange(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        $addressLong = ip2long($address);
        $subnetLong = ip2long($subnet);

        if ($addressLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($addressLong & $mask) === ($subnetLong & $mask);
    }
}
