<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Database\MailingList as DatabaseMailingList;
use App\Entity\Database\MailingListMember as DatabaseMailingListMember;
use App\Repository\Decision\MailingListMemberRepository;
use App\Repository\Decision\MailingListRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function count;
use function iterator_to_array;
use function sort;

final class MailingListRepositoryTest extends DatabaseTestCase
{
    private MailingListRepository $lists;

    private MailingListMemberRepository $members;

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
        $this->lists = self::getContainer()->get(MailingListRepository::class);
        $this->members = self::getContainer()->get(MailingListMemberRepository::class);
        $this->build = new LedgerBuilder($ledger);
    }

    public function testPaginateListsOrdersByNameAndClampsThePage(): void
    {
        $page = iterator_to_array(
            $this->lists->paginateLists(
                1,
                1,
            ),
            false,
        );

        self::assertCount(
            1,
            $page,
        );

        $all = iterator_to_array(
            $this->lists->paginateLists(
                1,
                100,
            ),
            false,
        );
        self::assertGreaterThanOrEqual(
            2,
            count($all),
        );

        $names = [];
        foreach ($all as $list) {
            $names[] = $list->name;
        }

        $sorted = $names;
        sort($sorted);
        self::assertSame(
            $sorted,
            $names,
        );
    }

    public function testPaginateSubscribersIsScopedToTheListAndOrdered(): void
    {
        $page = iterator_to_array(
            $this->members->paginateSubscribers(
                'announcements',
                true,
                1,
                100,
            ),
            false,
        );

        self::assertNotEmpty($page);

        $previous = 0;
        foreach ($page as $membership) {
            self::assertSame(
                'announcements',
                $membership->mailingList->name,
            );
            self::assertGreaterThanOrEqual(
                $previous,
                $membership->member->lidnr,
            );
            $previous = $membership->member->lidnr;
        }

        self::assertSame(
            [],
            iterator_to_array($this->members->paginateSubscribers(
                'no-such-list',
                true,
                1,
                100,
            )),
        );
    }

    public function testADeletedSubscriberOnlyAppearsWhenDeletedOnesAreIncluded(): void
    {
        $databaseList = $this->ledger->getRepository(DatabaseMailingList::class)
            ->findOneBy(['name' => 'announcements']);
        self::assertInstanceOf(
            DatabaseMailingList::class,
            $databaseList,
        );

        $member = $this->build->member();
        $membership = new DatabaseMailingListMember();
        $membership->mailingList = $databaseList;
        $membership->setMember($member);
        $membership->email = (string) $member->email;
        $this->ledger->persist($membership);
        $this->ledger->flush();

        $member->deleted = true;
        $this->ledger->flush();

        $withoutDeleted = $this->memberNumbers(false);
        $withDeleted = $this->memberNumbers(true);

        self::assertNotContains(
            $member->lidnr,
            $withoutDeleted,
        );
        self::assertContains(
            $member->lidnr,
            $withDeleted,
        );
    }

    public function testPaginateSubscribersClampsThePage(): void
    {
        $page = iterator_to_array(
            $this->members->paginateSubscribers(
                'announcements',
                true,
                1,
                1,
            ),
            false,
        );

        self::assertCount(
            1,
            $page,
        );
    }

    /**
     * @return list<int>
     */
    private function memberNumbers(bool $includeDeleted): array
    {
        $numbers = [];
        foreach (
            $this->members->paginateSubscribers(
                'announcements',
                $includeDeleted,
                1,
                5000,
            ) as $membership
        ) {
            $numbers[] = $membership->member->lidnr;
        }

        return $numbers;
    }
}
