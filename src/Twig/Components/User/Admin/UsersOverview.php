<?php

declare(strict_types=1);

namespace App\Twig\Components\User\Admin;

use App\Attribute\Application\ReadOnlySafe;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Decision\MemberRepository;
use App\Repository\User\UserRepository;
use App\Security\User\SudoVoter;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use App\ViewModel\User\Admin\MemberRow;
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
 * @extends AbstractDoctrinePaginatedOverview<Member>
 */
#[AsLiveComponent(
    name: 'User:Admin:UsersOverview',
    template: 'components/User/Admin/UsersOverview.html.twig',
)]
#[IsGranted(UserRoles::Admin->value)]
#[IsGranted(SudoVoter::ATTRIBUTE)]
final class UsersOverview extends AbstractDoctrinePaginatedOverview
{
    private const array ALLOWED_SORTS = [
        'lidnr',
        'name',
        'type',
        'expiration',
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
    public string $sort = 'lidnr';

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public string $direction = 'asc';

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public ?string $typeFilter = null;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public bool $hiddenOnly = false;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public bool $deletedOnly = false;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public bool $expiredOnly = false;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public bool $activatedOnly = false;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public bool $mfaOnly = false;

    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return list<MemberRow>
     */
    public function getMembers(): array
    {
        $members = $this->getRows();

        // Hydrate the matching `User` per row in one extra query. Doctrine's LEFT JOIN above does not produce a
        // straightforward `Member -> ?User` mapping for unmanaged entities, so we look the users up explicitly.
        $lidnrs = array_map(
            static fn (Member $m): int => $m->getLidnr(),
            $members,
        );

        $users = $this->userRepository->findByLidnrsWithRoles($lidnrs);
        /** @var array<int, User> $usersByLidnr */
        $usersByLidnr = [];
        foreach ($users as $user) {
            $usersByLidnr[$user->getLidnr()] = $user;
        }

        return array_map(
            static fn (Member $m): MemberRow => MemberRow::fromMember(
                $m,
                $usersByLidnr[$m->getLidnr()] ?? null,
            ),
            $members,
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
            $this->typeFilter,
            $this->hiddenOnly,
            $this->deletedOnly,
            $this->expiredOnly,
            $this->activatedOnly,
            $this->mfaOnly,
        ];
    }

    /**
     * @return Paginator<Member>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->memberRepository->paginateForAdmin(
            search: $this->search,
            sort: $this->effectiveSort(),
            direction: $this->direction,
            filters: [
                'type' => $this->resolveTypeFilter(),
                'hiddenOnly' => $this->hiddenOnly,
                'deletedOnly' => $this->deletedOnly,
                'expiredOnly' => $this->expiredOnly,
                'activatedOnly' => $this->activatedOnly,
                'mfaOnly' => $this->mfaOnly,
            ],
            page: $page,
            pageSize: $pageSize,
        );
    }

    /**
     * @return list<MembershipTypes>
     */
    public function getMembershipTypes(): array
    {
        return MembershipTypes::cases();
    }

    /**
     * @return list<string>
     */
    public function getAllowedSorts(): array
    {
        return self::ALLOWED_SORTS;
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
            : 'lidnr';
    }

    private function resolveTypeFilter(): ?MembershipTypes
    {
        if (
            null === $this->typeFilter
            || '' === $this->typeFilter
        ) {
            return null;
        }

        return MembershipTypes::tryFrom($this->typeFilter);
    }
}
