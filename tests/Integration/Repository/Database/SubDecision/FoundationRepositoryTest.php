<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Database\SubDecision;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\SubDecision;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Repository\Database\SubDecision\FoundationRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;

use function iterator_to_array;
use function mb_strtolower;
use function str_contains;

final class FoundationRepositoryTest extends DatabaseTestCase
{
    private FoundationRepository $repository;

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
        $this->repository = self::getContainer()->get(FoundationRepository::class);
        $this->build = new LedgerBuilder($this->entityManager);
    }

    public function testFindOrganLoadsTheBodysInstallations(): void
    {
        $organ = $this->repository->findOrgan(...$this->address($this->foundation('BAC')));

        self::assertNotNull($organ);
        self::assertSame(
            'BAC',
            $organ->abbr,
        );

        $references = iterator_to_array(
            $organ->getReferences(),
            false,
        );
        self::assertNotEmpty($references);
        foreach ($references as $reference) {
            self::assertInstanceOf(
                Installation::class,
                $reference,
            );
        }
    }

    public function testFindOrganExcludesADischargedInstallationUntilTheDischargeIsAnnulled(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ZZ1',
            'Testcommissie een',
        );
        $foundationAddress = $this->address($foundation);
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );
        $installationHash = $installation->getHash();

        // The foundation was built in this process, so its reference collection is already initialised. Detaching it
        // makes the query read the database instead.
        $this->entityManager->clear();

        self::assertContains(
            $installationHash,
            $this->referenceHashes($this->repository->findOrgan(...$foundationAddress)),
        );

        $installation = $this->repository->findInstallationDecision(...$this->address($installation));
        self::assertNotNull($installation);

        $discharge = $this->build->discharge(
            $this->build->meeting(
                MeetingTypes::ALV,
                '2042-01-01',
            ),
            $installation,
        );
        $dischargeDecision = [
            $discharge->getMeetingType(),
            $discharge->getMeetingNumber(),
            $discharge->getDecisionPoint(),
            $discharge->getDecisionNumber(),
        ];

        $this->entityManager->clear();

        self::assertNotContains(
            $installationHash,
            $this->referenceHashes($this->repository->findOrgan(...$foundationAddress)),
        );

        $decision = $this->entityManager->find(
            Decision::class,
            [
                'meeting_type' => $dischargeDecision[0],
                'meeting_number' => $dischargeDecision[1],
                'point' => $dischargeDecision[2],
                'number' => $dischargeDecision[3],
            ],
        );
        self::assertInstanceOf(
            Decision::class,
            $decision,
        );

        $this->build->annul(
            $this->build->meeting(
                MeetingTypes::ALV,
                '2043-01-01',
            ),
            $decision,
        );
        $this->entityManager->clear();

        self::assertContains(
            $installationHash,
            $this->referenceHashes($this->repository->findOrgan(...$foundationAddress)),
        );
    }

    public function testFindOrganExcludesAnAnnulledInstallation(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ZZ2',
            'Testcommissie twee',
        );
        $foundationAddress = $this->address($foundation);
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );
        $this->build->annul(
            $meeting,
            $installation->decision,
        );

        $this->entityManager->clear();

        self::assertNotContains(
            $installation->getHash(),
            $this->referenceHashes($this->repository->findOrgan(...$foundationAddress)),
        );
    }

    public function testFindSimpleReturnsTheBodyAndNothingForAWrongSequence(): void
    {
        $foundation = $this->foundation('BAC');
        $address = $this->address($foundation);

        $simple = $this->repository->findSimple(...$address);

        self::assertNotNull($simple);
        self::assertSame(
            $foundation->getHash(),
            $simple->getHash(),
        );

        self::assertNull($this->repository->findSimple(
            $address[0],
            $address[1],
            $address[2],
            $address[3],
            99,
        ));
    }

    public function testFindInstallationDecisionReturnsTheInstallationAtTheAddress(): void
    {
        $installation = $this->findAnInstallationOf('BAC');

        $found = $this->repository->findInstallationDecision(...$this->address($installation));

        self::assertNotNull($found);
        self::assertSame(
            $installation->getHash(),
            $found->getHash(),
        );
    }

    public function testOrganSearchLeavesOutAbrogatedBodiesUnlessAsked(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ZZ3',
            'Zoekbare testcommissie',
        );

        self::assertContains(
            $foundation->getHash(),
            $this->hashes($this->repository->organSearch('zz3')),
        );

        $abrogation = $this->build->abrogate(
            $meeting,
            $foundation,
        );

        self::assertNotContains(
            $foundation->getHash(),
            $this->hashes($this->repository->organSearch('zz3')),
        );
        self::assertContains(
            $foundation->getHash(),
            $this->hashes($this->repository->organSearch(
                'zz3',
                true,
            )),
        );

        $this->build->annul(
            $meeting,
            $abrogation->decision,
        );

        self::assertContains(
            $foundation->getHash(),
            $this->hashes($this->repository->organSearch('zz3')),
        );
    }

    public function testOrganSearchLeavesOutAnAnnulledFoundation(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ZZ4',
            'Geannuleerde testcommissie',
        );
        $this->build->annul(
            $meeting,
            $foundation->decision,
        );

        self::assertSame(
            [],
            $this->repository->organSearch('zz4'),
        );
    }

    public function testOrganSearchFindsBodiesByTheirName(): void
    {
        $results = $this->repository->organSearch('Bacchus');

        self::assertNotEmpty($results);
        foreach ($results as $foundation) {
            self::assertTrue(
                str_contains(
                    mb_strtolower($foundation->name),
                    'bacchus',
                ) || str_contains(
                    mb_strtolower($foundation->abbr),
                    'bacchus',
                ),
            );
        }
    }

    public function testPaginateForOverviewOrdersByAbbreviationAndClampsToThePage(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateForOverview(
                '',
                1,
                4,
            ),
            false,
        );

        self::assertCount(
            4,
            $page,
        );

        $previous = '';
        foreach ($page as $foundation) {
            self::assertGreaterThanOrEqual(
                $previous,
                $foundation->abbr,
            );
            $previous = $foundation->abbr;
        }
    }

    private function foundation(string $abbr): Foundation
    {
        $foundation = $this->entityManager->getRepository(Foundation::class)->findOneBy(['abbr' => $abbr]);
        self::assertInstanceOf(
            Foundation::class,
            $foundation,
        );

        return $foundation;
    }

    /**
     * @return array{0: MeetingTypes, 1: int, 2: int, 3: int, 4: int}
     */
    private function address(SubDecision $subDecision): array
    {
        return [
            $subDecision->getMeetingType(),
            $subDecision->getMeetingNumber(),
            $subDecision->getDecisionPoint(),
            $subDecision->getDecisionNumber(),
            $subDecision->sequence,
        ];
    }

    private function reloadMeeting(Meeting $meeting): Meeting
    {
        $reloaded = $this->entityManager->find(
            Meeting::class,
            [
                'type' => $meeting->type->value,
                'number' => $meeting->getNumber(),
            ],
        );
        self::assertInstanceOf(
            Meeting::class,
            $reloaded,
        );

        return $reloaded;
    }

    private function findAnInstallationOf(string $abbr): Installation
    {
        $foundation = $this->foundation($abbr);
        foreach (
            iterator_to_array(
                $foundation->getReferences(),
                false,
            ) as $reference
        ) {
            if ($reference instanceof Installation) {
                return $reference;
            }
        }

        self::fail('The seed is expected to install members into ' . $abbr . '.');
    }

    /**
     * @return list<string>
     */
    private function referenceHashes(?Foundation $foundation): array
    {
        if (null === $foundation) {
            return [];
        }

        $hashes = [];
        foreach ($foundation->getReferences() as $reference) {
            $hashes[] = $reference->getHash();
        }

        return $hashes;
    }

    /**
     * @param Foundation[] $foundations
     *
     * @return list<string>
     */
    private function hashes(array $foundations): array
    {
        $hashes = [];
        foreach ($foundations as $foundation) {
            $hashes[] = $foundation->getHash();
        }

        return $hashes;
    }
}
