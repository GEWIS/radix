<?php

declare(strict_types=1);

namespace App\Tests\State\Api;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\Pagination\Pagination;
use App\State\Api\CollectionPagination;
use ArrayIterator;
use PHPUnit\Framework\TestCase;
use stdClass;

use function intdiv;
use function iterator_to_array;

use const PHP_INT_MAX;

/**
 * What a consumer may ask for, and what it is given instead when it asks for something that would break the query.
 */
final class CollectionPaginationTest extends TestCase
{
    public function testTheRequestedWindowIsHandedBack(): void
    {
        self::assertSame(
            [
                2,
                50,
                50,
            ],
            $this->pagination()->window(
                $this->operation(),
                [
                    'filters' => [
                        'page' => 2,
                        'itemsPerPage' => 50,
                    ],
                ],
            ),
        );
    }

    public function testAPageSizeAboveTheMaximumIsCutToIt(): void
    {
        self::assertSame(
            [
                1,
                0,
                200,
            ],
            $this->pagination()->window(
                $this->operation(),
                ['filters' => ['itemsPerPage' => 1000]],
            ),
        );
    }

    public function testAPageSizeBelowOneIsRaisedToOne(): void
    {
        self::assertSame(
            [
                1,
                0,
                1,
            ],
            $this->pagination()->window(
                $this->operation(),
                ['filters' => ['itemsPerPage' => 0]],
            ),
        );
    }

    /**
     * API Platform turns the page into `($page - 1) * $itemsPerPage`, which overflows to a float and aborts the
     * request; the last page that still multiplies out to an integer returns an empty page instead.
     */
    public function testAPageThatWouldOverflowIsCutToTheLastOneThatDoesNot(): void
    {
        [
            $page,
            $offset, $itemsPerPage
        ] = $this->pagination()->window(
            $this->operation(),
            [
                'filters' => [
                    'page' => PHP_INT_MAX,
                    'itemsPerPage' => 50,
                ],
            ],
        );

        self::assertSame(
            intdiv(
                PHP_INT_MAX,
                50,
            ),
            $page,
        );
        self::assertSame(
            ($page - 1) * $itemsPerPage,
            $offset,
        );
    }

    public function testAPageBelowOneIsRaisedToOne(): void
    {
        self::assertSame(
            [
                1,
                0,
                50,
            ],
            $this->pagination()->window(
                $this->operation(),
                ['filters' => ['page' => 0]],
            ),
        );
    }

    /**
     * A value that is not an integer is dropped rather than clamped, so the operation's own default applies.
     */
    public function testAnUnreadableValueFallsBackToTheDefault(): void
    {
        self::assertSame(
            [
                1,
                0,
                50,
            ],
            $this->pagination()->window(
                $this->operation(),
                [
                    'filters' => [
                        'page' => 'not a number',
                        'itemsPerPage' => 'also not a number',
                    ],
                ],
            ),
        );
    }

    public function testAValueThatIsNotScalarFallsBackToTheDefault(): void
    {
        self::assertSame(
            [
                1,
                0,
                50,
            ],
            $this->pagination()->window(
                $this->operation(),
                [
                    'filters' => [
                        'page' => ['2'],
                        'itemsPerPage' => ['10'],
                    ],
                ],
            ),
        );
    }

    public function testNoFiltersAtAllIsTheFirstPage(): void
    {
        self::assertSame(
            [
                1,
                0,
                50,
            ],
            $this->pagination()->window(
                $this->operation(),
                [],
            ),
        );
    }

    public function testTheOperationMaximumWinsOverTheGlobalOne(): void
    {
        self::assertSame(
            [
                1,
                0,
                200,
            ],
            $this->pagination()->window(
                $this->operation(),
                ['filters' => ['itemsPerPage' => 5000]],
            ),
        );
    }

    public function testThePaginatorCarriesThePageAndTheTotal(): void
    {
        $first = new stdClass();
        $second = new stdClass();

        $paginator = $this->pagination()->paginator(
            [
                $first,
                $second,
            ],
            2,
            2,
            7,
        );

        self::assertEquals(
            2,
            $paginator->getCurrentPage(),
        );
        self::assertEquals(
            2,
            $paginator->getItemsPerPage(),
        );
        self::assertEquals(
            7,
            $paginator->getTotalItems(),
        );
        self::assertEquals(
            4,
            $paginator->getLastPage(),
        );
        self::assertSame(
            [
                $first,
                $second,
            ],
            iterator_to_array($paginator),
        );
    }

    public function testThePaginatorAcceptsAnIteratorAsItIs(): void
    {
        $item = new stdClass();
        $items = new ArrayIterator([$item]);

        $paginator = $this->pagination()->paginator(
            $items,
            1,
            1,
            1,
        );

        self::assertSame(
            [$item],
            iterator_to_array($paginator),
        );
    }

    private function pagination(): CollectionPagination
    {
        return new CollectionPagination(new Pagination([
            'enabled' => true,
            'client_enabled' => true,
            'client_items_per_page' => true,
            'items_per_page' => 30,
            'maximum_items_per_page' => 100,
        ]));
    }

    private function operation(): Get
    {
        return new Get()
            ->withPaginationItemsPerPage(50)
            ->withPaginationMaximumItemsPerPage(200);
    }
}
