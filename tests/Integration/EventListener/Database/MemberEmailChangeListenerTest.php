<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener\Database;

use App\Entity\Database\AuditEmailChange;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member;
use App\EventListener\Database\MemberEmailChangeListener;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MemberEmailChangeListener::class)]
final class MemberEmailChangeListenerTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get('doctrine')->getManager('default');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
    }

    public function testCarriesSubscriptionsOverToTheNewAddress(): void
    {
        $member = $this->subscribedMember();
        $before = $this->subscriptionsOf($member);

        self::assertNotEmpty(
            $before,
            'The seed is expected to contain a member on at least one mailing list.',
        );

        $newEmail = 'carried-over@example.org';

        $member->setEmail($newEmail);
        $this->ledger->flush();

        foreach ($before as $subscription) {
            self::assertTrue(
                $subscription->isToBeDeleted(),
                'The subscription under the address that was replaced is on its way out.',
            );
        }

        $carried = [];
        foreach ($this->subscriptionsOf($member) as $subscription) {
            if ($newEmail !== $subscription->getEmail()) {
                continue;
            }

            $carried[$subscription->getMailingList()->getName()] = $subscription;
            self::assertTrue($subscription->isToBeCreated());
        }

        foreach ($before as $subscription) {
            self::assertArrayHasKey(
                $subscription->getMailingList()->getName(),
                $carried,
                'Every list the member was on under the old address is one they are on under the new one.',
            );
        }
    }

    public function testWritesTheChangeIntoTheAuditTrail(): void
    {
        $member = $this->subscribedMember();
        $oldEmail = $member->getEmail();

        $member->setEmail('audited@example.org');
        $this->ledger->flush();

        $audit = $this->ledger->getRepository(AuditEmailChange::class)->findOneBy(
            ['member' => $member->getLidnr()],
            ['id' => 'DESC'],
        );

        self::assertInstanceOf(
            AuditEmailChange::class,
            $audit,
        );
        self::assertSame(
            $oldEmail,
            $audit->getOldEmail(),
        );
        self::assertSame(
            'audited@example.org',
            $audit->getNewEmail(),
        );
    }

    public function testDoesNothingWhenTheAddressIsUnchanged(): void
    {
        $member = $this->subscribedMember();
        $auditRepository = $this->ledger->getRepository(AuditEmailChange::class);
        $before = $auditRepository->count([]);

        $member->setEmail($member->getEmail());
        $this->ledger->flush();

        self::assertSame(
            $before,
            $auditRepository->count([]),
        );
    }

    private function subscribedMember(): Member
    {
        $subscription = $this->ledger->getRepository(MailingListMember::class)->findOneBy(['toBeDeleted' => false]);
        self::assertInstanceOf(
            MailingListMember::class,
            $subscription,
        );

        $member = $subscription->getMember();
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }

    /**
     * @return MailingListMember[]
     */
    private function subscriptionsOf(Member $member): array
    {
        $subscriptions = [];

        foreach ($member->getMailingListMemberships() as $subscription) {
            if ($subscription->isToBeDeleted()) {
                continue;
            }

            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }
}
