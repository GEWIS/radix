<?php

declare(strict_types=1);

namespace App\Twig\Components\Application;

use App\Attribute\Application\ReadOnlySafe;
use App\Twig\Components\Concerns\PageSizeTrait;
use App\ViewModel\Application\ResultPage;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function ceil;
use function max;

/**
 * The paging every overview does the same way: a page number, a clamped page size, the totals the pagination partial
 * renders, and one query per set of inputs.
 *
 * Subclasses answer {@see self::fetchPage()} with the rows and the total for a page and keep their own filter props;
 * everything else here is deliberately not theirs to write. Every overview that did write its own got the same two
 * things wrong, so the abstraction asks for as little as it can: not a `Paginator`, only what one would have said.
 * {@see AbstractDoctrinePaginatedOverview} is the flavour for the ones that do have a `Paginator` to hand.
 *
 * `#[AsLiveComponent]` and `#[IsGranted]` stay on the concrete component: the factory registers by attribute on the
 * class it finds, and each overview is gated differently.
 *
 * A subclass must bind the type it pages over, or static analysis rejects its `fetchPage()` return type against this
 * one:
 *
 *     &#64;extends AbstractPaginatedOverview&lt;Company&gt;
 *
 * @template T
 */
abstract class AbstractPaginatedOverview
{
    use DefaultActionTrait;
    use PageSizeTrait;

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public int $page = 1;

    /** @var ResultPage<T>|null */
    private ?ResultPage $result = null;

    /**
     * What {@see self::$result} was fetched for, so a cached page cannot outlive the inputs that asked for it.
     *
     * @var array{int, int, list<mixed>}|null
     */
    private ?array $resultKey = null;

    /**
     * One page of whatever this overview lists.
     *
     * @return ResultPage<T>
     */
    abstract protected function fetchPage(
        int $page,
        int $pageSize,
    ): ResultPage;

    /**
     * Anything besides the page and its size that decides what a page holds, so filters belong here. Leaving them
     * out is only safe while nothing reads a page before the filter has finished being applied, which is an ordering
     * an overview should not have to know it depends on.
     *
     * @return list<mixed>
     */
    protected function filterKey(): array
    {
        return [];
    }

    /**
     * @return list<T>
     */
    public function getRows(): array
    {
        return $this->result()->rows;
    }

    public function getTotalCount(): int
    {
        return $this->result()->total;
    }

    public function getTotalPages(): int
    {
        return $this->lastPage($this->getTotalCount());
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function gotoPage(#[LiveArg]
    int $page,): void
    {
        // Only the lower bound belongs here. Working out the last page runs the query, and running it while the page
        // being left behind is still the current one is how an overview ends up serving the page it just left;
        // {@see self::result()} clamps to the last page for every way a page number arrives, this action included.
        $this->page = max(
            1,
            $page,
        );
    }

    /**
     * Narrowing the list restarts at the first page, so a reader is never left on a page that no longer exists.
     */
    protected function resetToFirstPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return ResultPage<T>
     */
    private function result(): ResultPage
    {
        $page = max(
            1,
            $this->page,
        );
        $result = $this->fetch($page);

        // A page number arrives from the URL as well as from `gotoPage()`, so a hand-written `?page=999` would
        // otherwise render an empty table with no control to get back out of it. Counting does not depend on the
        // offset, so the last page is known from the page just fetched, and asking again costs a second query only
        // when the number really was past the end.
        $lastPage = $this->lastPage($result->total);
        if ($page > $lastPage) {
            $page = $lastPage;
            $result = $this->fetch($page);
        }

        // Write the page back so the pager marks the one actually being shown, and the URL prop follows it.
        $this->page = $page;

        return $result;
    }

    /**
     * @return ResultPage<T>
     */
    private function fetch(int $page): ResultPage
    {
        $key = [
            $page,
            $this->pageSize(),
            $this->filterKey(),
        ];

        if (
            null !== $this->result
            && $this->resultKey === $key
        ) {
            return $this->result;
        }

        $this->resultKey = $key;

        return $this->result = $this->fetchPage(
            $page,
            $this->pageSize(),
        );
    }

    private function lastPage(int $totalCount): int
    {
        return max(
            1,
            (int) ceil($totalCount / $this->pageSize()),
        );
    }
}
