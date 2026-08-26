<?php

declare(strict_types=1);

namespace App\Twig\Components\Activity\Admin;

use App\Entity\Activity\ActivityRevision;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Activity\ActivityRevisionRepository;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use App\ViewModel\Activity\Admin\ActivityAdminRow;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

use function array_map;

/**
 * The activity submissions waiting on the board, paged through. The rows carry the same columns the activity
 * overview's tables do, so an activity reads the same wherever it is listed.
 *
 * @extends AbstractDoctrinePaginatedOverview<ActivityRevision>
 */
#[AsLiveComponent(
    name: 'Activity:Admin:ApprovalOverview',
    template: 'components/Activity/Admin/ApprovalOverview.html.twig',
)]
#[IsGranted(UserRoles::Board->value)]
final class ApprovalOverview extends AbstractDoctrinePaginatedOverview
{
    public function __construct(private readonly ActivityRevisionRepository $revisionRepository)
    {
    }

    /**
     * @return list<ActivityAdminRow>
     */
    public function getRowModels(): array
    {
        return array_map(
            static fn (ActivityRevision $revision): ActivityAdminRow => ActivityAdminRow::fromRevision($revision),
            $this->getRows(),
        );
    }

    /**
     * @return Paginator<ActivityRevision>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->revisionRepository->paginateForReview(
            $page,
            $pageSize,
        );
    }
}
