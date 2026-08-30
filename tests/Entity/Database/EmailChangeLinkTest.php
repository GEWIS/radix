<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database;

use App\Entity\Database\EmailChangeLink;
use App\Entity\Database\Member;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailChangeLink::class)]
class EmailChangeLinkTest extends TestCase
{
    public function testHoldsBothAddressesAndLeavesTheMemberAlone(): void
    {
        $member = $this->member('timon@example.com');
        $link = new EmailChangeLink(
            $member,
            'timon@example.org',
        );

        self::assertSame(
            'timon@example.org',
            $link->getNewEmail(),
        );
        self::assertSame(
            'timon@example.com',
            $link->getPreviousEmail(),
        );
        self::assertSame(
            'timon@example.com',
            $member->getEmail(),
        );
        self::assertFalse($link->isUsed());
    }

    public function testIsGoodForADay(): void
    {
        $link = new EmailChangeLink(
            $this->member('timon@example.com'),
            'timon@example.org',
        );

        self::assertFalse($link->linkExpired());
        self::assertEqualsWithDelta(
            new DateTimeImmutable('+1 day')->getTimestamp(),
            $link->getExpiresAt()->getTimestamp(),
            5,
        );
    }

    public function testAcceptsAMemberWhoHadNoAddress(): void
    {
        $link = new EmailChangeLink(
            new Member(),
            'timon@example.org',
        );

        self::assertNull($link->getPreviousEmail());
    }

    private function member(string $email): Member
    {
        $member = new Member();
        $member->setEmail($email);

        return $member;
    }
}
