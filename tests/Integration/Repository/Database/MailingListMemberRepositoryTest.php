<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member;
use App\Repository\Database\MailingListMemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;

use function array_map;

final class MailingListMemberRepositoryTest extends DatabaseTestCase
{
    private MailingListMemberRepository $repository;

    private EntityManagerInterface $ledger;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
        $this->repository = self::getContainer()->get(MailingListMemberRepository::class);
        $this->build = new LedgerBuilder($ledger);
    }

    public function testFindByListAndMemberIsTheExactPair(): void
    {
        $existing = $this->ledger->getRepository(MailingListMember::class)->findAll()[0];
        self::assertInstanceOf(
            MailingListMember::class,
            $existing,
        );
        self::assertNotNull($existing->member);

        self::assertSame(
            $existing,
            $this->repository->findByListAndMember(
                $existing->mailingList,
                $existing->member,
            ),
        );

        self::assertNull($this->repository->findByListAndMember(
            $existing->mailingList,
            $this->build->member(),
        ));
    }

    public function testPendingCountsFollowTheFlags(): void
    {
        $list = $this->list('announcements');
        $member = $this->build->member();

        $creationBefore = $this->repository->countPendingCreation();
        $this->repository->persist($this->membership(
            $list,
            $member,
        ));
        self::assertSame(
            $creationBefore + 1,
            $this->repository->countPendingCreation(),
        );

        $deletionBefore = $this->repository->countPendingDeletion();
        $leaving = $this->membership(
            $list,
            $this->build->member(),
        );
        $leaving->toBeDeleted = true;
        $this->repository->persist($leaving);
        self::assertSame(
            $deletionBefore + 1,
            $this->repository->countPendingDeletion(),
        );
    }

    public function testMarkAllMembersForCreationLeavesTheDeletionFlagAlone(): void
    {
        $list = $this->list('activities');

        $this->ledger->createQueryBuilder()
            ->update(
                MailingListMember::class,
                'mlm',
            )
            ->set(
                'mlm.toBeCreated',
                'false',
            )
            ->where('mlm.mailingList = :list')
            ->andWhere('mlm.toBeDeleted = false')
            ->setParameter(
                'list',
                $list,
            )
            ->getQuery()
            ->execute();

        $this->repository->markAllMembersForCreation($list);

        $notMarked = (int) $this->ledger->createQueryBuilder()
            ->select('COUNT(mlm.member)')
            ->from(
                MailingListMember::class,
                'mlm',
            )
            ->where('mlm.mailingList = :list')
            ->andWhere('mlm.toBeDeleted = false')
            ->andWhere('mlm.toBeCreated = false')
            ->setParameter(
                'list',
                $list,
            )
            ->getQuery()
            ->getSingleScalarResult();

        self::assertSame(
            0,
            $notMarked,
        );
    }

    public function testFindAllExpiredOrHiddenReturnsLapsedAndHiddenButNotCurrentVisibleMembers(): void
    {
        $list = $this->list('announcements');

        $expired = $this->build->member(
            MembershipTypes::Ordinary,
            '-2 years',
            '-1 year',
        );
        $hidden = $this->build->member();
        $hidden->hidden = true;
        $visible = $this->build->member();
        $this->ledger->flush();

        foreach ([$expired, $hidden, $visible] as $member) {
            $this->repository->persist($this->membership(
                $list,
                $member,
            ));
        }

        $lidnrs = array_map(
            static function (MailingListMember $membership): int {
                $member = $membership->member;
                self::assertNotNull($member);

                return $member->lidnr;
            },
            $this->repository->findAllExpiredOrHidden(),
        );

        self::assertContains(
            $expired->lidnr,
            $lidnrs,
        );
        self::assertContains(
            $hidden->lidnr,
            $lidnrs,
        );
        self::assertNotContains(
            $visible->lidnr,
            $lidnrs,
        );
    }

    public function testFindAllPendingLocalOnlyReturnsOnlyListsWithoutAServer(): void
    {
        $list = $this->localList();
        $pending = $this->membership(
            $list,
            $this->build->member(),
        );
        $this->repository->persist($pending);

        $leaving = $this->membership(
            $list,
            $this->build->member(),
        );
        $leaving->toBeCreated = false;
        $leaving->toBeDeleted = true;
        $this->repository->persist($leaving);

        $settled = $this->membership(
            $list,
            $this->build->member(),
        );
        $settled->toBeCreated = false;
        $this->repository->persist($settled);

        $keys = array_map(
            static fn (MailingListMember $membership): string => $membership->mailingList->name
                . '|' . $membership->email,
            $this->repository->findAllPendingLocalOnly(),
        );

        self::assertContains(
            $pending->mailingList->name . '|' . $pending->email,
            $keys,
        );
        self::assertContains(
            $leaving->mailingList->name . '|' . $leaving->email,
            $keys,
        );
        self::assertNotContains(
            $settled->mailingList->name . '|' . $settled->email,
            $keys,
        );

        foreach ($this->repository->findAllPendingLocalOnly() as $membership) {
            self::assertNull($membership->mailingList->mailmanList);
            self::assertNull($membership->mailingList->listmonkList);
        }
    }

    public function testRemoveDeletesTheMembership(): void
    {
        $list = $this->list('announcements');
        $member = $this->build->member();
        $membership = $this->membership(
            $list,
            $member,
        );
        $this->repository->persist($membership);

        $this->repository->remove($membership);

        self::assertNull($this->repository->findByListAndMember(
            $list,
            $member,
        ));
    }

    private function membership(
        MailingList $list,
        Member $member,
    ): MailingListMember {
        $membership = new MailingListMember();
        $membership->mailingList = $list;
        $membership->setMember($member);
        $membership->email = $member->email ?? throw new LogicException('The member needs an address.');

        return $membership;
    }

    private function list(string $name): MailingList
    {
        $list = $this->ledger->getRepository(MailingList::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(
            MailingList::class,
            $list,
        );

        return $list;
    }

    private function localList(): MailingList
    {
        $list = new MailingList();
        $list->name = 'local-only-test';
        $list->onForm = false;
        $list->defaultSub = false;
        $list->setEnDescription('A list without a server.');
        $list->setNlDescription('Een lijst zonder server.');

        $this->ledger->persist($list);
        $this->ledger->flush();

        return $list;
    }
}
