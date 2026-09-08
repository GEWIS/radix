<?php

declare(strict_types=1);

namespace App\Twig\Components\User\Admin;

use App\Entity\User\Enums\SecurityEventCategory;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\SecurityLog;
use App\Repository\User\SecurityLogRepository;
use App\Security\User\SudoVoter;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use DateTimeImmutable;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function in_array;

/**
 * The security trail, as the administration reads it: what happened to accounts, newest first, narrowed by who it
 * happened to, what kind of thing it was, and how far back to look.
 *
 * Behind the same sudo requirement as the rest of the user administration. It is a record of where members sign in
 * from, which is not something to leave behind an ordinary session.
 *
 * @extends AbstractDoctrinePaginatedOverview<SecurityLog>
 */
#[AsLiveComponent(
    name: 'User:Admin:SecurityLogOverview',
    template: 'components/User/Admin/SecurityLogOverview.html.twig',
)]
#[IsGranted(UserRoles::Admin->value)]
#[IsGranted(SudoVoter::ATTRIBUTE)]
final class SecurityLogOverview extends AbstractDoctrinePaginatedOverview
{
    /** How far back each choice in the period filter looks; `0` is everything still on file. */
    private const array WINDOWS = [
        1,
        7,
        30,
        90,
        0,
    ];

    /**
     * A membership number, a company user's address, or an IP address. Matched whole rather than as a fragment: this
     * is a lookup of somebody already known, and a `LIKE` over a table this size answers slowly and finds members
     * whose number merely contains the digits typed.
     */
    #[LiveProp(
        writable: true,
        url: true,
        onUpdated: 'onFilterUpdated',
    )]
    public string $search = '';

    #[LiveProp(
        writable: true,
        url: true,
        onUpdated: 'onFilterUpdated',
    )]
    public ?string $category = null;

    #[LiveProp(
        writable: true,
        url: true,
        onUpdated: 'onFilterUpdated',
    )]
    public int $days = 30;

    public function __construct(private readonly SecurityLogRepository $securityLogRepository)
    {
    }

    public function onFilterUpdated(): void
    {
        $this->resetToFirstPage();
    }

    /**
     * @return list<SecurityLog>
     */
    public function getEntries(): array
    {
        return $this->getRows();
    }

    /**
     * @return list<SecurityEventCategory>
     */
    public function getCategories(): array
    {
        return SecurityEventCategory::cases();
    }

    /**
     * @return list<int>
     */
    public function getWindows(): array
    {
        return self::WINDOWS;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function filterKey(): array
    {
        return [
            $this->search,
            $this->category,
            $this->days,
        ];
    }

    /**
     * @return Paginator<SecurityLog>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->securityLogRepository->paginateForAdmin(
            search: $this->search,
            category: null !== $this->category
                ? SecurityEventCategory::tryFrom($this->category)
                : null,
            events: [],
            since: $this->since(),
            page: $page,
            pageSize: $pageSize,
        );
    }

    private function since(): ?DateTimeImmutable
    {
        // Anything but one of the offered windows is treated as no window at all, rather than as a number of days
        // somebody put in the address bar.
        if (
            !in_array(
                $this->days,
                self::WINDOWS,
                true,
            )
            || 0 === $this->days
        ) {
            return null;
        }

        return new DateTimeImmutable('-' . $this->days . ' days');
    }
}
