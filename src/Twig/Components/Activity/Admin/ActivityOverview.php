<?php

declare(strict_types=1);

namespace App\Twig\Components\Activity\Admin;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\Activity\Activity;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Activity\ActivityRepository;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use App\ViewModel\Activity\Admin\ActivityAdminRow;
use Closure;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function array_map;
use function assert;

/**
 * Admin activity overview, split into a "pending" table (drafts/submitted/in-review/rejected/closed) and an
 * "approved" table, both paginated. Each member sees the activities they created or that one of their organs
 * organises; a board member can flip {@see self::$showAll} to see every activity.
 *
 * @extends AbstractDoctrinePaginatedOverview<Activity>
 */
#[AsLiveComponent(
    name: 'Activity:Admin:ActivityOverview',
    template: 'components/Activity/Admin/ActivityOverview.html.twig',
)]
#[IsGranted(new Expression("is_granted('ROLE_ACTIVE_MEMBER') or is_granted('ROLE_BOARD')"))]
final class ActivityOverview extends AbstractDoctrinePaginatedOverview
{
    /** The name the pending table pages under, which the template passes to the pagination partial as well. */
    public const string PENDING = 'pending';

    #[LiveProp(writable: true)]
    public bool $showAll = false;

    // The approved table can hold thousands of rows, so it is collapsed by default. Driven as a live prop (not a
    // client-side Bootstrap collapse) so the state survives the Ajax re-render that pagination triggers.
    #[LiveProp(writable: true)]
    public bool $expanded = false;

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
            $this->getRows(self::PENDING),
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

    #[LiveAction]
    #[ReadOnlySafe]
    public function toggleApproved(): void
    {
        $this->expanded = !$this->expanded;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function filterKey(): array
    {
        return [
            $this->showingAll(),
        ];
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
     * @return array<string, Closure(int, int): Paginator<Activity>>
     */
    #[Override]
    protected function otherPaginators(): array
    {
        return [
            self::PENDING => fn (
                int $page,
                int $pageSize,
            ): Paginator => $this->activityRepository->findPendingForAdmin(
                $this->getMember(),
                $this->getOrganIds(),
                $this->showingAll(),
                $page,
                $pageSize,
            ),
        ];
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
