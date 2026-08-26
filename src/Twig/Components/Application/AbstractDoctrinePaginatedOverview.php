<?php

declare(strict_types=1);

namespace App\Twig\Components\Application;

use App\ViewModel\Application\ResultPage;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;

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
     * @return ResultPage<T>
     */
    #[Override]
    protected function fetchPage(
        int $page,
        int $pageSize,
    ): ResultPage {
        $paginator = $this->createPaginator(
            $page,
            $pageSize,
        );

        return new ResultPage(
            iterator_to_array(
                $paginator->getIterator(),
                false,
            ),
            $paginator->count(),
        );
    }
}
