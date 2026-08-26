<?php

declare(strict_types=1);

namespace App\Twig\Components\Frontpage\Admin;

use App\Entity\Frontpage\NewsItem;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Frontpage\NewsItemRepository;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * Everything the association has published, newest first, paged through.
 *
 * @extends AbstractDoctrinePaginatedOverview<NewsItem>
 */
#[AsLiveComponent(
    name: 'Frontpage:Admin:NewsOverview',
    template: 'components/Frontpage/Admin/NewsOverview.html.twig',
)]
#[IsGranted(UserRoles::Board->value)]
final class NewsOverview extends AbstractDoctrinePaginatedOverview
{
    public function __construct(private readonly NewsItemRepository $newsItemRepository)
    {
    }

    /**
     * @return list<NewsItem>
     */
    public function getItems(): array
    {
        return $this->getRows();
    }

    /**
     * @return Paginator<NewsItem>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->newsItemRepository->getPaginatorAdapter(
            $page,
            $pageSize,
        );
    }
}
