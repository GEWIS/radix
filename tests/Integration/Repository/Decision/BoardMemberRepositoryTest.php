<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Decision\BoardMember;
use App\Repository\Decision\BoardMemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function count;
use function iterator_to_array;

final class BoardMemberRepositoryTest extends DatabaseTestCase
{
    private BoardMemberRepository $repository;

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
        $this->repository = self::getContainer()->get(BoardMemberRepository::class);
        $this->build = new LedgerBuilder($ledger);
    }

    public function testCurrentBoardMembersAreOrderedByInstallationDate(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateBoardMembers(
                false,
                false,
                1,
                5000,
            ),
            false,
        );

        self::assertNotEmpty($page);

        $now = new DateTimeImmutable();
        $previous = null;
        foreach ($page as $boardMember) {
            self::assertLessThanOrEqual(
                $now,
                $boardMember->installDate,
            );
            self::assertTrue(
                null === $boardMember->releaseDate || $boardMember->releaseDate > $now,
            );
            self::assertTrue(
                null === $boardMember->dischargeDate || $boardMember->dischargeDate > $now,
            );
            self::assertFalse($boardMember->member->deleted);

            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $boardMember->installDate,
                );
            }

            $previous = $boardMember->installDate;
        }
    }

    public function testIncludingFormerMembersReturnsAtLeastTheCurrentBoard(): void
    {
        $current = count(iterator_to_array($this->repository->paginateBoardMembers(
            false,
            false,
            1,
            5000,
        )));
        $former = count(iterator_to_array($this->repository->paginateBoardMembers(
            true,
            false,
            1,
            5000,
        )));

        self::assertGreaterThan(
            $current,
            $former,
        );
    }

    public function testADeletedBoardMemberOnlyAppearsWhenDeletedMembersAreIncluded(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $this->build->installBoard(
            $meeting,
            $member,
        );
        $member->deleted = true;
        $this->ledger->flush();

        $withoutDeleted = $this->memberNumbers($this->repository->paginateBoardMembers(
            true,
            false,
            1,
            5000,
        ));
        $withDeleted = $this->memberNumbers($this->repository->paginateBoardMembers(
            true,
            true,
            1,
            5000,
        ));

        self::assertNotContains(
            $member->lidnr,
            $withoutDeleted,
        );
        self::assertContains(
            $member->lidnr,
            $withDeleted,
        );
    }

    public function testThePageSizeIsApplied(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateBoardMembers(
                true,
                true,
                1,
                2,
            ),
            false,
        );

        self::assertCount(
            2,
            $page,
        );
    }

    /**
     * @param iterable<BoardMember> $page
     *
     * @return list<int>
     */
    private function memberNumbers(iterable $page): array
    {
        $numbers = [];
        foreach ($page as $boardMember) {
            $numbers[] = $boardMember->member->lidnr;
        }

        return $numbers;
    }
}
