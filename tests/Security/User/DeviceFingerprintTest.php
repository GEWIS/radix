<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\DeviceFingerprint;
use App\Security\User\IpNetworkResolver;
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
            $this->device(self::CHROME_124),
            $this->device(self::CHROME_140),
        );
    }

    public function testADifferentBrowserIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->device(self::CHROME_140),
            $this->device(self::FIREFOX_140),
        );
    }

    public function testADifferentSystemIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->device(self::CHROME_140),
            $this->device(self::CHROME_ON_ANDROID),
        );
    }

    /**
     * The point of keeping the network out of the device key: a laptop is the same laptop at home, on campus and on a
     * phone's hotspot, and were the address part of the key each pairing would be announced as a new device.
     */
    public function testMovingBetweenNetworksIsTheSameDevice(): void
    {
        self::assertSame(
            $this->device(
                self::CHROME_140,
                '192.0.2.10',
            ),
            $this->device(
                self::CHROME_140,
                '198.51.100.10',
            ),
        );
    }

    public function testAnotherNetworkIsAnotherNetwork(): void
    {
        self::assertNotSame(
            $this->network('192.0.2.10'),
            $this->network('198.51.100.10'),
        );
    }

    /**
     * A router handing out a different address on the same network is the same place.
     */
    public function testAnotherAddressOnTheSameNetworkIsTheSameNetwork(): void
    {
        self::assertSame(
            $this->network('192.0.2.10'),
            $this->network('192.0.2.240'),
        );
    }

    /**
     * An address that does not parse is no network at all rather than a network of its own: it is what an attacker
     * would vary to look like somebody else's network, and recognition must not vouch for it.
     */
    public function testAMalformedAddressIsNoNetworkAtAll(): void
    {
        self::assertNull($this->network('not-an-address'));
        self::assertNull($this->network(null));
    }

    /**
     * The two hashes are fed the same secret, so they must never be able to collide: a network fingerprint that could
     * equal a device fingerprint would let one table vouch for the other.
     */
    public function testADeviceFingerprintIsNeverANetworkFingerprint(): void
    {
        $described = $this->describe(
            self::CHROME_140,
            '192.0.2.10',
        );

        self::assertNotSame(
            $described['device'],
            $described['network'],
        );
    }

    /**
     * What tells apart two people who are otherwise the same browser on the same system.
     */
    public function testAnotherSetOfLanguagesIsADifferentDevice(): void
    {
        self::assertNotSame(
            $this->device(
                self::CHROME_140,
                languages: 'nl-NL,nl;q=0.9,en;q=0.8',
            ),
            $this->device(
                self::CHROME_140,
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
            $this->device(
                self::CHROME_140,
                languages: 'nl-NL,nl;q=0.9,en;q=0.8',
            ),
            $this->device(
                self::CHROME_140,
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
        $absent = $this->device(
            self::CHROME_140,
            languages: null,
        );

        self::assertSame(
            $absent,
            $this->device(
                self::CHROME_140,
                languages: '',
            ),
        );
        self::assertSame(
            $absent,
            $this->device(
                self::CHROME_140,
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
            $this->device(
                self::CHROME_140,
                languages: null,
            ),
            $this->device(
                self::CHROME_140,
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
        $garbled = $this->device(
            self::CHROME_140,
            languages: '???',
        );

        self::assertSame(
            $garbled,
            $this->device(
                self::CHROME_140,
                languages: '???',
            ),
        );
        self::assertNotSame(
            $garbled,
            $this->device(
                self::CHROME_140,
                languages: null,
            ),
        );
    }

    /**
     * Both keys are keyed on the application secret, so the same device on two installations does not share one.
     */
    public function testTheSecretChangesBothFingerprints(): void
    {
        $one = $this->describe(
            self::CHROME_140,
            '192.0.2.10',
        );
        $other = $this->describe(
            self::CHROME_140,
            '192.0.2.10',
            'another secret',
        );

        self::assertNotSame(
            $one['device'],
            $other['device'],
        );
        self::assertNotSame(
            $one['network'],
            $other['network'],
        );
    }

    private function device(
        string $userAgent,
        ?string $address = '192.0.2.10',
        ?string $languages = 'nl-NL,nl;q=0.9,en;q=0.8',
    ): string {
        return $this->describe(
            $userAgent,
            $address,
            languages: $languages,
        )['device'];
    }

    private function network(?string $address): ?string
    {
        return $this->describe(
            self::CHROME_140,
            $address,
        )['network'];
    }

    /**
     * @return array{device: string, network: ?string, browser: ?string, operatingSystem: ?string}
     */
    private function describe(
        string $userAgent,
        ?string $address,
        string $secret = 'a secret',
        ?string $languages = 'nl-NL,nl;q=0.9,en;q=0.8',
    ): array {
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
            new IpNetworkResolver('/nonexistent'),
            $secret,
        )->describe($request);
    }
}
