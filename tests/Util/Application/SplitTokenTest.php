<?php

declare(strict_types=1);

namespace App\Tests\Util\Application;

use App\Util\Application\SplitToken;
use PHPUnit\Framework\TestCase;

use function hash;
use function strlen;

/**
 * A leaked selector alone is useless, and the stored hash never reveals the token.
 */
final class SplitTokenTest extends TestCase
{
    public function testAGeneratedTokenIsASelectorAndAVerifier(): void
    {
        $generated = SplitToken::generate(
            16,
            32,
            'sha256',
        );

        self::assertSame(
            32,
            strlen($generated['selector']),
        );
        self::assertSame(
            $generated['selector'] . '.' . self::verifierOf($generated['token']),
            $generated['token'],
        );
        self::assertSame(
            hash(
                'sha256',
                self::verifierOf($generated['token']),
            ),
            $generated['hashedToken'],
        );
    }

    public function testAZeroLengthIsStillAtLeastOneByte(): void
    {
        $generated = SplitToken::generate(
            0,
            0,
            'sha256',
        );

        self::assertSame(
            2,
            strlen($generated['selector']),
        );
        self::assertSame(
            2,
            strlen(self::verifierOf($generated['token'])),
        );
    }

    public function testATokenIsSplitIntoItsTwoParts(): void
    {
        self::assertSame(
            [
                'selector' => 'abc',
                'verifier' => 'def',
            ],
            SplitToken::split('abc.def'),
        );
    }

    public function testATokenWithoutTwoPartsIsNotAToken(): void
    {
        self::assertNull(SplitToken::split('abcdef'));
        self::assertNull(SplitToken::split(''));
    }

    public function testTheVerifierIsComparedAgainstTheStoredHash(): void
    {
        $hash = hash(
            'sha256',
            'verifier',
        );

        self::assertTrue(SplitToken::matches($hash, 'verifier', 'sha256'));
        self::assertFalse(SplitToken::matches($hash, 'another verifier', 'sha256'));
    }

    private static function verifierOf(string $token): string
    {
        $parts = SplitToken::split($token);
        self::assertNotNull($parts);

        return $parts['verifier'];
    }
}
