<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Entity\User\Enums\DeviceTypes;
use App\Security\User\UserAgentParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The family is what two spellings of the same browser compare equal on, and a bot is not a device a member signed in
 * from.
 */
final class UserAgentParserBranchesTest extends TestCase
{
    public function testAFamilyIsTheNameWithoutItsVersion(): void
    {
        self::assertSame(
            'chrome',
            UserAgentParser::family('Chrome 124'),
        );
        self::assertSame(
            'chrome',
            UserAgentParser::family('Chrome'),
        );
        self::assertNull(UserAgentParser::family(null));
    }

    public function testABotIsRecognisedAsABotWithoutAnOperatingSystem(): void
    {
        $parsed = new UserAgentParser()->parse(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        );

        self::assertSame(
            DeviceTypes::Bot,
            $parsed['type'],
        );
        self::assertNull($parsed['operatingSystem']);
        self::assertNotNull($parsed['browser']);
    }

    /**
     * Client hints alone are enough to identify a device, even where the user agent is empty.
     */
    public function testClientHintsWithoutAUserAgentAreStillParsed(): void
    {
        $parsed = new UserAgentParser()->parse(
            '',
            [
                'sec-ch-ua' => '"Chromium";v="124", "Google Chrome";v="124"',
                'sec-ch-ua-platform' => '"Windows"',
                'sec-ch-ua-platform-version' => '"15.0.0"',
            ],
        );

        self::assertSame(
            'Chrome 124',
            $parsed['browser'],
        );
        self::assertSame(
            'Windows 11',
            $parsed['operatingSystem'],
        );
    }

    /**
     * A hint that is present but empty is not a statement of a version.
     */
    public function testAnEmptyHintIsNotAVersion(): void
    {
        $parsed = new UserAgentParser()->parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0.0.0 Safari/537.36',
            [
                'sec-ch-ua-platform' => '"Windows"',
                'sec-ch-ua-platform-version' => '""',
            ],
        );

        self::assertSame(
            'Windows',
            $parsed['operatingSystem'],
        );
    }

    public function testAnEmptyUserAgentAndNoHintsIsUnknown(): void
    {
        $parsed = new UserAgentParser()->parse('   ');

        self::assertSame(
            DeviceTypes::Unknown,
            $parsed['type'],
        );
        self::assertNull($parsed['browser']);
        self::assertNull($parsed['operatingSystem']);
    }

    public function testARequestWithoutAUserAgentHeaderIsUnknown(): void
    {
        $parsed = new UserAgentParser()->parseRequest(Request::create('/'));

        self::assertSame(
            DeviceTypes::Unknown,
            $parsed['type'],
        );
    }
}
