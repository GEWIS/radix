<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Checker;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\RenewalLink;
use App\Repository\Checker\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function array_map;
use function array_values;
use function count;

/**
 * Who the renewal run writes to: graduates whose last membership is ending and who have not been sent a link for it
 * yet. Everybody else is left alone: the hidden, the ordinary members, the ones who already have a link, and the ones
 * whose graduate year is behind them.
 */
final class ExpiringGraduatesTest extends DatabaseTestCase
{
    private MemberRepository $repository;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $entityManager,
        );

        $this->entityManager = $entityManager;
        $this->repository = self::getContainer()->get(MemberRepository::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testAGraduateWhoseLastMembershipEndsIsDue(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );

        self::assertContains(
            $graduate->lidnr,
            $this->lidnrs($this->due()),
        );
    }

    public function testAGraduateBeyondTheDateAskedForIsNotYetDue(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );

        $due = $this->lidnrs($this->repository->getExpiringGraduates(
            new DateTimeImmutable('-1 day'),
        ));

        self::assertNotContains(
            $graduate->lidnr,
            $due,
        );
    }

    public function testAHiddenMemberIsNeverWrittenTo(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );
        $graduate->hidden = true;
        $this->entityManager->flush();

        self::assertNotContains(
            $graduate->lidnr,
            $this->lidnrs($this->due()),
        );
    }

    public function testADeletedMemberIsNeverWrittenTo(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );
        $graduate->deleted = true;
        $this->entityManager->flush();

        self::assertNotContains(
            $graduate->lidnr,
            $this->lidnrs($this->due()),
        );
    }

    public function testAMemberWithoutAnAddressIsNeverWrittenTo(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );
        $graduate->setEmail(null);
        $this->entityManager->flush();

        self::assertNotContains(
            $graduate->lidnr,
            $this->lidnrs($this->due()),
        );
    }

    public function testAMemberWhoAlreadyHasALinkForThisExpirationIsNotSentAnother(): void
    {
        $graduate = $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );
        self::assertContains(
            $graduate->lidnr,
            $this->lidnrs($this->due()),
        );

        $this->entityManager->persist(new RenewalLink(
            $graduate,
            new DateTimeImmutable('+13 months'),
        ));
        $this->entityManager->flush();

        self::assertNotContains(
            $graduate->lidnr,
            $this->lidnrs($this->due()),
        );
    }

    public function testOnlyAGraduateMembershipCounts(): void
    {
        $ordinary = $this->build->member(
            MembershipTypes::Ordinary,
            '-11 months',
            '+1 month',
        );

        self::assertNotContains(
            $ordinary->lidnr,
            $this->lidnrs($this->due()),
        );
    }

    /**
     * The graduate year has to be the last thing on the member's record: someone who graduated and then became an
     * ordinary member again is not a graduate whose membership is running out.
     */
    public function testAGraduateMembershipThatWasFollowedByAnotherIsNotTheLastOne(): void
    {
        $member = $this->build->member(
            MembershipTypes::Graduate,
            '-25 months',
            '-13 months',
        );
        $member->addMembership(new Membership(
            $member,
            MembershipTypes::Ordinary,
            new DateTimeImmutable('+1 month'),
            new DateTimeImmutable('+13 months'),
        ));
        $this->entityManager->flush();

        self::assertNotContains(
            $member->lidnr,
            $this->lidnrs($this->due()),
        );
    }

    public function testTheRunCanBeCapped(): void
    {
        $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );
        $this->build->member(
            MembershipTypes::Graduate,
            '-11 months',
            '+1 month',
        );

        self::assertNotEmpty($this->due());
        self::assertCount(
            1,
            $this->repository->getExpiringGraduates(
                new DateTimeImmutable('+2 months'),
                1,
            ),
        );
    }

    /**
     * @return Member[]
     */
    private function due(): array
    {
        return $this->repository->getExpiringGraduates(new DateTimeImmutable('+2 months'));
    }

    /**
     * @param Member[] $members
     *
     * @return list<int>
     */
    private function lidnrs(array $members): array
    {
        $lidnrs = array_values(array_map(
            static fn (Member $member): int => $member->lidnr,
            $members,
        ));

        self::assertCount(
            count($members),
            $lidnrs,
        );

        return $lidnrs;
    }
}
