<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\UrlSafeToken;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function strlen;

/**
 * The alphabet is shared by the remember-me handler and the known-device registry, so the two cannot differ on what a
 * token looks like.
 */
final class UrlSafeTokenTest extends TestCase
{
    public function testATokenIsUrlSafe(): void
    {
        self::assertSame(
            1,
            preg_match(
                '/^[A-Za-z0-9_-]+$/',
                UrlSafeToken::generate(),
            ),
        );
    }

    public function testTheLengthFollowsTheEntropyAskedFor(): void
    {
        // 32 bytes base64-encoded is 43 characters once the padding is dropped.
        self::assertSame(
            43,
            strlen(UrlSafeToken::generate()),
        );
        self::assertSame(
            59,
            strlen(UrlSafeToken::generate(44)),
        );
    }

    public function testTwoTokensAreNotTheSame(): void
    {
        self::assertNotSame(
            UrlSafeToken::generate(),
            UrlSafeToken::generate(),
        );
    }
}
