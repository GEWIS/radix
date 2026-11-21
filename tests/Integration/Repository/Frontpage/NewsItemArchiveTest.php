<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Frontpage;

use App\Entity\Application\AssociationYear;
use App\Entity\Frontpage\Enums\NewsCategory;
use App\Entity\Frontpage\NewsItem;
use App\Repository\Frontpage\NewsItemRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;

use function count;
use function iterator_to_array;
use function rsort;

/**
 * The archive behind the news page: read by date rather than by what was pinned, narrowed by the year, the category and
 * what the reader typed, and only ever searching the language the reader is reading in.
 */
final class NewsItemArchiveTest extends DatabaseTestCase
{
    private NewsItemRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(NewsItemRepository::class);
    }

    public function testTheArchiveReadsNewestFirst(): void
    {
        $items = $this->archive();

        self::assertGreaterThan(
            1,
            count($items),
        );

        $previous = null;
        foreach ($items as $item) {
            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $item->date,
                );
            }

            $previous = $item->date;
        }
    }

    public function testTheArchiveNarrowsToACategory(): void
    {
        $items = $this->archive(category: NewsCategory::Board);

        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertSame(
                NewsCategory::Board,
                $item->category,
            );
        }
    }

    public function testTheArchiveNarrowsToAnAssociationYear(): void
    {
        $year = AssociationYear::fromDate(new DateTimeImmutable())->getYear();
        $associationYear = AssociationYear::fromYear($year);

        self::assertCount(
            (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(n.id)')
                ->from(
                    NewsItem::class,
                    'n',
                )
                ->where('n.date BETWEEN :start AND :end')
                ->setParameter(
                    'start',
                    $associationYear->getStartDate(),
                )
                ->setParameter(
                    'end',
                    $associationYear->getEndDate(),
                )
                ->getQuery()
                ->getSingleScalarResult(),
            $this->archive(year: $year),
        );

        // A year before the archive began has nothing in it.
        self::assertSame(
            [],
            $this->archive(year: $year - 50),
        );
    }

    public function testTheSearchOnlyLooksAtTheLanguageBeingRead(): void
    {
        // The yearbook is written about in Dutch as "jaarboek" and in English as "yearbook"; each is found only in the
        // language it is actually written in.
        self::assertCount(
            1,
            $this->archive(
                search: 'jaarboek',
                locale: 'nl',
            ),
        );
        self::assertSame(
            [],
            $this->archive(
                search: 'jaarboek',
                locale: 'en',
            ),
        );

        self::assertCount(
            1,
            $this->archive(
                search: 'yearbook',
                locale: 'en',
            ),
        );
        self::assertSame(
            [],
            $this->archive(
                search: 'yearbook',
                locale: 'nl',
            ),
        );
    }

    public function testTheSearchEscapesWildcards(): void
    {
        // `%` would otherwise match every item, which is not what a reader who typed it meant.
        self::assertSame(
            [],
            $this->archive(search: '%'),
        );
        self::assertSame(
            [],
            $this->archive(search: 'introductie%'),
        );
    }

    public function testTheArchiveIsCappedToAPage(): void
    {
        self::assertCount(
            2,
            $this->archive(limit: 2),
        );
    }

    public function testTheYearSwitcherListsTheYearsWithNewsNewestFirst(): void
    {
        $years = $this->repository->findYears();

        self::assertNotEmpty($years);
        self::assertContains(
            AssociationYear::fromDate(new DateTimeImmutable())->getYear(),
            $years,
        );

        $sorted = $years;
        rsort($sorted);
        self::assertSame(
            $sorted,
            $years,
        );

        foreach ($this->archive() as $item) {
            self::assertContains(
                AssociationYear::fromDate($item->date)->getYear(),
                $years,
            );
        }
    }

    /**
     * @return list<NewsItem>
     */
    private function archive(
        ?int $year = null,
        ?NewsCategory $category = null,
        string $search = '',
        string $locale = 'nl',
        int $limit = 100,
    ): array {
        return iterator_to_array(
            $this->repository->findForOverview(
                $year,
                $category,
                $search,
                $locale,
                $limit,
            ),
            false,
        );
    }
}
