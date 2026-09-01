<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\IpNetworkResolver;
use PHPUnit\Framework\TestCase;

/**
 * The layer below the databases: what an address reduces to when neither the ASN nor the country file is on disk,
 * which is every development machine and the test suite. The addresses used here are reserved ones that no database
 * would answer for either, so these answers hold with the files present too.
 */
final class IpNetworkResolverTest extends TestCase
{
    public function testAnIpv4AddressReducesToItsFirstThreeOctets(): void
    {
        self::assertSame(
            'pfx:c00002',
            $this->identify('192.0.2.10'),
        );
    }

    public function testAnotherAddressOnTheSameIpv4NetworkIsTheSameNetwork(): void
    {
        self::assertSame(
            $this->identify('192.0.2.10'),
            $this->identify('192.0.2.240'),
        );
    }

    public function testAnotherIpv4NetworkIsAnotherNetwork(): void
    {
        self::assertNotSame(
            $this->identify('192.0.2.10'),
            $this->identify('198.51.100.10'),
        );
    }

    /**
     * IPv4 written as IPv6 is still IPv4, and it arrives that way from a dual-stack listener or a proxy that forwards
     * what it was given. Read as an IPv6 address it would be cut to ten zero bytes, which is the same network for
     * everybody who reaches us like this.
     */
    public function testIpv4WrittenAsIpv6IsTheSameNetwork(): void
    {
        self::assertSame(
            $this->identify('192.0.2.10'),
            $this->identify('::ffff:192.0.2.10'),
        );
        self::assertNotSame(
            $this->identify('::ffff:192.0.2.10'),
            $this->identify('::ffff:198.51.100.10'),
        );
    }

    /**
     * IPv6 privacy addressing rewrites the host part about once a day, so anything narrower than the /64 would make
     * every member on IPv6 a new network every morning.
     */
    public function testAnIpv6HostRotationIsTheSameNetwork(): void
    {
        self::assertSame(
            $this->identify('2001:db8:1234:5678:1111:2222:3333:4444'),
            $this->identify('2001:db8:1234:5678:aaaa:bbbb:cccc:dddd'),
        );
    }

    public function testAnotherIpv6SubnetIsAnotherNetwork(): void
    {
        self::assertNotSame(
            $this->identify('2001:db8:1234:5678::1'),
            $this->identify('2001:db8:1234:9999::1'),
        );
    }

    /**
     * The same address written the long way round is the same address, which is why it is packed before it is cut.
     */
    public function testAnIpv6AddressIsReadIndependentlyOfHowItIsSpelled(): void
    {
        self::assertSame(
            $this->identify('2001:db8:1234:5678::1'),
            $this->identify('2001:0db8:1234:5678:0000:0000:0000:0001'),
        );
    }

    /**
     * An address that does not parse must not read as a network of its own: it is what an attacker would vary to look
     * like somebody else's network.
     */
    public function testAMalformedAddressIsNoNetworkAtAll(): void
    {
        self::assertSame(
            '',
            $this->identify('not-an-address'),
        );
        self::assertSame(
            '',
            $this->identify(null),
        );
        self::assertSame(
            '',
            $this->identify(''),
        );
    }

    private function identify(?string $address): string
    {
        return new IpNetworkResolver('/nonexistent')->identify($address);
    }
}
