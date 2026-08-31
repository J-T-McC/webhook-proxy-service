<?php

namespace Tests\Unit\Services;

use App\Exceptions\RefusedOutboundAddress;
use App\Services\OutboundAddressGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The address guard for validation sends (#18 AC20, AC40; ADR-027 decision 3).
 * The guard fails closed, so every case here that is not explicitly permitted
 * must refuse.
 */
class OutboundAddressGuardTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function refusedLiterals(): array
    {
        return [
            ['http://127.0.0.1/hook'],              // loopback
            ['http://127.1.2.3/hook'],              // the rest of 127/8
            ['http://10.0.0.1/hook'],               // RFC 1918
            ['http://172.16.5.5/hook'],             // RFC 1918
            ['http://192.168.1.1/hook'],            // RFC 1918
            ['http://169.254.169.254/latest/meta'],  // AWS/GCP/Azure metadata
            ['http://100.100.100.200/latest'],      // Alibaba metadata, inside CGNAT
            ['http://100.64.0.1/hook'],             // carrier-grade NAT
            ['http://192.0.0.1/hook'],              // IETF protocol assignments
            ['http://198.18.0.1/hook'],             // benchmarking
            ['http://224.0.0.1/hook'],              // multicast
            ['http://0.0.0.0/hook'],                // unspecified
            ['http://[::1]/hook'],                  // IPv6 loopback
            ['http://[fe80::1]/hook'],              // IPv6 link-local
            ['http://[fc00::1]/hook'],              // IPv6 unique-local
        ];
    }

    #[DataProvider('refusedLiterals')]
    public function test_a_refused_address_literal_is_rejected(string $url): void
    {
        $this->expectException(RefusedOutboundAddress::class);

        (new OutboundAddressGuard)->resolve($url);
    }

    public function test_an_ipv4_mapped_ipv6_address_cannot_smuggle_a_private_address(): void
    {
        // ::ffff:10.0.0.1 carries an RFC 1918 address. Judged as IPv6 alone it
        // would pass; the guard unwraps it and applies the IPv4 rules.
        $this->expectException(RefusedOutboundAddress::class);

        (new OutboundAddressGuard)->resolve('http://[::ffff:10.0.0.1]/hook');
    }

    public function test_a_public_address_literal_is_permitted(): void
    {
        $this->assertSame('93.184.216.34', (new OutboundAddressGuard)->resolve('https://93.184.216.34/hook'));
    }

    public function test_a_hostname_resolving_to_a_public_address_is_permitted(): void
    {
        $guard = new OutboundAddressGuard(fn (string $host) => ['93.184.216.34']);

        $this->assertSame('93.184.216.34', $guard->resolve('https://example.test/hook'));
    }

    public function test_a_hostname_resolving_to_a_private_address_is_refused(): void
    {
        $guard = new OutboundAddressGuard(fn (string $host) => ['10.0.0.5']);

        $this->expectException(RefusedOutboundAddress::class);

        $guard->resolve('https://internal.test/hook');
    }

    public function test_a_host_is_refused_when_any_of_its_addresses_is_refused(): void
    {
        // The choice of address must not be the security boundary: a host that
        // answers with one public and one private address is refused whole.
        $guard = new OutboundAddressGuard(fn (string $host) => ['93.184.216.34', '169.254.169.254']);

        $this->expectException(RefusedOutboundAddress::class);

        $guard->resolve('https://mixed.test/hook');
    }

    public function test_an_unresolvable_host_is_refused_rather_than_allowed(): void
    {
        $guard = new OutboundAddressGuard(fn (string $host) => []);

        $this->expectException(RefusedOutboundAddress::class);

        $guard->resolve('https://nowhere.test/hook');
    }

    public function test_a_url_without_a_host_is_refused(): void
    {
        $this->expectException(RefusedOutboundAddress::class);

        (new OutboundAddressGuard)->resolve('not-a-url');
    }

    public function test_the_checked_address_is_returned_so_the_caller_can_pin_to_it(): void
    {
        // The whole point of the guard: a host that answers differently on a
        // second resolution cannot be reached, because the caller connects to
        // the address returned here rather than resolving again.
        $answers = [['93.184.216.34'], ['127.0.0.1']];
        $guard = new OutboundAddressGuard(function (string $host) use (&$answers) {
            return array_shift($answers) ?? [];
        });

        $this->assertSame(
            '93.184.216.34',
            $guard->resolve('https://rebinding.test/hook'),
            'The guard must hand back the address it validated, not the host, so the '
            .'connection cannot resolve again and land somewhere else.',
        );
    }
}
