<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;

/**
 * A concrete overview that pages over nothing, so the paging itself can be looked at on its own.
 *
 * @extends AbstractDoctrinePaginatedOverview<object>
 */
final class PaginatedOverviewDouble extends AbstractDoctrinePaginatedOverview
{
    /** How often the query was built, which is what says whether a page was reused. */
    public int $queries = 0;

    /** @var array{int, int}|null The page and page size the last query was built for. */
    public ?array $askedFor = null;

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
}
