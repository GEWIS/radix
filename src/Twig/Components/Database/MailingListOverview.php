<?php

declare(strict_types=1);

namespace App\Twig\Components\Database;

use App\Entity\Database\MailingList;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Database\MailingListRepository;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Every mailing list the association keeps, searchable by name or by either description.
 *
 * @extends AbstractDoctrinePaginatedOverview<MailingList>
 */
#[AsLiveComponent(
    name: 'Database:MailingListOverview',
    template: 'components/Database/MailingListOverview.html.twig',
)]
#[IsGranted(UserRoles::DatabaseReadOnly->value)]
final class MailingListOverview extends AbstractDoctrinePaginatedOverview
{
    #[LiveProp(
        writable: true,
        url: true,
        onUpdated: 'onSearchUpdated',
    )]
    public string $search = '';

    public function __construct(private readonly MailingListRepository $mailingListRepository)
    {
    }

    public function onSearchUpdated(): void
    {
        $this->resetToFirstPage();
    }

    /**
     * @return list<MailingList>
     */
    public function getLists(): array
    {
        return $this->getRows();
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function filterKey(): array
    {
        return [$this->search];
    }

    /**
     * @return Paginator<MailingList>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->mailingListRepository->paginateForOverview(
            $this->search,
            $page,
            $pageSize,
        );
    }
}
