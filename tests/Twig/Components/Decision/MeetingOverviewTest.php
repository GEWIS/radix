<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Repository\Decision\MeetingRepository;
use App\Twig\Components\Decision\MeetingOverview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function count;

/**
 * The meetings overview pages over a row shape of the repository's own rather than a `Paginator`, so it cannot
 * inherit the shared paging and carries its own. These are the parts of it that went wrong (GH-119).
 */
#[CoversClass(MeetingOverview::class)]
final class MeetingOverviewTest extends TestCase
{
    /** @var list<int> */
    private array $pagesAskedFor = [];

    /**
     * The reported bug: clicking a page changed the number and nothing else. Working out the last page ran the query
     * while the page being left behind was still the current one, and the answer was cached under it.
     */
    public function testGoingToAPageQueriesThatPage(): void
    {
        $overview = $this->overview(50);

        $overview->gotoPage(3);
        $overview->getRows();

        self::assertSame(
            3,
            $this->lastPageAskedFor(),
        );
    }

    public function testTheRowsOfTheNewPageAreNotTheOnesAlreadyRead(): void
    {
        $overview = $this->overview(50);

        // Reading the first page is what used to poison everything after it.
        $overview->getRows();
        $overview->gotoPage(2);
        $overview->getRows();

        self::assertSame(
            2,
            $this->lastPageAskedFor(),
        );
    }

    public function testAPagePastTheEndLandsOnTheLastOne(): void
    {
        $overview = $this->overview(15);
        $overview->page = 99;

        $overview->getRows();

        self::assertSame(
            2,
            $overview->page,
        );
        self::assertSame(
            2,
            $this->lastPageAskedFor(),
        );
    }

    public function testAPageIsQueriedOnlyOnceWhileNothingChanges(): void
    {
        $overview = $this->overview(50);

        $overview->getRows();
        $overview->getTotalCount();
        $overview->getTotalPages();

        self::assertCount(
            1,
            $this->pagesAskedFor,
        );
    }

    /**
     * The other half of GH-119: the page was the one prop of the three not written to the URL, so a page could not be
     * linked to and a reload went back to the first one.
     */
    public function testThePageIsWrittenToTheUrl(): void
    {
        $attributes = new ReflectionProperty(
            MeetingOverview::class,
            'page',
        )->getAttributes(LiveProp::class);

        self::assertCount(
            1,
            $attributes,
        );
        // Read as written rather than through the instance: `LiveProp` keeps the flag private.
        self::assertTrue($attributes[0]->getArguments()['url'] ?? false);
    }

    private function lastPageAskedFor(): int
    {
        self::assertNotEmpty($this->pagesAskedFor);

        return $this->pagesAskedFor[count($this->pagesAskedFor) - 1];
    }

    private function overview(int $total): MeetingOverview
    {
        $repository = self::createStub(MeetingRepository::class);
        $repository->method('paginateForOverview')->willReturnCallback(
            function (
                ?MeetingTypes $type,
                ?int $number,
                int $page,
                int $pageSize,
                bool $excludeVirtual = false,
            ) use ($total): array {
                $this->pagesAskedFor[] = $page;

                return [
                    'items' => [],
                    'total' => $total,
                ];
            },
        );

        $overview = new MeetingOverview($repository);
        $overview->pageSize = MeetingOverview::PAGE_SIZES[0];

        return $overview;
    }
}
