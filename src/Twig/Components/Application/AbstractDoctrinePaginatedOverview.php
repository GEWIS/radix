<?php

declare(strict_types=1);

namespace App\Twig\Components\Application;

use App\ViewModel\Application\ResultPage;
use Closure;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;

use function array_map;
use function iterator_to_array;

/**
 * The paging above, for the overviews whose repository already answers with a Doctrine {@see Paginator}, which is
 * most of them. Subclasses write the query and nothing else.
 *
 * Both halves of the paginator are read at once. Every overview renders the pagination partial, which wants the
 * total as much as the rows, and the paginator caches each of its two queries, so nothing is asked for twice.
 *
 * @template T of object
 *
 * @extends AbstractPaginatedOverview<T>
 */
abstract class AbstractDoctrinePaginatedOverview extends AbstractPaginatedOverview
{
    /**
     * @return Paginator<T>
     */
    abstract protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator;

    /**
     * The other tables of an overview that shows more than one, as a paginator per name; the same as
     * {@see AbstractPaginatedOverview::otherTables()}, for a subclass that has a `Paginator` for those as well.
     *
     * @return array<string, Closure(int, int): Paginator<T>>
     */
    protected function otherPaginators(): array
    {
        return [];
    }

    /**
     * @return array<string, Closure(int, int): ResultPage<T>>
     */
    #[Override]
    final protected function otherTables(): array
    {
        return array_map(
            fn (Closure $createPaginator): Closure => fn (
                int $page,
                int $pageSize,
            ): ResultPage => $this->resultPage($createPaginator(
                $page,
                $pageSize,
            )),
            $this->otherPaginators(),
        );
    }

    /**
     * @return ResultPage<T>
     */
    #[Override]
    protected function fetchPage(
        int $page,
        int $pageSize,
    ): ResultPage {
        return $this->resultPage($this->createPaginator(
            $page,
            $pageSize,
        ));
    }

    /**
     * @param Paginator<T> $paginator
     *
     * @return ResultPage<T>
     */
    private function resultPage(Paginator $paginator): ResultPage
    {
        return new ResultPage(
            iterator_to_array(
                $paginator->getIterator(),
                false,
            ),
            $paginator->count(),
        );
    }
}
