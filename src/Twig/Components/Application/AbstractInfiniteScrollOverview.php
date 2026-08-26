<?php

declare(strict_types=1);

namespace App\Twig\Components\Application;

use App\Attribute\Application\ReadOnlySafe;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The other way a list gets longer: a public overview shows the first {@see STEP} of something and hands the reader
 * more when they ask, rather than cutting the list into numbered pages.
 *
 * Sibling to {@see AbstractPaginatedOverview} and deliberately not the same thing. Pagination is for a surface
 * somebody works through, where the page number is worth a URL and worth jumping between; this is for a surface
 * somebody browses, where there is no page to link to and asking for more never takes anything away.
 *
 * Only the step is shared. How the rows are fetched is not, and should not be: the album overview windows after
 * loading because a voter decides per album what a visitor may see, and the course overview asks for one row more
 * than it shows because its filters are `HAVING` clauses that a count would have to group the whole table for. What
 * every one of them had in common was a page size of its own and the same three lines to grow it.
 */
abstract class AbstractInfiniteScrollOverview
{
    use DefaultActionTrait;

    /**
     * How many rows a reader starts with, and how many more each request for more adds.
     *
     * One number for every overview, where there used to be seven between twelve and twenty-five. Every grid on the
     * site is one, two, three or four columns wide, so a step has to divide by twelve to leave no half-finished row
     * at any breakpoint; twenty-four is the one of those that still fills six rows of the widest grid, and it is at
     * or above what all but one of these overviews showed before.
     */
    public const int STEP = 24;

    /** Not client-writable: it travels in the signed props, so a crafted request cannot ask for the whole archive. */
    #[LiveProp]
    public int $limit = self::STEP;

    /**
     * Whether asking for more would bring anything back. Each overview knows this its own way, and some of them
     * cannot answer it with a count.
     */
    abstract public function hasMore(): bool;

    #[LiveAction]
    #[ReadOnlySafe]
    public function loadMore(): void
    {
        $this->limit += self::STEP;
    }
}
