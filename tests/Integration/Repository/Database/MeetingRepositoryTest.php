<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\SubDecision;
use App\Entity\Database\SubDecision\Board\Discharge as BoardDischarge;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Other;
use App\Repository\Database\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function array_map;
use function count;
use function iterator_to_array;

final class MeetingRepositoryTest extends DatabaseTestCase
{
    private MeetingRepository $repository;

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
        $this->repository = self::getContainer()->get(MeetingRepository::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testSearchMatchesTheTypeAndOrdersNewestFirst(): void
    {
        $meetings = $this->repository->searchMeeting('bv');

        self::assertNotEmpty($meetings);

        $previous = null;
        foreach ($meetings as $meeting) {
            self::assertSame(
                MeetingTypes::BV,
                $meeting->type,
            );

            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $meeting->date,
                );
            }

            $previous = $meeting->date;
        }
    }

    public function testSearchIgnoresSpacesInThePrompt(): void
    {
        self::assertEquals(
            $this->repository->searchMeeting('bv1800'),
            $this->repository->searchMeeting('bv 1800'),
        );
    }

    /**
     * The second half of the predicate matches a bare number, so a number typed without its type still resolves.
     */
    public function testSearchMatchesANumberWithoutItsType(): void
    {
        $numbers = array_map(
            static fn (Meeting $meeting): int => $meeting->getNumber(),
            $this->repository->searchMeeting('1800'),
        );

        self::assertContains(
            1800,
            $numbers,
        );
    }

    public function testSearchForSomethingUnknownFindsNothing(): void
    {
        self::assertSame(
            [],
            $this->repository->searchMeeting('zzz'),
        );
    }

    public function testPaginateForOverviewPutsVirtualMeetingsAfterRealOnesOfTheSameDate(): void
    {
        $real = $this->build->meeting(
            MeetingTypes::ALV,
            '2035-01-01',
        );
        $virtual = $this->build->meeting(
            MeetingTypes::VIRT,
            '2035-01-01',
        );

        $page = iterator_to_array(
            $this->repository->paginateForOverview(
                1,
                1000,
            ),
            false,
        );

        $realIndex = null;
        $virtualIndex = null;
        foreach ($page as $index => $meeting) {
            if ($meeting === $real) {
                $realIndex = $index;
            }

            if ($meeting !== $virtual) {
                continue;
            }

            $virtualIndex = $index;
        }

        self::assertNotNull($realIndex);
        self::assertNotNull($virtualIndex);
        self::assertSame(
            0,
            $realIndex,
            'The newest meeting is the one built for this test.',
        );
        self::assertLessThan(
            $virtualIndex,
            $realIndex,
        );
    }

    public function testPaginateForOverviewClampsToThePageAndFiltersTheType(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateForOverview(
                1,
                3,
                MeetingTypes::ALV,
            ),
            false,
        );

        self::assertCount(
            3,
            $page,
        );
        foreach ($page as $meeting) {
            self::assertSame(
                MeetingTypes::ALV,
                $meeting->type,
            );
        }
    }

    public function testCountsByTypeCountsEveryMeetingAndKeepsUnusedTypes(): void
    {
        $counts = $this->repository->countsByType();

        $expected = [];
        foreach (MeetingTypes::cases() as $case) {
            $expected[$case->value] = 0;
        }

        foreach ($this->entityManager->getRepository(Meeting::class)->findAll() as $meeting) {
            $expected[$meeting->type->value]++;
        }

        self::assertSame(
            $expected,
            $counts,
        );
    }

    public function testDecisionCountsForAnEmptyPageIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->repository->decisionCountsFor([]),
        );
    }

    public function testDecisionCountsAreKeyedByTypeAndNumber(): void
    {
        $meeting = $this->build->meeting();
        $this->build->decideFreely(
            $meeting,
            'Een',
        );
        $this->build->decideFreely(
            $meeting,
            'Twee',
        );
        $empty = $this->build->meeting(
            MeetingTypes::ALV,
            '2036-02-02',
        );

        $counts = $this->repository->decisionCountsFor(
            [
                $meeting,
                $empty,
            ],
        );

        self::assertSame(
            2,
            $counts[$meeting->type->value . '-' . $meeting->getNumber()],
        );
        self::assertSame(
            0,
            $counts[$empty->type->value . '-' . $empty->getNumber()],
        );
    }

    public function testFindAllWithDecisionCountPairsEachMeetingWithItsDecisions(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2037-03-03',
        );
        $this->build->decideFreely(
            $meeting,
            'Een',
        );

        $descending = $this->repository->findAllWithDecisionCount();
        self::assertSame(
            $meeting,
            $descending[0][0],
        );
        self::assertSame(
            1,
            $descending[0][1],
        );

        $ascending = $this->repository->findAllWithDecisionCount(true);
        self::assertSame(
            $meeting,
            $ascending[count($ascending) - 1][0],
        );
    }

    public function testFindLatestOfTypeReturnsTheHighestNumber(): void
    {
        $latest = $this->repository->findLatestOfType(MeetingTypes::VV);

        self::assertNotNull($latest);
        self::assertSame(
            MeetingTypes::VV,
            $latest->type,
        );

        $max = (int) $this->entityManager->createQueryBuilder()
            ->select('MAX(m.number)')
            ->from(
                Meeting::class,
                'm',
            )
            ->where('m.type = :type')
            ->setParameter(
                'type',
                MeetingTypes::VV,
            )
            ->getQuery()
            ->getSingleScalarResult();

        self::assertSame(
            $max,
            $latest->getNumber(),
        );
    }

    public function testFindNumbersOfTypeReturnsTheRangeInAscendingOrder(): void
    {
        $numbers = $this->repository->findNumbersOfType(
            MeetingTypes::BV,
            1800,
            1802,
        );

        self::assertSame(
            [
                1800,
                1801,
                1802,
            ],
            $numbers,
        );
    }

    public function testFindLastReturnsTheMeetingWithTheLatestDate(): void
    {
        $last = $this->repository->findLast();

        self::assertNotNull($last);

        $max = $this->entityManager->createQueryBuilder()
            ->select('MAX(m.date)')
            ->from(
                Meeting::class,
                'm',
            )
            ->getQuery()
            ->getSingleScalarResult();

        self::assertIsString($max);
        self::assertSame(
            new DateTimeImmutable($max)->format('Y-m-d'),
            $last->date->format('Y-m-d'),
        );
    }

    public function testFindMeetingOrdersItsDecisions(): void
    {
        $meeting = $this->repository->findMeeting(
            MeetingTypes::BV,
            4,
        );

        self::assertNotNull($meeting);
        self::assertSame(
            4,
            $meeting->getNumber(),
        );

        $decisions = iterator_to_array(
            $meeting->getDecisions(),
            false,
        );
        self::assertNotEmpty($decisions);

        $previousPoint = 0;
        foreach ($decisions as $decision) {
            self::assertGreaterThanOrEqual(
                $previousPoint,
                $decision->point,
            );
            $previousPoint = $decision->point;
        }
    }

    public function testFindMeetingForAnUnknownNumberIsNull(): void
    {
        self::assertNull($this->repository->findMeeting(
            MeetingTypes::BV,
            999999,
        ));
    }

    public function testFindDecisionLoadsTheSubdecisions(): void
    {
        $decision = $this->repository->findDecision(
            MeetingTypes::BV,
            4,
            4,
            1,
        );

        self::assertNotNull($decision);
        self::assertSame(
            4,
            $decision->point,
        );
        self::assertNotEmpty(iterator_to_array(
            $decision->getSubdecisions(),
            false,
        ));
    }

    public function testFindDecisionForAnUnknownAddressIsNull(): void
    {
        self::assertNull($this->repository->findDecision(
            MeetingTypes::BV,
            4,
            999,
            1,
        ));
    }

    public function testFindDecisionsByMeetingsOnlyReturnsThoseMeetings(): void
    {
        $decisions = $this->repository->findDecisionsByMeetings([
            [
                'type' => MeetingTypes::BV->value,
                'number' => 4,
            ],
            [
                'type' => MeetingTypes::BV->value,
                'number' => 3,
            ],
        ]);

        self::assertNotEmpty($decisions);
        foreach ($decisions as $decision) {
            self::assertSame(
                MeetingTypes::BV,
                $decision->getMeetingType(),
            );
            self::assertContains(
                $decision->getMeetingNumber(),
                [
                    3,
                    4,
                ],
            );
        }
    }

    public function testFindReferencingSubDecisionsFindsWhatPointsAtTheInstallation(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan($meeting);
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );
        $discharge = $this->build->discharge(
            $meeting,
            $installation,
        );

        self::assertContains(
            $discharge,
            $this->repository->findReferencingSubDecisions(
                Discharge::class,
                'installation',
                $installation,
            ),
        );
    }

    public function testFindReferencingSubDecisionsWithoutReferentsIsEmpty(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan($meeting);
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );

        self::assertSame(
            [],
            $this->repository->findReferencingSubDecisions(
                Discharge::class,
                'installation',
                $installation,
            ),
        );
    }

    public function testDeleteDecisionRemovesItAndItsSubdecisionsFromTheLedger(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Te verwijderen',
        );
        $decision = $other->decision;
        $hash = $other->getHash();

        $this->repository->deleteDecision(
            $meeting->type,
            $meeting->getNumber(),
            $decision->point,
            $decision->number,
        );

        self::assertNull($this->repository->findDecision(
            $meeting->type,
            $meeting->getNumber(),
            $decision->point,
            $decision->number,
        ));
        self::assertNull($this->entityManager->find(
            Other::class,
            [
                'meeting_type' => $meeting->type->value,
                'meeting_number' => $meeting->getNumber(),
                'decision_point' => $decision->point,
                'decision_number' => $decision->number,
                'sequence' => 1,
            ],
        ));
        self::assertNotSame(
            '',
            $hash,
        );
    }

    public function testPersistStoresTheMeetingAndManagesIt(): void
    {
        $meeting = new Meeting();
        $meeting->type = MeetingTypes::VV;
        $meeting->setNumber(7777);
        $meeting->date = new DateTimeImmutable('2038-04-04');

        self::assertFalse($this->repository->isManaged($meeting));

        $this->repository->persist($meeting);

        self::assertTrue($this->repository->isManaged($meeting));
        self::assertSame(
            $meeting,
            $this->entityManager->find(
                Meeting::class,
                [
                    'type' => MeetingTypes::VV->value,
                    'number' => 7777,
                ],
            ),
        );
    }

    public function testFindCurrentBoardExcludesADischargedInstallationUnlessTheDischargeIsAnnulled(): void
    {
        $installationMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2032-01-01',
        );
        $member = $this->build->member();
        $installation = $this->build->installBoard(
            $installationMeeting,
            $member,
        );

        self::assertContains(
            $installation->getHash(),
            $this->hashes($this->repository->findCurrentBoard()),
        );

        $dischargeMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2033-01-01',
        );
        $discharge = new BoardDischarge();
        $discharge->installation = $installation;
        $discharge->sequence = 1;
        $discharge->setDecision($this->build->decision($dischargeMeeting));
        $this->entityManager->persist($discharge);
        $this->entityManager->flush();

        self::assertNotContains(
            $installation->getHash(),
            $this->hashes($this->repository->findCurrentBoard()),
        );

        $annulmentMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2034-01-01',
        );
        $this->build->annul(
            $annulmentMeeting,
            $discharge->decision,
        );

        self::assertContains(
            $installation->getHash(),
            $this->hashes($this->repository->findCurrentBoard()),
        );
    }

    public function testFindCurrentBoardNotYetReleasedDropsAReleasedInstallation(): void
    {
        $installationMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2039-01-01',
        );
        $member = $this->build->member();
        $installation = $this->build->installBoard(
            $installationMeeting,
            $member,
        );

        self::assertContains(
            $installation->getHash(),
            $this->hashes($this->repository->findCurrentBoardNotYetReleased()),
        );

        $releaseMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2040-01-01',
        );
        $release = $this->build->releaseBoard(
            $releaseMeeting,
            $installation,
        );

        self::assertNotContains(
            $installation->getHash(),
            $this->hashes($this->repository->findCurrentBoardNotYetReleased()),
        );

        $annulmentMeeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2041-01-01',
        );
        $this->build->annul(
            $annulmentMeeting,
            $release->decision,
        );

        self::assertContains(
            $installation->getHash(),
            $this->hashes($this->repository->findCurrentBoardNotYetReleased()),
        );
    }

    public function testFindCurrentKeysOnlyKeepsKeysThatAreCurrentAndNotWithdrawn(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $current = $this->build->grantKey(
            $meeting,
            $member,
        );
        $expired = $this->build->grantKey(
            $meeting,
            $member,
            '-1 month',
        );
        $withdrawn = $this->build->grantKey(
            $meeting,
            $member,
        );
        $this->build->withdrawKey(
            $meeting,
            $withdrawn,
        );

        $hashes = $this->hashes($this->repository->findCurrentKeys());

        self::assertContains(
            $current->getHash(),
            $hashes,
        );
        self::assertNotContains(
            $expired->getHash(),
            $hashes,
        );
        self::assertNotContains(
            $withdrawn->getHash(),
            $hashes,
        );
    }

    /**
     * @param array<array-key, SubDecision> $subDecisions
     *
     * @return list<string>
     */
    private function hashes(array $subDecisions): array
    {
        $hashes = [];
        foreach ($subDecisions as $subDecision) {
            $hashes[] = $subDecision->getHash();
        }

        return $hashes;
    }
}
