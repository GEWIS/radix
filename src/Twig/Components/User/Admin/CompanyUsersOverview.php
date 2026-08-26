<?php

declare(strict_types=1);

namespace App\Twig\Components\User\Admin;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\UserRoles;
use App\Repository\User\CompanyUserRepository;
use App\Security\User\SudoVoter;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use App\ViewModel\User\Admin\CompanyUserRow;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function array_map;
use function in_array;

/**
 * @extends AbstractDoctrinePaginatedOverview<CompanyUser>
 */
#[AsLiveComponent(
    name: 'User:Admin:CompanyUsersOverview',
    template: 'components/User/Admin/CompanyUsersOverview.html.twig',
)]
#[IsGranted(UserRoles::Admin->value)]
#[IsGranted(SudoVoter::ATTRIBUTE)]
final class CompanyUsersOverview extends AbstractDoctrinePaginatedOverview
{
    private const array ALLOWED_SORTS = [
        'company',
        'name',
        'email',
        'mfa',
    ];

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $search = '';

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $sort = 'company';

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $direction = 'asc';

    public function __construct(private readonly CompanyUserRepository $companyUserRepository)
    {
    }

    /**
     * @return list<CompanyUserRow>
     */
    public function getCompanyUsers(): array
    {
        return array_map(
            static fn (CompanyUser $cu): CompanyUserRow => CompanyUserRow::fromCompanyUser($cu),
            $this->getRows(),
        );
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function filterKey(): array
    {
        return [
            $this->search,
            $this->effectiveSort(),
            $this->direction,
        ];
    }

    /**
     * @return Paginator<CompanyUser>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->companyUserRepository->paginateForAdmin(
            search: $this->search,
            sort: $this->effectiveSort(),
            direction: $this->direction,
            page: $page,
            pageSize: $pageSize,
        );
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function toggleSort(#[LiveArg]
    string $column,): void
    {
        if (
            !in_array(
                $column,
                self::ALLOWED_SORTS,
                true,
            )
        ) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = 'asc' === $this->direction
                ? 'desc'
                : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }

        $this->resetToFirstPage();
    }

    private function effectiveSort(): string
    {
        return in_array(
            $this->sort,
            self::ALLOWED_SORTS,
            true,
        )
            ? $this->sort
            : 'company';
    }
}
