<?php

declare(strict_types=1);

namespace App\Twig\Components\Application;

use App\Attribute\Application\ReadOnlySafe;
use App\Twig\Components\Concerns\PageSizeTrait;
use App\ViewModel\Application\ResultPage;
use Closure;
use InvalidArgumentException;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function array_key_exists;
use function ceil;
use function max;
use function sprintf;

/**
 * The paging every overview does the same way: a page number, a clamped page size, the totals the pagination partial
 * renders, and one query per set of inputs.
 *
 * Subclasses answer {@see self::fetchPage()} with the rows and the total for a page and keep their own filter props;
 * everything else here is deliberately not theirs to write. Every overview that did write its own got the same two
 * things wrong, so the abstraction asks for as little as it can: not a `Paginator`, only what one would have said.
 * {@see AbstractDoctrinePaginatedOverview} is the flavour for the ones that do have a `Paginator` to hand.
 *
 * An overview that shows more than one table names the others in {@see self::otherTables()} and reads them through
 * the same methods with the name as their argument. That is all a second table needs, because one written out by
 * hand repeated the same two mistakes: the administrative activity overview clamped in its own action, which ran the
 * query for the page being left and then rendered that page again in place of the one asked for.
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

    /** The table an overview is of, which is the one {@see self::fetchPage()} answers for. */
    protected const string MAIN = '';

    #[LiveProp(
        writable: true,
        url: true,
    )]
    public int $page = 1;

    /**
     * The page of every other table, under the name it is declared with. One prop rather than one per table, so a
     * second table needs nothing here and nothing in the pagination partial beyond its name.
     *
     * A page number written from a query string arrives as a string, so the values are read through
     * {@see self::pageNumber()} rather than used as they arrive.
     *
     * @var array<string, int|string>
     */
    #[LiveProp(
        writable: true,
        url: true,
    )]
    public array $pages = [];

    /** @var array<string, ResultPage<T>> */
    private array $results = [];

    /**
     * What each of {@see self::$results} was fetched for, so a cached page cannot outlive the inputs that asked for
     * it.
     *
     * @var array<string, array{int, int, list<mixed>}>
     */
    private array $resultKeys = [];

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
     * The tables besides the one {@see self::fetchPage()} answers for, as a query per name. An overview with one
     * table, which is almost all of them, does not override this.
     *
     * @return array<string, Closure(int, int): ResultPage<T>>
     */
    protected function otherTables(): array
    {
        return [];
    }

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
    public function getRows(string $table = self::MAIN): array
    {
        return $this->result($table)->rows;
    }

    public function getTotalCount(string $table = self::MAIN): int
    {
        return $this->result($table)->total;
    }

    public function getTotalPages(string $table = self::MAIN): int
    {
        return $this->lastPage($this->getTotalCount($table));
    }

    /**
     * The page another table is on. The main table has {@see self::$page} for this, which the pagination partial
     * reads directly.
     */
    public function getPageOf(string $table): int
    {
        $this->result($table);

        return $this->pageNumber($table);
    }

    #[LiveAction]
    #[ReadOnlySafe]
    public function gotoPage(
        #[LiveArg]
        int $page,
        #[LiveArg]
        string $table = self::MAIN,
    ): void {
        // Only the lower bound belongs here. Working out the last page runs the query, and running it while the page
        // being left behind is still the current one is how an overview ends up serving the page it just left;
        // {@see self::result()} clamps to the last page for every way a page number arrives, this action included.
        $this->setPageNumber(
            $table,
            max(
                1,
                $page,
            ),
        );
    }

    /**
     * Narrowing the list restarts at the first page of every table, so a reader is never left on a page that no
     * longer exists.
     */
    protected function resetToFirstPage(): void
    {
        $this->page = 1;
        $this->pages = [];
    }

    /**
     * @return ResultPage<T>
     */
    private function result(string $table): ResultPage
    {
        $page = $this->pageNumber($table);
        $result = $this->fetch(
            $table,
            $page,
        );

        // A page number arrives from the URL as well as from `gotoPage()`, so a hand-written `?page=999` would
        // otherwise render an empty table with no control to get back out of it. Counting does not depend on the
        // offset, so the last page is known from the page just fetched, and asking again costs a second query only
        // when the number really was past the end.
        $lastPage = $this->lastPage($result->total);
        if ($page > $lastPage) {
            $page = $lastPage;
            $result = $this->fetch(
                $table,
                $page,
            );
        }

        // Write the page back so the pager marks the one actually being shown, and the URL prop follows it.
        $this->setPageNumber(
            $table,
            $page,
        );

        return $result;
    }

    /**
     * @return ResultPage<T>
     */
    private function fetch(
        string $table,
        int $page,
    ): ResultPage {
        $key = [
            $page,
            $this->pageSize(),
            $this->filterKey(),
        ];

        if (
            array_key_exists(
                $table,
                $this->results,
            )
            && ($this->resultKeys[$table] ?? null) === $key
        ) {
            return $this->results[$table];
        }

        $this->resultKeys[$table] = $key;

        return $this->results[$table] = ($this->query($table))(
            $page,
            $this->pageSize(),
        );
    }

    /**
     * @return Closure(int, int): ResultPage<T>
     */
    private function query(string $table): Closure
    {
        if (self::MAIN === $table) {
            return fn (
                int $page,
                int $pageSize,
            ): ResultPage => $this->fetchPage(
                $page,
                $pageSize,
            );
        }

        $tables = $this->otherTables();
        if (
            !array_key_exists(
                $table,
                $tables,
            )
        ) {
            throw new InvalidArgumentException(sprintf(
                'Overview "%s" has no table named "%s".',
                static::class,
                $table,
            ));
        }

        return $tables[$table];
    }

    /**
     * The number a table pages on, cast and bounded here rather than used as the request wrote it.
     */
    private function pageNumber(string $table): int
    {
        return max(
            1,
            (int) (self::MAIN === $table ? $this->page : ($this->pages[$table] ?? 1)),
        );
    }

    private function setPageNumber(
        string $table,
        int $page,
    ): void {
        if (self::MAIN === $table) {
            $this->page = $page;

            return;
        }

        $this->pages[$table] = $page;
    }

    private function lastPage(int $totalCount): int
    {
        return max(
            1,
            (int) ceil($totalCount / $this->pageSize()),
        );
    }
}
