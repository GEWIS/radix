<?php

declare(strict_types=1);

namespace App\Twig\Components\Frontpage\Admin;

use App\Entity\Frontpage\Poll;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Frontpage\PollRepository;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * Every poll the association has agreed to, newest closing date first, paged through.
 *
 * @extends AbstractDoctrinePaginatedOverview<Poll>
 */
#[AsLiveComponent(
    name: 'Frontpage:Admin:PollOverview',
    template: 'components/Frontpage/Admin/PollOverview.html.twig',
)]
#[IsGranted(UserRoles::Board->value)]
final class PollOverview extends AbstractDoctrinePaginatedOverview
{
    public function __construct(private readonly PollRepository $pollRepository)
    {
    }

    /**
     * @return list<Poll>
     */
    public function getPolls(): array
    {
        $polls = $this->getRows();

        // The rows show what each question was answered, which is a query per poll unless the page is warmed at once.
        $this->pollRepository->primeResults($polls);

        return $polls;
    }

    /**
     * @return Paginator<Poll>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->pollRepository->paginateForAdmin(
            $page,
            $pageSize,
        );
    }
}
