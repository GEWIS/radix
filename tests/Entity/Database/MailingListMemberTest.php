<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database;

use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailingListMember::class)]
final class MailingListMemberTest extends TestCase
{
    /**
     * A subscription is entered against an address, so linking a member records the address they subscribed with.
     */
    public function testLinkingAMemberRecordsTheirEmailAddress(): void
    {
        $membership = new MailingListMember();
        $membership->setMember($this->member('lid@example.org'));

        self::assertSame(
            'lid@example.org',
            $membership->email,
        );
        self::assertSame(
            8100,
            $membership->member?->lidnr,
        );
    }

    /**
     * A member without an address cannot be on a mailing list; writing an empty address would queue a deletion
     * instead.
     */
    public function testAMemberWithoutAnEmailAddressCannotBeLinked(): void
    {
        $this->expectException(LogicException::class);

        new MailingListMember()->setMember($this->member(null));
    }

    /**
     * The link to the member is cleared only when the subscription is removed, so a live row keeps its member.
     */
    public function testTheMemberCanOnlyBeUnsetWhenTheSubscriptionIsBeingDeleted(): void
    {
        $membership = new MailingListMember();
        $membership->setMember($this->member('lid@example.org'));

        try {
            $membership->unsetMember();
            self::fail('Expected a LogicException');
        } catch (LogicException) {
            self::assertSame(
                8100,
                $membership->member?->lidnr,
            );
        }

        $membership->toBeDeleted = true;
        $membership->unsetMember();

        self::assertNull($membership->member);
    }

    /**
     * The sync timestamp is what tells the synchroniser this row has been handled; until it is set the row is new.
     */
    public function testTheSyncTimestampRecordsWhenTheRowWasHandled(): void
    {
        $membership = new MailingListMember();
        self::assertNull($membership->lastSyncOn);
        self::assertFalse($membership->lastSyncSuccess);
        self::assertTrue($membership->toBeCreated);

        $syncedAt = new DateTimeImmutable('2026-03-01 12:00:00');
        $membership->setLastSyncOn($syncedAt);

        self::assertSame(
            $syncedAt,
            $membership->lastSyncOn,
        );
    }

    private function member(?string $email): Member
    {
        $member = new Member();
        $member->lidnr = 8100;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';
        $member->changedOn = new DateTimeImmutable('2026-01-01');
        $member->setEmail($email);

        return $member;
    }
}
