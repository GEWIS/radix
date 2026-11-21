<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\Decision\Member;
use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\UserTypes;
use App\Entity\User\PasswordReset;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordReset::class)]
final class PasswordResetTest extends TestCase
{
    /**
     * A reset belongs to exactly one account space; the type follows from which account it names.
     */
    public function testAResetForAMemberIsAUserReset(): void
    {
        $reset = new PasswordReset(
            new DateTimeImmutable('+1 hour'),
            'selector',
            'hash',
            $this->member(),
        );

        self::assertSame(
            UserTypes::User,
            $reset->userType,
        );
        self::assertSame(
            8100,
            $reset->member?->lidnr,
        );
        self::assertNull($reset->companyUser);
    }

    /**
     * A reset that names nobody, or names both an account and a company user, cannot say whose password it may change.
     */
    public function testAResetMustNameExactlyOneAccount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PasswordReset(
            new DateTimeImmutable('+1 hour'),
            'selector',
            'hash',
        );
    }

    public function testAResetCannotNameBothAccountSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PasswordReset(
            new DateTimeImmutable('+1 hour'),
            'selector',
            'hash',
            $this->member(),
            new CompanyUser(),
        );
    }

    private function member(): Member
    {
        $member = new Member();
        $member->lidnr = 8100;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';

        return $member;
    }
}
