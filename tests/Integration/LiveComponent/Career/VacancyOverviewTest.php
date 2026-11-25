<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Career;

use App\Entity\Application\Enums\Languages;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\Vacancy;
use App\Repository\Career\CompanyRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Application\AbstractInfiniteScrollOverview;
use App\Twig\Components\Career\VacancyOverview;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function array_slice;
use function count;
use function in_array;

/**
 * The public vacancies overview: a random order fixed per visit, and the category, company, label and search
 * filters. The order is kept as a seed, so a filtered or extended list does not reshuffle.
 */
final class VacancyOverviewTest extends DatabaseTestCase
{
    public function testTheOverviewListsTheActiveVacanciesInTheVisitorsOrder(): void
    {
        $overview = $this->overview();
        $overview->seed = 424242;

        $listed = $this->ids($overview->getVacancies());

        self::assertNotEmpty($listed);
        self::assertSame(
            $listed,
            $this->ids($this->overview(424242)->getVacancies()),
            'The same seed draws the same order, so the list does not reshuffle between renders.',
        );
        self::assertLessThanOrEqual(
            $overview->getTotalCount(),
            count($listed),
        );
        self::assertSame(
            $overview->getTotalCount() > count($listed),
            $overview->hasMore(),
        );
    }

    public function testAskingForMoreAddsToTheEndOfTheSameOrder(): void
    {
        $narrow = $this->overview(424242);
        $first = $this->ids($narrow->getVacancies());
        self::assertNotEmpty($first);

        $wider = $this->overview(424242);
        $wider->limit = AbstractInfiniteScrollOverview::STEP * 2;

        $extended = $this->ids($wider->getVacancies());

        self::assertGreaterThanOrEqual(
            count($first),
            count($extended),
        );
        self::assertSame(
            $first,
            array_slice(
                $extended,
                0,
                count($first),
            ),
            'More is added to the end of the order, not drawn anew.',
        );

        $wider->loadMore();
        self::assertSame(
            AbstractInfiniteScrollOverview::STEP * 3,
            $wider->limit,
        );
    }

    public function testACategoryFilterReturnsThatCategoryOnly(): void
    {
        $category = VacancyCategories::cases()[0];
        $overview = $this->overview();
        $overview->category = $category->value;

        foreach ($overview->getVacancies() as $vacancy) {
            self::assertSame(
                $category,
                $vacancy->getLiveRevision()?->category,
            );
        }

        self::assertLessThanOrEqual(
            $this->overview()->getTotalCount(),
            $overview->getTotalCount(),
        );
    }

    public function testACategoryThatDoesNotExistFiltersNothing(): void
    {
        $overview = $this->overview();
        $overview->category = 'nonsense';

        self::assertSame(
            $this->overview()->getTotalCount(),
            $overview->getTotalCount(),
        );
    }

    public function testACompanyFilterReturnsThatCompanyOnly(): void
    {
        $companies = self::getContainer()->get(CompanyRepository::class)->findAllPublic();
        self::assertNotEmpty($companies);
        $company = $companies[0];

        $overview = $this->overview();
        $overview->companyFilter = $company->slugName;

        foreach ($overview->getVacancies() as $vacancy) {
            self::assertSame(
                $company->slugName,
                $vacancy->getCompany()->slugName,
            );
        }

        // An empty company filter is no filter at all, which is what the company page passes when it names none.
        $all = $this->overview();
        $all->companyFilter = '';
        self::assertSame(
            $this->overview()->getTotalCount(),
            $all->getTotalCount(),
        );
    }

    public function testASearchNarrowsByName(): void
    {
        $any = $this->overview()->getVacancies()[0] ?? null;
        self::assertInstanceOf(
            Vacancy::class,
            $any,
        );
        $name = $any->getName()->getText(Languages::English);
        self::assertNotNull($name);

        $overview = $this->overview();
        $overview->search = $name;

        self::assertContains(
            $any->id,
            $this->ids($overview->getVacancies()),
        );
    }

    public function testTheHighlightedStripStaysWithinTheFilteredResults(): void
    {
        $overview = $this->overview();

        $matching = $this->ids($overview->getVacancies());
        $highlighted = $this->ids($overview->getHighlightedVacancies());

        foreach ($highlighted as $id) {
            self::assertTrue(
                in_array(
                    $id,
                    $matching,
                    true,
                ),
                'A highlighted vacancy that the filters excluded does not lead the strip.',
            );
        }

        foreach ($overview->getHighlightedIds() as $id) {
            self::assertContains(
                $id,
                $matching,
            );
        }
    }

    public function testASharedLabelLinkPreSelectsOnlyTheLabelsThatAreOffered(): void
    {
        $offered = array_map(
            static fn ($label): int => (int) $label->id,
            $this->overview()->getLabels(),
        );

        if ([] === $offered) {
            self::assertSame(
                [],
                $this->overview()->getLabels(),
            );

            return;
        }

        $request = new Request(['labels' => (string) $offered[0] . ',999999']);
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get('request_stack')->push($request);

        $overview = $this->overview();
        $overview->mount();

        self::assertSame(
            [$offered[0]],
            $overview->labelFilters,
        );
    }

    public function testTheFilterPanelOffersTheCategoriesAndThePublicCompanies(): void
    {
        $overview = $this->overview();

        self::assertSame(
            VacancyCategories::cases(),
            $overview->getCategories(),
        );
        self::assertSame(
            self::getContainer()->get(CompanyRepository::class)->findAllPublic(),
            $overview->getCompanies(),
        );
    }

    private function overview(?int $seed = null): VacancyOverview
    {
        $overview = self::getContainer()->get(VacancyOverview::class);
        if (null !== $seed) {
            $overview->seed = $seed;
        }

        return $overview;
    }

    /**
     * @param Vacancy[] $vacancies
     *
     * @return int[]
     */
    private function ids(array $vacancies): array
    {
        return array_map(
            static fn (Vacancy $vacancy): int => (int) $vacancy->id,
            $vacancies,
        );
    }
}
