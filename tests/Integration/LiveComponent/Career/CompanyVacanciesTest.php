<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Career;

use App\Entity\Career\Company;
use App\Repository\Career\CompanyRepository;
use App\Repository\Career\VacancyRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Career\CompanyVacancies;

use function array_map;
use function count;

/**
 * The company page shows one vacancy at a time, in an order drawn once per visit; paging wraps, so neither arrow is
 * ever disabled.
 */
final class CompanyVacanciesTest extends DatabaseTestCase
{
    public function testTheCompanyPageWalksThroughItsOwnOpenVacancies(): void
    {
        $slug = $this->companyWithVacancies();
        $component = $this->vacanciesFor($slug);
        $component->seed = 424242;

        $vacancies = $component->vacancies();

        self::assertNotEmpty($vacancies);
        self::assertSame(
            count($vacancies),
            $component->total(),
        );
        self::assertContains(
            $component->currentVacancy(),
            $vacancies,
        );

        foreach ($vacancies as $vacancy) {
            self::assertSame(
                $slug,
                $vacancy->getCompany()->slugName,
                'Only this company\'s vacancies are walked through.',
            );
        }
    }

    public function testTheOrderIsTheOneDrawnForThisVisit(): void
    {
        $slug = $this->companyWithVacancies();
        $first = $this->vacanciesFor($slug);
        $first->seed = 99;
        $second = $this->vacanciesFor($slug);
        $second->seed = 99;

        self::assertSame(
            array_map(
                static fn ($vacancy): ?int => $vacancy->id,
                $first->vacancies(),
            ),
            array_map(
                static fn ($vacancy): ?int => $vacancy->id,
                $second->vacancies(),
            ),
        );
    }

    public function testPagingWrapsAroundTheVacancies(): void
    {
        $component = $this->vacanciesFor($this->companyWithVacancies());
        $count = count($component->vacancies());

        $component->show(-1);
        self::assertSame(
            $count - 1,
            $component->index,
        );

        $component->show($count);
        self::assertSame(
            0,
            $component->index,
        );
        self::assertSame(
            $component->vacancies()[0],
            $component->currentVacancy(),
        );
    }

    public function testACompanyWithoutOpenVacanciesShowsNone(): void
    {
        $component = $this->vacanciesFor('no-such-company');
        $component->seed = 1;

        self::assertSame(
            [],
            $component->vacancies(),
        );
        self::assertSame(
            0,
            $component->total(),
        );
        self::assertNull($component->currentVacancy());
    }

    private function vacanciesFor(string $slug): CompanyVacancies
    {
        $component = self::getContainer()->get(CompanyVacancies::class);
        $component->companySlug = $slug;

        return $component;
    }

    private function companyWithVacancies(): string
    {
        $vacancies = self::getContainer()->get(VacancyRepository::class);

        foreach (self::getContainer()->get(CompanyRepository::class)->findAllPublic() as $company) {
            self::assertInstanceOf(
                Company::class,
                $company,
            );
            if ([] !== $vacancies->findActiveIdsForOverview(companySlugName: $company->slugName)) {
                return $company->slugName;
            }
        }

        self::fail('The seed has a public company with an open vacancy.');
    }
}
