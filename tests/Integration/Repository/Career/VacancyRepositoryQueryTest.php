<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Career;

use App\Entity\Career\Company;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyLabel;
use App\Repository\Career\VacancyRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;

use function array_map;
use function array_sum;
use function count;
use function rsort;
use function sprintf;

final class VacancyRepositoryQueryTest extends DatabaseTestCase
{
    private VacancyRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(VacancyRepository::class);
    }

    public function testASlugIsOnlyUniqueWithinItsCompanyAndCategory(): void
    {
        $nexunt = $this->company('nexunt');
        $orbit = $this->company('orbit-analytics');
        $backend = $this->vacancy('backend-engineer');

        self::assertFalse($this->repository->isSlugNameUnique(
            $nexunt,
            'backend-engineer',
            VacancyCategories::Jobs,
        ));
        self::assertTrue($this->repository->isSlugNameUnique(
            $nexunt,
            'brand-new-slug',
            VacancyCategories::Jobs,
        ));
        // The category is part of the identity: the same slug may exist under another one.
        self::assertTrue($this->repository->isSlugNameUnique(
            $nexunt,
            'backend-engineer',
            VacancyCategories::Internships,
        ));
        // And so is the company.
        self::assertTrue($this->repository->isSlugNameUnique(
            $orbit,
            'backend-engineer',
            VacancyCategories::Jobs,
        ));
        // The vacancy being edited does not collide with itself.
        self::assertTrue($this->repository->isSlugNameUnique(
            $nexunt,
            'backend-engineer',
            VacancyCategories::Jobs,
            $backend,
        ));
    }

    public function testFindVacancyNarrowsOnCategorySlugAndCompany(): void
    {
        $jobs = $this->repository->findVacancy(VacancyCategories::Jobs);

        self::assertNotEmpty($jobs);
        foreach ($jobs as $vacancy) {
            self::assertSame(
                VacancyCategories::Jobs,
                $vacancy->getCategory(),
            );
        }

        $bySlug = $this->repository->findVacancy(
            vacancySlugName: 'backend-engineer',
        );
        self::assertCount(
            1,
            $bySlug,
        );
        self::assertSame(
            'nexunt',
            $bySlug[0]->getCompany()->slugName,
        );

        self::assertSame(
            [],
            $this->repository->findVacancy(
                vacancySlugName: 'backend-engineer',
                companySlugName: 'orbit-analytics',
            ),
        );
    }

    public function testFindVacancyFiltersOnAMatchingLabel(): void
    {
        $label = $this->vacancy('backend-engineer')->getLabels()->first();
        self::assertInstanceOf(
            VacancyLabel::class,
            $label,
        );

        $vacancies = $this->repository->findVacancy(vacancyLabelId: $label->id);

        self::assertNotEmpty($vacancies);
        foreach ($vacancies as $vacancy) {
            $ids = array_map(
                static fn (VacancyLabel $assigned): ?int => $assigned->id,
                $vacancy->getLabels()->toArray(),
            );
            self::assertContains(
                $label->id,
                $ids,
            );
        }
    }

    public function testFindLatestForOverviewRespectsTheLimit(): void
    {
        $latest = $this->repository->findLatestForOverview(3);

        self::assertCount(
            3,
            $latest,
        );

        $ids = array_map(
            static fn (Vacancy $vacancy): int => (int) $vacancy->id,
            $latest,
        );
        $sorted = $ids;
        rsort($sorted);
        self::assertSame(
            $sorted,
            $ids,
        );
    }

    public function testCountActiveByCategoryAgreesWithTheOverview(): void
    {
        $counts = $this->repository->countActiveByCategory();

        self::assertNotEmpty($counts);

        foreach ($counts as $category => $total) {
            self::assertSame(
                count($this->repository->findActiveIdsForOverview(VacancyCategories::from($category))),
                $total,
            );
        }

        self::assertSame(
            count($this->repository->findActiveIdsForOverview()),
            array_sum($counts),
        );
    }

    public function testFindHighlightedOnlyShowsActiveHighlightedVacancies(): void
    {
        $ids = $this->repository->findHighlightedIds();
        $vacancies = $this->repository->findHighlighted();

        self::assertNotEmpty($ids);
        self::assertEqualsCanonicalizing(
            $ids,
            array_map(
                static fn (Vacancy $vacancy): int => (int) $vacancy->id,
                $vacancies,
            ),
        );

        $slugs = array_map(
            static fn (Vacancy $vacancy): string => $vacancy->slugName,
            $vacancies,
        );
        self::assertContains(
            'backend-engineer',
            $slugs,
        );
        self::assertNotContains(
            'platform-internship',
            $slugs,
        );
    }

    public function testFindHighlightableForCompanyLeavesOutClosedVacancies(): void
    {
        $vacancies = $this->repository->findHighlightableForCompany($this->company('nexunt'));

        $slugs = array_map(
            static fn (Vacancy $vacancy): string => $vacancy->slugName,
            $vacancies,
        );

        self::assertContains(
            'backend-engineer',
            $slugs,
        );
        self::assertNotContains(
            'platform-internship',
            $slugs,
        );
        foreach ($vacancies as $vacancy) {
            self::assertSame(
                'nexunt',
                $vacancy->getCompany()->slugName,
            );
        }
    }

    public function testFindByPackageAndCompanyRequiresAllThreePartsToMatch(): void
    {
        $vacancy = $this->vacancy('backend-engineer');
        $packageId = (int) $vacancy->package->id;

        $found = $this->repository->findByPackageAndCompany(
            'nexunt',
            $packageId,
            (int) $vacancy->id,
        );

        self::assertNotNull($found);
        self::assertSame(
            $vacancy->id,
            $found->id,
        );

        self::assertNull($this->repository->findByPackageAndCompany(
            'orbit-analytics',
            $packageId,
            (int) $vacancy->id,
        ));
        self::assertNull($this->repository->findByPackageAndCompany(
            'nexunt',
            $packageId + 100000,
            (int) $vacancy->id,
        ));
    }

    public function testNextActiveBoundaryIsTheEarliestFutureDateOrNullWhenNoneIsLeft(): void
    {
        $boundary = $this->repository->nextActiveBoundaryAfter(new DateTimeImmutable('2000-01-01'));

        self::assertNotNull($boundary);
        self::assertSame(
            '2020-01-01 00:00:00',
            $boundary->format('Y-m-d H:i:s'),
        );

        self::assertNull($this->repository->nextActiveBoundaryAfter(new DateTimeImmutable('2200-01-01')));
    }

    private function company(string $slug): Company
    {
        $company = $this->entityManager->getRepository(Company::class)->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Company::class,
            $company,
            sprintf(
                'The seed is expected to contain company %s.',
                $slug,
            ),
        );

        return $company;
    }

    private function vacancy(string $slug): Vacancy
    {
        $vacancy = $this->entityManager->getRepository(Vacancy::class)->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Vacancy::class,
            $vacancy,
        );

        return $vacancy;
    }
}
