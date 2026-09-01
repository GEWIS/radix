<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\IpNetworkResolver;
use PHPUnit\Framework\TestCase;

/**
 * Without the ASN database on disk, which is every development machine and the test suite. The addresses are
 * reserved ones the database would not answer for either, so these answers hold with the file present too.
 */
final class IpNetworkResolverTest extends TestCase
{
    public function testAnIpv4AddressReducesToItsFirstThreeOctets(): void
    {
        self::assertSame(
            ['pfx:c00002'],
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
     * Read as IPv6, mapped IPv4 would be cut to ten zero bytes: the same network for everybody arriving through a
     * dual-stack listener.
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
     * IPv6 privacy addressing rewrites the host part about once a day; narrower than the /64 would be a new network
     * every morning.
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

    public function testAnIpv6AddressIsReadIndependentlyOfHowItIsSpelled(): void
    {
        self::assertSame(
            $this->identify('2001:db8:1234:5678::1'),
            $this->identify('2001:0db8:1234:5678:0000:0000:0000:0001'),
        );
    }

    /**
     * An attacker varies the address, so a malformed one must not read as a network of its own.
     */
    public function testAMalformedAddressIsNoNetworkAtAll(): void
    {
        self::assertSame(
            [],
            $this->identify('not-an-address'),
        );
        self::assertSame(
            [],
            $this->identify(null),
        );
        self::assertSame(
            [],
            $this->identify(''),
        );
    }

    public function testWithoutTheDatabaseNoNetworkHasAName(): void
    {
        self::assertNull(new IpNetworkResolver('/nonexistent')->networkName('192.0.2.10'));
        self::assertNull(new IpNetworkResolver('/nonexistent')->networkName('not-an-address'));
    }

    /**
     * @return list<string>
     */
    private function identify(?string $address): array
    {
        return new IpNetworkResolver('/nonexistent')->identify($address);
    }
}
