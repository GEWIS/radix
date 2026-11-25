<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganLabel;
use App\Repository\Decision\OrganRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\NoResultException;
use Override;

use function array_map;
use function count;
use function iterator_to_array;

final class OrganRepositoryQueryTest extends DatabaseTestCase
{
    private OrganRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(OrganRepository::class);
    }

    public function testPaginateBodiesFiltersTypeAndAbrogation(): void
    {
        $page = iterator_to_array(
            $this->repository->paginateBodies(
                OrganTypes::Committee,
                false,
                1,
                2,
            ),
            false,
        );

        self::assertCount(
            2,
            $page,
        );

        $previous = '';
        foreach ($page as $organ) {
            self::assertSame(
                OrganTypes::Committee,
                $organ->type,
            );
            self::assertTrue(
                null === $organ->abrogationDate || $organ->abrogationDate > new DateTimeImmutable(),
            );
            self::assertGreaterThanOrEqual(
                $previous,
                $organ->abbr,
            );
            $previous = $organ->abbr;
        }

        $includingAbrogated = iterator_to_array(
            $this->repository->paginateBodies(
                OrganTypes::Committee,
                true,
                1,
                5000,
            ),
            false,
        );

        $abrogated = 0;
        foreach ($includingAbrogated as $organ) {
            if (null === $organ->abrogationDate) {
                continue;
            }

            $abrogated++;
        }

        self::assertGreaterThan(
            0,
            $abrogated,
        );
    }

    public function testFindOverviewIdsSplitsAbrogatedFromStandingBodies(): void
    {
        $standing = $this->repository->findOverviewIds(
            OrganTypes::Committee,
            false,
        );
        $abrogated = $this->repository->findOverviewIds(
            OrganTypes::Committee,
            true,
        );

        self::assertNotEmpty($standing);
        self::assertNotEmpty($abrogated);

        foreach ($this->repository->findOverviewByIds($standing) as $organ) {
            self::assertSame(
                OrganTypes::Committee,
                $organ->type,
            );
            self::assertTrue(
                null === $organ->abrogationDate || $organ->abrogationDate > new DateTimeImmutable(),
            );
        }

        foreach ($this->repository->findOverviewByIds($abrogated) as $organ) {
            self::assertLessThanOrEqual(
                new DateTimeImmutable(),
                $organ->abrogationDate,
            );
        }
    }

    public function testFindOverviewIdsSearchesNamesAndAbbreviations(): void
    {
        $ids = $this->repository->findOverviewIds(
            OrganTypes::Committee,
            false,
            'bac',
        );

        self::assertNotEmpty($ids);
        $organs = $this->repository->findOverviewByIds($ids);
        self::assertSame(
            'BAC',
            $organs[0]->abbr,
        );

        self::assertSame(
            [],
            $this->repository->findOverviewIds(
                OrganTypes::Committee,
                false,
                'zzzznotfound',
            ),
        );
    }

    public function testFindOverviewIdsFiltersOnTheLiveRevisionsLabels(): void
    {
        $bac = $this->organ('BAC');
        $labels = $bac->organInformation?->getLiveRevision()?->getLabels();
        self::assertNotNull($labels);
        self::assertNotEmpty($labels);

        $label = $labels->first();
        self::assertInstanceOf(
            OrganLabel::class,
            $label,
        );

        $ids = $this->repository->findOverviewIds(
            OrganTypes::Committee,
            false,
            '',
            [(int) $label->id],
        );

        self::assertContains(
            (int) $bac->id,
            $ids,
        );
        foreach ($this->repository->findOverviewByIds($ids) as $organ) {
            $organLabels = $organ->organInformation?->getLiveRevision()?->getLabels();
            self::assertNotNull($organLabels);
            $assigned = array_map(
                static fn (OrganLabel $assignedLabel): int => (int) $assignedLabel->id,
                $organLabels->toArray(),
            );
            self::assertContains(
                (int) $label->id,
                $assigned,
            );
        }
    }

    public function testFindOverviewByIdsKeepsTheRequestedOrderAndSkipsUnknownIds(): void
    {
        $ids = $this->repository->findOverviewIds(
            OrganTypes::Committee,
            false,
        );
        self::assertGreaterThanOrEqual(
            2,
            count($ids),
        );

        $requested = [
            $ids[1],
            $ids[0],
            999999,
        ];
        $organs = $this->repository->findOverviewByIds($requested);

        self::assertCount(
            2,
            $organs,
        );
        self::assertSame(
            (int) $ids[1],
            (int) $organs[0]->id,
        );
        self::assertSame(
            (int) $ids[0],
            (int) $organs[1]->id,
        );
        self::assertSame(
            [],
            $this->repository->findOverviewByIds([]),
        );
    }

    public function testFindActiveByIdLeavesOutAbrogatedBodies(): void
    {
        $bac = $this->organ('BAC');
        self::assertSame(
            $bac->id,
            $this->repository->findActiveById((int) $bac->id)?->id,
        );

        $abrogated = $this->organ('SUD');
        self::assertNull($this->repository->findActiveById((int) $abrogated->id));
    }

    public function testFindOrganLoadsTheBodysMembers(): void
    {
        $bac = $this->organ('BAC');

        $organ = $this->repository->findOrgan((int) $bac->id);

        self::assertNotNull($organ);
        self::assertNotEmpty(iterator_to_array($organ->getMembers()));
        self::assertNull($this->repository->findOrgan(999999));
    }

    public function testFindByAbbrWithAndWithoutLatest(): void
    {
        $latest = $this->repository->findByAbbr(
            'BAC',
            true,
            OrganTypes::Committee,
        );
        self::assertNotNull($latest);
        self::assertSame(
            'BAC',
            $latest->abbr,
        );

        self::assertSame(
            $latest->id,
            $this->repository->findByAbbr(
                'BAC',
                false,
                OrganTypes::Committee,
            )?->id,
        );

        self::assertNull($this->repository->findByAbbr(
            'ZZZZ',
            true,
            OrganTypes::Committee,
        ));
    }

    public function testFindByAbbrWithoutLatestThrowsWhenNothingMatches(): void
    {
        $this->expectException(NoResultException::class);

        $this->repository->findByAbbr(
            'ZZZZ',
            false,
            OrganTypes::Committee,
        );
    }

    public function testWarmPageAssociationsLoadsTheBodysPage(): void
    {
        $bac = $this->organ('BAC');

        $this->repository->warmPageAssociations([]);
        $this->repository->warmPageAssociations([$bac]);

        self::assertNotNull($bac->organInformation);
        self::assertNotNull($bac->organInformation->getLiveRevision());
        self::assertSame(
            'BAC',
            $bac->organInformation
                ->getLiveRevision()
                ->getOrgan()
                ->abbr,
        );
    }

    private function organ(string $abbr): Organ
    {
        $organs = $this->entityManager->getRepository(Organ::class)->findBy(['abbr' => $abbr]);
        self::assertNotEmpty($organs);

        return $organs[0];
    }
}
