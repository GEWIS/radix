<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use Closure;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;

/**
 * A concrete overview that pages over nothing, so the paging itself can be looked at on its own.
 *
 * It declares a second table as well, which is all a component with two of them declares, so the paging of a table
 * the base class does not page itself is covered by the same tests.
 *
 * @extends AbstractDoctrinePaginatedOverview<object>
 */
final class PaginatedOverviewDouble extends AbstractDoctrinePaginatedOverview
{
    public const string SECOND = 'second';

    /** How often the query was built, which is what says whether a page was reused. */
    public int $queries = 0;

    /** @var array{int, int}|null The page and page size the last query was built for. */
    public ?array $askedFor = null;

    public int $secondQueries = 0;

    /** @var array{int, int}|null */
    public ?array $secondAskedFor = null;

    /** Stands in for a subclass's filter props, so the cache key can be looked at. */
    public string $filter = '';

    /**
     * @param Paginator<object> $paginator
     */
    public function __construct(private readonly Paginator $paginator)
    {
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function filterKey(): array
    {
        return [$this->filter];
    }

    /**
     * @return Paginator<object>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        $this->queries++;
        $this->askedFor = [
            $page,
            $pageSize,
        ];

        return $this->paginator;
    }

    /**
     * @return array<string, Closure(int, int): Paginator<object>>
     */
    #[Override]
    protected function otherPaginators(): array
    {
        return [
            self::SECOND => function (
                int $page,
                int $pageSize,
            ): Paginator {
                $this->secondQueries++;
                $this->secondAskedFor = [
                    $page,
                    $pageSize,
                ];

                return $this->paginator;
            },
        ];
    }
}
