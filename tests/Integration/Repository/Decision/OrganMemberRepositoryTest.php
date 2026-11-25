<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganMember;
use App\Repository\Decision\OrganMemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function count;
use function iterator_to_array;

final class OrganMemberRepositoryTest extends DatabaseTestCase
{
    private OrganMemberRepository $repository;

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
        $this->repository = self::getContainer()->get(OrganMemberRepository::class);
        $this->build = new LedgerBuilder($ledger);
    }

    public function testPaginateByBodyLeavesOutDischargedMembersUnlessAsked(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan(
            $meeting,
            'OMB',
            'Orgaanleden testcommissie',
        );
        $current = $this->build->member();
        $this->build->install(
            $meeting,
            $organ,
            $current,
            InstallationFunctions::Member,
        );
        $former = $this->build->member();
        $formerInstallation = $this->build->install(
            $meeting,
            $organ,
            $former,
            InstallationFunctions::Member,
        );
        $this->build->discharge(
            $this->build->meeting(
                MeetingTypes::ALV,
                '2026-09-01',
            ),
            $formerInstallation,
        );

        $organId = $this->organId('OMB');

        $currentLidnrs = $this->memberNumbers($this->repository->paginateByBody(
            $organId,
            false,
            false,
            1,
            100,
        ));
        $allLidnrs = $this->memberNumbers($this->repository->paginateByBody(
            $organId,
            true,
            false,
            1,
            100,
        ));

        self::assertContains(
            $current->lidnr,
            $currentLidnrs,
        );
        self::assertNotContains(
            $former->lidnr,
            $currentLidnrs,
        );
        self::assertContains(
            $former->lidnr,
            $allLidnrs,
        );
    }

    public function testPaginateByBodyLeavesOutDeletedMembersUnlessAsked(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan(
            $meeting,
            'OMD',
            'Verwijderde leden testcommissie',
        );
        $member = $this->build->member();
        $this->build->install(
            $meeting,
            $organ,
            $member,
            InstallationFunctions::Member,
        );
        $member->deleted = true;
        $this->ledger->flush();

        $organId = $this->organId('OMD');

        self::assertNotContains(
            $member->lidnr,
            $this->memberNumbers($this->repository->paginateByBody(
                $organId,
                true,
                false,
                1,
                100,
            )),
        );
        self::assertContains(
            $member->lidnr,
            $this->memberNumbers($this->repository->paginateByBody(
                $organId,
                true,
                true,
                1,
                100,
            )),
        );
    }

    public function testPaginateByBodyOrdersByMemberNumberAndClampsThePage(): void
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy(['abbr' => 'BAC']);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        $page = iterator_to_array(
            $this->repository->paginateByBody(
                (int) $organ->id,
                true,
                true,
                1,
                2,
            ),
            false,
        );

        self::assertLessThanOrEqual(
            2,
            count($page),
        );

        $previous = 0;
        foreach ($page as $organMember) {
            self::assertLessThanOrEqual(
                $organMember->member->lidnr,
                $previous,
            );
            $previous = $organMember->member->lidnr;
        }
    }

    public function testPaginateByMemberIncludesHistoricalInstallationsOnlyWhenAsked(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan(
            $meeting,
            'OMM',
            'Ledenhistorie testcommissie',
        );
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $organ,
            $member,
            InstallationFunctions::Member,
        );
        $this->build->discharge(
            $this->build->meeting(
                MeetingTypes::ALV,
                '2026-09-01',
            ),
            $installation,
        );

        $current = iterator_to_array($this->repository->paginateByMember(
            $member->lidnr,
            false,
            1,
            100,
        ));
        $all = iterator_to_array($this->repository->paginateByMember(
            $member->lidnr,
            true,
            1,
            100,
        ));

        self::assertCount(
            0,
            $current,
        );
        self::assertCount(
            1,
            $all,
        );
        self::assertSame(
            'OMM',
            $all[0]->organ->abbr,
        );
        self::assertNotNull($all[0]->dischargeDate);
        self::assertLessThanOrEqual(
            new DateTimeImmutable(),
            $all[0]->dischargeDate,
        );
        self::assertInstanceOf(
            OrganMember::class,
            $all[0],
        );
    }

    public function testPaginateByMemberOrdersByBodyAbbreviation(): void
    {
        $meeting = $this->build->meeting();
        $first = $this->build->foundOrgan(
            $meeting,
            'AAA',
            'Eerste testcommissie',
        );
        $second = $this->build->foundOrgan(
            $meeting,
            'ZZZ',
            'Tweede testcommissie',
        );
        $member = $this->build->member();
        $this->build->install(
            $meeting,
            $second,
            $member,
            InstallationFunctions::Member,
        );
        $this->build->install(
            $meeting,
            $first,
            $member,
            InstallationFunctions::Member,
        );

        $page = iterator_to_array(
            $this->repository->paginateByMember(
                $member->lidnr,
                false,
                1,
                100,
            ),
            false,
        );

        self::assertCount(
            2,
            $page,
        );
        self::assertSame(
            'AAA',
            $page[0]->organ->abbr,
        );
        self::assertSame(
            'ZZZ',
            $page[1]->organ->abbr,
        );
    }

    private function organId(string $abbr): int
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy(['abbr' => $abbr]);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        return (int) $organ->id;
    }

    /**
     * @param iterable<OrganMember> $page
     *
     * @return list<int>
     */
    private function memberNumbers(iterable $page): array
    {
        $numbers = [];
        foreach ($page as $organMember) {
            $numbers[] = $organMember->member->lidnr;
        }

        return $numbers;
    }
}
