<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\DeviceFingerprint;
use App\Security\User\UserAgentParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceFingerprintTest extends TestCase
{
    private const string CHROME_124 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const string CHROME_140 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const string FIREFOX_140 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 '
        . 'Firefox/140.0';

    private const string CHROME_ON_ANDROID = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/140.0.0.0 Mobile Safari/537.36';

    /**
     * The reason versions are left out. Chrome moves a major version every few weeks, and were it part of the key
     * every member would be told about a new device each time their browser updated itself.
     */
    public function testABrowserUpdateIsTheSameDevice(): void
    {
        self::assertSame(
            $this->fingerprint(
                self::CHROME_124,
                '192.0.2.10',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
            ),
        );
    }

    public function testADifferentBrowserIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
            ),
            $this->fingerprint(
                self::FIREFOX_140,
                '192.0.2.10',
            ),
        );
    }

    public function testADifferentSystemIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
            ),
            $this->fingerprint(
                self::CHROME_ON_ANDROID,
                '192.0.2.10',
            ),
        );
    }

    /**
     * A router handing out a different address on the same network is the same device in the same place.
     */
    public function testAnotherAddressOnTheSameIpv4NetworkIsTheSameDevice(): void
    {
        self::assertSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.240',
            ),
        );
    }

    public function testAnotherNetworkIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '198.51.100.10',
            ),
        );
    }

    /**
     * IPv4 written as IPv6 is still IPv4, and it arrives that way from a dual-stack listener or a proxy that forwards
     * what it was given. Read as an IPv6 address it would be cut to ten zero bytes, which is the same network for
     * everybody who reaches us like this.
     */
    public function testIpv4WrittenAsIpv6IsTheSameDevice(): void
    {
        self::assertSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '::ffff:192.0.2.10',
            ),
        );
    }

    public function testAnotherNetworkWrittenAsIpv6IsStillADifferentDevice(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '::ffff:192.0.2.10',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '::ffff:198.51.100.10',
            ),
        );
    }

    /**
     * IPv6 privacy addressing rewrites the host part about once a day, so anything narrower than the /64 would make
     * every member on IPv6 a new device every morning.
     */
    public function testAnIpv6HostRotationIsTheSameDevice(): void
    {
        self::assertSame(
            $this->fingerprint(
                self::CHROME_140,
                '2001:db8:1234:5678:1111:2222:3333:4444',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd',
            ),
        );
    }

    public function testAnotherIpv6SubnetIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '2001:db8:1234:5678::1',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '2001:db8:1234:9999::1',
            ),
        );
    }

    /**
     * The same address written the long way round is the same address, which is why it is packed before it is cut.
     */
    public function testAnIpv6AddressIsReadIndependentlyOfHowItIsSpelled(): void
    {
        self::assertSame(
            $this->fingerprint(
                self::CHROME_140,
                '2001:db8:1234:5678::1',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '2001:0db8:1234:5678:0000:0000:0000:0001',
            ),
        );
    }

    /**
     * Unlike the languages, an address that does not parse must not read as a network of its own: it is what an
     * attacker would vary to look like somebody else's network.
     */
    public function testAMalformedAddressIsNoNetworkAtAll(): void
    {
        self::assertSame(
            $this->fingerprint(
                self::CHROME_140,
                'not-an-address',
            ),
            $this->fingerprint(
                self::CHROME_140,
                null,
            ),
        );
    }

    /**
     * What tells apart two people who are otherwise the same browser on the same system on one network.
     */
    public function testAnotherSetOfLanguagesIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: 'nl-NL,nl;q=0.9,en;q=0.8',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: 'en-GB,en;q=0.9',
            ),
        );
    }

    /**
     * The same preferences spelled differently are the same preferences, which is why they are read through Symfony's
     * parsing rather than off the header.
     */
    public function testTheLanguagesAreReadIndependentlyOfHowTheyAreSpelled(): void
    {
        self::assertSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: 'nl-NL,nl;q=0.9,en;q=0.8',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: 'nl-NL, NL;q=0.90, EN;q=0.80',
            ),
        );
    }

    /**
     * A header left out, sent empty, or sent with nothing but spaces in it all say the same thing, and have to land in
     * the same place.
     */
    public function testABrowserThatAsksForNoLanguagesIsStillOneDevice(): void
    {
        $absent = $this->fingerprint(
            self::CHROME_140,
            '192.0.2.10',
            languages: null,
        );

        self::assertSame(
            $absent,
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: '',
            ),
        );
        self::assertSame(
            $absent,
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: '   ',
            ),
        );
    }

    /**
     * Asking for nothing is a state of its own rather than one that matches whoever does ask.
     */
    public function testAskingForNoLanguagesIsNotTheSameAsAskingForSome(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: null,
            ),
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: 'nl-NL,nl;q=0.9,en;q=0.8',
            ),
        );
    }

    /**
     * A header nobody can parse is carried as it stands. It cannot be turned to anyone's advantage, the languages
     * being one more thing that has to match, so a strange header only gets its own sign-in announced.
     */
    public function testAnUnparseableSetOfLanguagesIsCarriedWithoutUpset(): void
    {
        $garbled = $this->fingerprint(
            self::CHROME_140,
            '192.0.2.10',
            languages: '???',
        );

        self::assertSame(
            $garbled,
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: '???',
            ),
        );
        self::assertNotSame(
            $garbled,
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                languages: null,
            ),
        );
    }

    /**
     * The key is keyed on the application secret, so the same device on two installations does not share one.
     */
    public function testTheSecretChangesTheFingerprint(): void
    {
        self::assertNotSame(
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
            ),
            $this->fingerprint(
                self::CHROME_140,
                '192.0.2.10',
                'another secret',
            ),
        );
    }

    private function fingerprint(
        string $userAgent,
        ?string $address,
        string $secret = 'a secret',
        ?string $languages = 'nl-NL,nl;q=0.9,en;q=0.8',
    ): string {
        $request = new Request();
        $request->headers->set(
            'User-Agent',
            $userAgent,
        );

        if (null !== $address) {
            $request->server->set(
                'REMOTE_ADDR',
                $address,
            );
        }

        if (null !== $languages) {
            $request->headers->set(
                'Accept-Language',
                $languages,
            );
        }

        return new DeviceFingerprint(
            new UserAgentParser(),
            $secret,
        )->describe($request)['fingerprint'];
    }
}
