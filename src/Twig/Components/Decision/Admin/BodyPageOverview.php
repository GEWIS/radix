<?php

declare(strict_types=1);

namespace App\Twig\Components\Decision\Admin;

use App\Entity\Decision\Organ;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Decision\OrganRepository;
use App\Twig\Components\Application\AbstractPaginatedOverview;
use App\ViewModel\Application\ResultPage;
use App\ViewModel\Decision\BodyPageRow;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

use function array_slice;
use function array_values;
use function count;
use function ksort;

/**
 * The bodies whose page a reader may write, or, for whoever administers the register, every body the association has
 * ever had. That last list is the one worth paging: it grows with the association's history and never shrinks.
 *
 * The window is taken in PHP rather than in the query. Two of the three lists this pages over are not queries at all,
 * one being the reader's own installations read off their member, and a body count in the hundreds is not worth three
 * fetching strategies to slice at the database.
 *
 * @extends AbstractPaginatedOverview<BodyPageRow>
 */
#[AsLiveComponent(
    name: 'Decision:Admin:BodyPageOverview',
    template: 'components/Decision/Admin/BodyPageOverview.html.twig',
)]
#[IsGranted(
    attribute: new Expression(
        'is_granted("' . UserRoles::ActiveMember->value . '") or is_granted("' . UserRoles::Board->value . '")',
    ),
    message: 'You are not allowed to administer bodies.',
)]
final class BodyPageOverview extends AbstractPaginatedOverview
{
    public function __construct(
        private readonly OrganRepository $organRepository,
        private readonly Security $security,
    ) {
    }

    /**
     * @return list<BodyPageRow>
     */
    public function getBodies(): array
    {
        return $this->getRows();
    }

    /**
     * @return ResultPage<BodyPageRow>
     */
    #[Override]
    protected function fetchPage(
        int $page,
        int $pageSize,
    ): ResultPage {
        $organs = $this->listableOrgans();
        $window = array_slice(
            $organs,
            ($page - 1) * $pageSize,
            $pageSize,
        );

        // Only the page being shown is warmed; the rest would be a query per body nobody is looking at.
        $this->organRepository->warmPageAssociations($window);

        return new ResultPage(
            BodyPageRow::fromOrgans($window),
            count($organs),
        );
    }

    /**
     * @return list<Organ>
     */
    private function listableOrgans(): array
    {
        // Whoever administers the register reads this page as the list of bodies rather than as the list of pages
        // they may write, and that list includes the ones that have been abrogated.
        if ($this->security->isGranted(UserRoles::DatabaseReadOnly->value)) {
            return array_values($this->organRepository->findAll());
        }

        if ($this->security->isGranted(UserRoles::Board->value)) {
            return array_values($this->organRepository->findActive());
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $organs = [];
        foreach ($user->getMember()->getCurrentOrganInstallations() as $installation) {
            $organs[$installation->getOrgan()->getAbbr()] = $installation->getOrgan();
        }

        ksort($organs);

        return array_values($organs);
    }
}
