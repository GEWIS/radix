<?php

declare(strict_types=1);

namespace App\Twig\Components\Career\Admin;

use App\Entity\Career\Company;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Career\CompanyRepository;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * The companies tab of the career overview: every company on the books, hidden and out of contract ones included,
 * searchable by name or slug and paged through.
 *
 * @extends AbstractDoctrinePaginatedOverview<Company>
 */
#[AsLiveComponent(
    name: 'Career:Admin:CompanyOverview',
    template: 'components/Career/Admin/CompanyOverview.html.twig',
)]
#[IsGranted(UserRoles::CompanyAdmin->value)]
final class CompanyOverview extends AbstractDoctrinePaginatedOverview
{
    #[LiveProp(
        writable: true,
        url: true,
        onUpdated: 'onSearchUpdated',
    )]
    public string $search = '';

    public function __construct(private readonly CompanyRepository $companyRepository)
    {
    }

    public function onSearchUpdated(): void
    {
        $this->resetToFirstPage();
    }

    /**
     * @return list<Company>
     */
    public function getCompanies(): array
    {
        return $this->getRows();
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function filterKey(): array
    {
        return [
            $this->search,
        ];
    }

    /**
     * @return Paginator<Company>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->companyRepository->paginateForAdmin(
            search: $this->search,
            page: $page,
            pageSize: $pageSize,
        );
    }
}
