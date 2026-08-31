<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\CredentialsSignature;
use DateTime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class CredentialsSignatureTest extends TestCase
{
    private const string SECRET = 'a secret that is only ever this test\'s';

    public function testAnUnchangedAccountKeepsItsFingerprint(): void
    {
        $signature = new CredentialsSignature(self::SECRET);

        self::assertTrue($signature->matches(
            $signature->hash($this->account()),
            $this->account(),
        ));
    }

    public function testANewPasswordMovesTheFingerprint(): void
    {
        $signature = new CredentialsSignature(self::SECRET);
        $stored = $signature->hash($this->account());

        self::assertFalse($signature->matches(
            $stored,
            $this->account(password: 'a different hash entirely'),
        ));
    }

    public function testTheMomentThePasswordChangedMovesTheFingerprint(): void
    {
        $signature = new CredentialsSignature(self::SECRET);
        $stored = $signature->hash($this->account());

        self::assertFalse($signature->matches(
            $stored,
            $this->account(passwordChangedOn: new DateTime('2026-08-31 12:00:00')),
        ));
    }

    public function testTheForcedReloginStampMovesTheFingerprint(): void
    {
        $signature = new CredentialsSignature(self::SECRET);
        $stored = $signature->hash($this->account());

        self::assertFalse($signature->matches(
            $stored,
            $this->account(forceReloginAt: new DateTime('2026-08-31 12:00:00')),
        ));
    }

    public function testTakingASecondFactorOffMovesTheFingerprint(): void
    {
        $signature = new CredentialsSignature(self::SECRET);
        $stored = $signature->hash($this->account(totpSecret: 'a secret the account no longer holds'));

        self::assertFalse($signature->matches(
            $stored,
            $this->account(),
        ));
    }

    public function testAFingerprintDoesNotCarryToAnotherSecret(): void
    {
        $stored = new CredentialsSignature(self::SECRET)->hash($this->account());

        self::assertFalse(new CredentialsSignature('somebody else\'s secret')->matches(
            $stored,
            $this->account(),
        ));
    }

    private function account(
        string $password = 'the hash the account is holding',
        ?DateTime $passwordChangedOn = null,
        ?DateTime $forceReloginAt = null,
        ?string $totpSecret = null,
    ): UserInterface {
        return new class (
            $password,
            $passwordChangedOn,
            $forceReloginAt,
            $totpSecret,
        ) implements UserInterface {
            public function __construct(
                public readonly string $password,
                public readonly ?DateTime $passwordChangedOn,
                public readonly ?DateTime $forceReloginAt,
                public readonly ?string $totpSecret,
            ) {
            }

            /**
             * @return string[]
             */
            public function getRoles(): array
            {
                return [];
            }

            public function getUserIdentifier(): string
            {
                return '8025';
            }
        };
    }
}
