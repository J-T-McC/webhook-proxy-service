<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A validation challenge was not sent because the destination's URL resolves
 * somewhere the product must never reach (#18 AC20, ADR-027 decision 3), or
 * does not resolve at all.
 *
 * The guard fails closed, so every constructor here is a refusal rather than a
 * warning. Messages name the host and the offending address deliberately: the
 * member owns the destination and needs to know why it cannot be validated,
 * and neither value is a secret — the member typed the URL.
 */
class RefusedOutboundAddress extends RuntimeException
{
    public static function malformed(string $url): self
    {
        return new self("Destination URL has no host to resolve: {$url}");
    }

    public static function unresolvable(string $host): self
    {
        return new self("Destination host does not resolve: {$host}");
    }

    public static function refusedRange(string $host, string $address): self
    {
        return new self(
            "Destination host {$host} resolves to {$address}, which is a private, loopback, "
            .'link-local or otherwise reserved address and cannot be validated.'
        );
    }
}
