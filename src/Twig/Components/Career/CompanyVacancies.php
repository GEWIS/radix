<?php

declare(strict_types=1);

namespace App\Twig\Components\Career;

use App\Entity\Career\Vacancy;
use App\Repository\Career\VacancyRepository;
use App\Twig\Components\Concerns\PanelPagerTrait;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function count;
use function mt_getrandmax;
use function random_int;

/**
 * One vacancy at a time on the company page, paged with panel-pager.
 */
#[AsLiveComponent(
    name: 'Career:Company:Vacancies',
    template: 'components/Career/CompanyVacancies.html.twig',
)]
class CompanyVacancies
{
    use DefaultActionTrait;
    use PanelPagerTrait;

    #[LiveProp]
    public string $companySlug;

    #[LiveProp]
    public int $seed = 0;

    public function __construct(
        private readonly VacancyRepository $vacancyRepository,
    ) {
    }

    public function mount(): void
    {
        $this->seed = random_int(
            0,
            mt_getrandmax(),
        );
    }

    /**
     * All active vacancies for this company, across all categories.
     *
     * @return Vacancy[]
     */
    public function vacancies(): array
    {
        $ids = $this->vacancyRepository->findActiveIdsForOverview(
            companySlugName: $this->companySlug,
        );

        $ids = new Randomizer(new Mt19937($this->seed))->shuffleArray($ids);

        return $this->vacancyRepository->findForOverviewByIds($ids);
    }

    protected function pagedItemCount(): int
    {
        return count($this->vacancies());
    }

    public function currentVacancy(): ?Vacancy
    {
        $vacancies = $this->vacancies();

        if ([] === $vacancies) {
            return null;
        }

        return $vacancies[$this->index % count($vacancies)];
    }
}
