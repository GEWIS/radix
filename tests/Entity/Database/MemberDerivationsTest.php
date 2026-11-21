<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\RenewalLink;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the ledger derives about a member from their memberships: when their membership ends, which generation they
 * belong to, and what happens to their mailing-list registrations when their address changes. A graduate membership is
 * not a formal membership, which is the distinction the register's expiry dates use.
 */
#[CoversClass(Member::class)]
final class MemberDerivationsTest extends TestCase
{
    public function testTheExpirationIsTheEndOfTheLastMembership(): void
    {
        $member = $this->member();
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-2 years'),
            new DateTimeImmutable('-1 month'),
        ));
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-1 month'),
            new DateTimeImmutable('+11 months'),
        ));

        self::assertSame(
            new DateTimeImmutable('+11 months')->format('Y-m-d'),
            $member->getExpiration()->format('Y-m-d'),
        );
    }

    public function testAGraduateYearIsNotAFormalMembershipButItDoesSetTheExpiration(): void
    {
        $member = $this->member();
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-3 years'),
            new DateTimeImmutable('-1 year'),
        ));
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Graduate,
            new DateTimeImmutable('-1 year'),
            new DateTimeImmutable('+1 month'),
        ));

        // A graduate is not a member under the articles of association, so their year does not end a membership.
        self::assertSame(
            new DateTimeImmutable('-1 year')->format('Y-m-d'),
            $member->getMembershipEndDate()?->format('Y-m-d'),
        );
        // The expiration is still the last day of the record, whatever kind of year it was.
        self::assertSame(
            new DateTimeImmutable('+1 month')->format('Y-m-d'),
            $member->getExpiration()->format('Y-m-d'),
        );
    }

    public function testAMemberWithNoMembershipsExpiresAtTheSentinelDate(): void
    {
        $member = $this->member();

        self::assertNull($member->getMembershipEndDate());
        self::assertSame(
            '0001-01-01',
            $member->getMembershipEndsOn()->format('Y-m-d'),
        );
        self::assertSame(
            '0001-01-01',
            $member->getExpiration()->format('Y-m-d'),
        );
        self::assertSame(
            0,
            $member->getGeneration(),
        );
        self::assertNull($member->getCurrentMembership());
        self::assertNull($member->getLastMembership());
        self::assertNull($member->getCurrentOrLastMembership());
    }

    public function testTheGenerationIsTheAssociationYearTheMemberJoined(): void
    {
        $member = $this->member();
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('2020-09-01'),
            new DateTimeImmutable('2021-06-30'),
        ));

        self::assertSame(
            2020,
            $member->getGeneration(),
        );
    }

    public function testMembershipsOfSomebodyElseCannotBeAdded(): void
    {
        $member = $this->member();
        $other = $this->member(
            4343,
            'other@example.org',
        );

        $this->expectException(RuntimeException::class);

        $member->addMembership(new Membership(
            $other,
            MembershipTypes::Ordinary,
        ));
    }

    public function testTheCurrentMembershipIsTheOneRunningToday(): void
    {
        $member = $this->member();
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-2 years'),
            new DateTimeImmutable('-1 year'),
        ));

        self::assertNull($member->getCurrentMembership());
        // With nothing running, the record's last year is what the register shows.
        self::assertNotNull($member->getCurrentOrLastMembership());

        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-1 month'),
            new DateTimeImmutable('+11 months'),
        ));

        self::assertNotNull($member->getCurrentMembership());
        self::assertSame(
            $member->getCurrentMembership(),
            $member->getCurrentOrLastMembership(),
        );
    }

    public function testEveryListRegistrationIsRescheduledWhenTheAddressChanges(): void
    {
        $member = $this->member();
        $list = new MailingList();
        $registration = new MailingListMember();
        $registration->mailingList = $list;
        $registration->setMember($member);
        $member->addList($registration);

        $member->setEmail('new@example.org');

        self::assertTrue(
            $registration->toBeDeleted,
            'The registration under the old address is withdrawn.',
        );
        self::assertCount(
            2,
            $member->getMailingListMemberships(),
        );

        $added = null;
        foreach ($member->getMailingListMemberships() as $membership) {
            if ($membership === $registration) {
                continue;
            }

            $added = $membership;
        }

        self::assertNotNull($added);
        self::assertSame(
            'new@example.org',
            $added->email,
        );
        self::assertSame(
            $list,
            $added->mailingList,
        );
        self::assertFalse($added->toBeDeleted);
    }

    /**
     * Adding the address a pending registration is already waiting for would leave two registrations for one address.
     */
    public function testAnAddressAPendingRegistrationUsesIsRefused(): void
    {
        $member = $this->member();
        $registration = new MailingListMember();
        $registration->mailingList = new MailingList();
        $member->addList($registration);
        // The registration already waits for the address that is about to be given to the member.
        $registration->email = 'new@example.org';

        $this->expectException(RuntimeException::class);

        $member->setEmail('new@example.org');
    }

    public function testSettingTheSameAddressAgainChangesNothing(): void
    {
        $member = $this->member();
        $registration = new MailingListMember();
        $registration->mailingList = new MailingList();
        $registration->setMember($member);
        $member->addList($registration);

        $member->setEmail('member@example.org');

        self::assertFalse($registration->toBeDeleted);
        self::assertCount(
            1,
            $member->getMailingListMemberships(),
        );
    }

    public function testARenewalLinkCountsUntilItHasBeenIdleForAMonth(): void
    {
        $member = $this->member();
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-1 year'),
            new DateTimeImmutable('+1 month'),
        ));

        self::assertFalse($member->hasActiveRenewalLink());

        $running = new RenewalLink(
            $member,
            new DateTimeImmutable('+13 months'),
        );
        $member->getRenewalLinks()->add($running);

        self::assertTrue($member->hasActiveRenewalLink());
    }

    public function testARenewalLinkForAYearThatEndedLongAgoNoLongerCounts(): void
    {
        $member = $this->member();
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('-2 years'),
            new DateTimeImmutable('-3 months'),
        ));

        $member->getRenewalLinks()->add(new RenewalLink(
            $member,
            new DateTimeImmutable('+9 months'),
        ));

        self::assertFalse($member->hasActiveRenewalLink());
    }

    public function testTheMailRecipientIsAbsentWithoutAnAddress(): void
    {
        $member = $this->member();
        self::assertNotNull($member->getEmailRecipient());
        self::assertSame(
            'member@example.org',
            $member->getEmailRecipient()->getAddress(),
        );

        $member->setEmail(null);

        self::assertNull($member->getEmailRecipient());
    }

    private function member(
        int $lidnr = 4242,
        string $email = 'member@example.org',
    ): Member {
        $member = new Member();
        $member->lidnr = $lidnr;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';
        $member->setEmail($email);
        $member->setBirth('2000-01-01');
        $member->changedOn = new DateTimeImmutable();

        return $member;
    }
}
