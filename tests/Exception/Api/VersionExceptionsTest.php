<?php

declare(strict_types=1);

namespace App\Tests\Exception\Api;

use App\Entity\User\Enums\ApiPermissions;
use App\Exception\Api\VersionExpected;
use App\Exception\Api\VersionFormat;
use App\Exception\Api\VersionIncompatible;
use App\Exception\User\NotAllowed;
use PHLAK\SemVer\Version;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a consumer is told when its version is missing, unreadable or outside the range: the message names the version
 * it sent, and the status tells the client the request is understood but not acceptable.
 */
final class VersionExceptionsTest extends TestCase
{
    public function testAVersionThatWasNotGivenIsNotAcceptable(): void
    {
        $exception = new VersionExpected();

        self::assertSame(
            406,
            $exception->getHttpStatusCode(),
        );
        self::assertSame(
            'API version expected, but none was given',
            $exception->getMessage(),
        );
    }

    public function testAVersionThatCannotBeReadIsQuotedBackSafely(): void
    {
        $exception = new VersionFormat('not a version/with a slash');

        self::assertSame(
            406,
            $exception->getHttpStatusCode(),
        );
        self::assertSame(
            0,
            $exception->getCode(),
        );
        self::assertSame(
            'API version expected, but not%20a%20version%2Fwith%20a%20slash was given',
            $exception->getMessage(),
        );
    }

    public function testAVersionBelowTheMinimumNamesTheMinimum(): void
    {
        $exception = new VersionIncompatible(
            new Version('5.0.0'),
            null,
            new Version('4.9.0'),
        );

        self::assertSame(
            406,
            $exception->getHttpStatusCode(),
        );
        self::assertSame(
            0,
            $exception->getCode(),
        );
        self::assertSame(
            'API version must be at least 5.0.0, but 4.9.0 was given.',
            $exception->getMessage(),
        );
    }

    public function testAVersionOutsideTheRangeNamesBothEnds(): void
    {
        $exception = new VersionIncompatible(
            new Version('4.0.0'),
            new Version('5.0.0'),
            new Version('6.0.0'),
        );

        self::assertSame(
            406,
            $exception->getHttpStatusCode(),
        );
        self::assertSame(
            'API version must be between 4.0.0 and 5.0.0, but 6.0.0 was given.',
            $exception->getMessage(),
        );
    }

    public function testTheCauseIsCarriedThrough(): void
    {
        $cause = new RuntimeException('underneath');

        self::assertSame(
            $cause,
            new VersionExpected(
                3,
                $cause,
            )->getPrevious(),
        );
        self::assertSame(
            3,
            new VersionExpected(3)->getCode(),
        );
    }

    public function testAMissingPermissionNamesWhatIsNeeded(): void
    {
        $exception = new NotAllowed(ApiPermissions::MembersR);

        self::assertSame(
            'Permission `members_read` is needed but is not currently held.',
            $exception->getMessage(),
        );
        self::assertSame(
            500,
            $exception->getHttpStatusCode(),
        );
    }
}
