<?php

declare(strict_types=1);

namespace App\Twig\Components\Activity\Admin;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\Activity\Activity;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Activity\ActivityRepository;
use App\Twig\Components\Application\AbstractPaginatedOverview;
use App\ViewModel\Activity\Admin\ActivityAdminRow;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function array_map;
use function assert;
use function ceil;
use function iterator_to_array;
use function max;
use function min;

/**
 * Admin activity overview, split into a "pending" table (drafts/submitted/in-review/rejected/closed) and an
 * "approved" table, both paginated. Each member sees the activities they created or that one of their organs
 * organises; a board member can flip {@see self::$showAll} to see every activity.
 *
 * @extends AbstractPaginatedOverview<Activity>
 */
#[AsLiveComponent(
    name: 'Activity:Admin:ActivityOverview',
    template: 'components/Activity/Admin/ActivityOverview.html.twig',
)]
#[IsGranted(new Expression("is_granted('ROLE_ACTIVE_MEMBER') or is_granted('ROLE_BOARD')"))]
final class ActivityOverview extends AbstractPaginatedOverview
{
    #[LiveProp(writable: true)]
    public bool $showAll = false;

    /**
     * The pending table pages separately from the approved one, so its own page travels alongside the inherited
     * `page` rather than sharing it. Both tables read the one page size.
     */
    #[LiveProp(
        writable: true,
        url: true,
    )]
    public int $pendingPage = 1;

    // The approved table can hold thousands of rows, so it is collapsed by default. Driven as a live prop (not a
    // client-side Bootstrap collapse) so the state survives the Ajax re-render that pagination triggers.
    #[LiveProp(writable: true)]
    public bool $expanded = false;

    /** @var Paginator<Activity>|null */
    private ?Paginator $pending = null;

    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly Security $security,
    ) {
    }

    public function isBoard(): bool
    {
        return $this->security->isGranted(UserRoles::Board->value);
    }

    /**
     * @return ActivityAdminRow[]
     */
    public function getPendingRows(): array
    {
        return array_map(
            static fn (Activity $activity): ActivityAdminRow => ActivityAdminRow::fromActivity($activity),
            iterator_to_array($this->pendingPaginator()),
        );
    }

    public function getPendingTotalCount(): int
    {
        return $this->pendingPaginator()->count();
    }

    public function getPendingTotalPages(): int
    {
        return max(
            1,
            (int) ceil($this->getPendingTotalCount() / $this->pageSize()),
        );
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function gotoPendingPage(#[LiveArg]
    int $page,): void
    {
        $this->pendingPage = max(
            1,
            min(
                $page,
                $this->getPendingTotalPages(),
            ),
        );
    }

    /**
     * @return ActivityAdminRow[]
     */
    public function getApprovedRows(): array
    {
        return array_map(
            static fn (Activity $activity): ActivityAdminRow => ActivityAdminRow::fromActivity($activity),
            $this->getRows(),
        );
    }

    public function getApprovedTotalCount(): int
    {
        return $this->getTotalCount();
    }

    public function getApprovedTotalPages(): int
    {
        return $this->getTotalPages();
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function toggleApproved(): void
    {
        $this->expanded = !$this->expanded;
    }

    /**
     * @return Paginator<Activity>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->activityRepository->findApprovedForAdmin(
            $this->getMember(),
            $this->getOrganIds(),
            $this->showingAll(),
            $page,
            $pageSize,
        );
    }

    /**
     * @return Paginator<Activity>
     */
    private function pendingPaginator(): Paginator
    {
        return $this->pending ??= $this->activityRepository->findPendingForAdmin(
            $this->getMember(),
            $this->getOrganIds(),
            $this->showingAll(),
            max(
                1,
                $this->pendingPage,
            ),
            $this->pageSize(),
        );
    }

    private function showingAll(): bool
    {
        return $this->showAll && $this->isBoard();
    }

    private function getMember(): Member
    {
        $user = $this->security->getUser();
        assert($user instanceof User);

        return $user->getMember();
    }

    /**
     * @return int[]
     */
    private function getOrganIds(): array
    {
        $ids = [];
        foreach ($this->getMember()->getCurrentOrganInstallations() as $installation) {
            $id = $installation->getOrgan()->getId();
            if (null === $id) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
