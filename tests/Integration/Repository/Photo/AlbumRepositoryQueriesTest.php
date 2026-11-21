<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Photo;

use App\Entity\Photo\Album;
use App\Repository\Photo\AlbumRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;

use function array_map;
use function array_search;
use function iterator_to_array;

/**
 * The album queries a page is built from: which albums fall in a date range or under a parent, how many sub-albums and
 * photos they hold, and which of them are published at all.
 *
 * The albums are built here rather than taken from the seed, so that "in range", "root" and "unpublished" each have an
 * album that is exactly one of those things.
 */
final class AlbumRepositoryQueriesTest extends DatabaseTestCase
{
    private AlbumRepository $repository;

    private Album $root;

    private Album $publishedChild;

    private Album $draftChild;

    private Album $undatedRoot;

    private Album $draftRoot;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(AlbumRepository::class);

        $this->root = $this->album(
            'Query Test Root',
            true,
            '2031-05-01 12:00',
        );
        $this->publishedChild = $this->album(
            'Query Test Root - Dinner',
            true,
            '2031-05-01 18:00',
            $this->root,
        );
        $this->draftChild = $this->album(
            'Query Test Root - Draft',
            false,
            '2031-05-02 18:00',
            $this->root,
        );
        $this->undatedRoot = $this->album(
            'Query Test Undated',
            true,
            null,
        );
        $this->draftRoot = $this->album(
            'Query Test Draft Root',
            false,
            '2031-06-01 12:00',
        );

        $this->entityManager->flush();
    }

    public function testTheDateRangeOnlyReturnsRootAlbumsInsideIt(): void
    {
        $albums = $this->repository->getAlbumsInDateRange(
            new DateTimeImmutable('2031-01-01 00:00'),
            new DateTimeImmutable('2031-12-31 23:59'),
        );
        $names = $this->names($albums);

        self::assertContains(
            $this->root->name,
            $names,
        );
        // A sub-album is part of its parent, not an album of its own in the overview.
        self::assertNotContains(
            $this->publishedChild->name,
            $names,
        );
        self::assertNotContains(
            $this->draftChild->name,
            $names,
        );
        // Published only by default.
        self::assertNotContains(
            $this->draftRoot->name,
            $names,
        );
        // Dated albums outside the window stay out.
        self::assertNotContains(
            $this->undatedRoot->name,
            $names,
        );

        foreach ($albums as $album) {
            self::assertNull($album->getParent());
            self::assertTrue($album->published);
        }
    }

    public function testTheDateRangePutsTheMostRecentAlbumFirst(): void
    {
        $albums = $this->repository->getAlbumsInDateRange(
            new DateTimeImmutable('2031-01-01 00:00'),
            new DateTimeImmutable('2031-12-31 23:59'),
        );

        $dates = array_map(
            static fn (Album $album): DateTimeImmutable => $album->startDateTime ?? new DateTimeImmutable('@0'),
            $albums,
        );

        $previous = null;
        foreach ($dates as $date) {
            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $date,
                );
            }

            $previous = $date;
        }
    }

    public function testTheDateRangeCanIncludeDrafts(): void
    {
        $names = $this->names($this->repository->getAlbumsInDateRange(
            new DateTimeImmutable('2031-01-01 00:00'),
            new DateTimeImmutable('2031-12-31 23:59'),
            false,
        ));

        self::assertContains(
            $this->draftRoot->name,
            $names,
        );
    }

    public function testThePublishedStartDatesAreTheOnesWithAnAlbumBehindThem(): void
    {
        $rows = $this->repository->getPublishedRootAlbumStartDates();

        $dates = array_map(
            static fn (array $row): string => $row['startDateTime']->format('Y-m-d H:i'),
            $rows,
        );

        self::assertContains(
            '2031-05-01 12:00',
            $dates,
        );
        // A draft has no public year to hang in the switcher, and neither has an album without a date.
        self::assertCount(
            (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(a.id)')
                ->from(
                    Album::class,
                    'a',
                )
                ->where('a.parent IS NULL')
                ->andWhere('a.startDateTime IS NOT NULL')
                ->andWhere('a.published = TRUE')
                ->getQuery()
                ->getSingleScalarResult(),
            $rows,
        );
    }

    public function testSubAlbumCountsSayHowManyAlbumsAreUnderneath(): void
    {
        self::assertNotNull($this->root->id);

        self::assertSame(
            [$this->root->id => 2],
            $this->repository->getSubAlbumCounts([$this->root]),
        );

        // A draft sub-album is not counted against a parent the public sees.
        self::assertSame(
            [$this->root->id => 1],
            $this->repository->getSubAlbumCounts(
                [$this->root],
                true,
            ),
        );

        // An album without sub-albums is absent rather than a zero.
        self::assertSame(
            [],
            $this->repository->getSubAlbumCounts([$this->undatedRoot]),
        );

        self::assertSame(
            [],
            $this->repository->getSubAlbumCounts([]),
        );
    }

    public function testTheRootOverviewListsDraftsAndPutsUndatedAlbumsLast(): void
    {
        $albums = $this->repository->findRootAlbums();
        $names = $this->names($albums);

        self::assertContains(
            $this->draftRoot->name,
            $names,
        );
        self::assertContains(
            $this->undatedRoot->name,
            $names,
        );
        self::assertNotContains(
            $this->publishedChild->name,
            $names,
        );

        $rootIndex = array_search(
            $this->root->name,
            $names,
            true,
        );
        $undatedIndex = array_search(
            $this->undatedRoot->name,
            $names,
            true,
        );

        self::assertIsInt($rootIndex);
        self::assertIsInt($undatedIndex);
        self::assertLessThan(
            $undatedIndex,
            $rootIndex,
            'An album without a date belongs at the end of the overview.',
        );
    }

    public function testThePublishedPaginatorOnlyHoldsPublishedAlbumsNewestFirst(): void
    {
        $page = iterator_to_array(
            $this->repository->paginatePublished(
                1,
                1000,
            ),
            false,
        );

        self::assertNotEmpty($page);
        self::assertContains(
            (int) $this->root->id,
            array_map(
                static fn (Album $album): int => (int) $album->id,
                $page,
            ),
        );
        self::assertNotContains(
            (int) $this->draftRoot->id,
            array_map(
                static fn (Album $album): int => (int) $album->id,
                $page,
            ),
        );

        $previous = null;
        foreach ($page as $album) {
            self::assertTrue($album->published);

            if (
                null !== $previous
                && null !== $album->startDateTime
            ) {
                self::assertLessThanOrEqual(
                    $previous,
                    $album->startDateTime,
                );
            }

            $previous = $album->startDateTime;
        }

        self::assertCount(
            2,
            iterator_to_array(
                $this->repository->paginatePublished(
                    1,
                    2,
                ),
                false,
            ),
        );
    }

    public function testFindPublishedRefusesADraft(): void
    {
        self::assertNotNull($this->root->id);
        self::assertSame(
            $this->root,
            $this->repository->findPublished($this->root->id),
        );
        self::assertNotNull($this->draftRoot->id);
        self::assertNull($this->repository->findPublished($this->draftRoot->id));
    }

    public function testTheChildrenOfAnAlbumAreItsPublishedOnesOldestFirst(): void
    {
        self::assertSame(
            [$this->publishedChild->id],
            array_map(
                static function (Album $album): int {
                    self::assertNotNull($album->id);

                    return $album->id;
                },
                $this->repository->findPublishedChildren($this->root),
            ),
        );
    }

    public function testTheAlbumsOfAnActivityAreThePublishedOnes(): void
    {
        $album = $this->repository->findOneBy(['name' => 'Movie Night']);
        self::assertInstanceOf(
            Album::class,
            $album,
            'The seed is expected to link an album to an activity.',
        );

        $activity = $album->activity;
        self::assertNotNull($activity);

        $albums = $this->repository->findPublishedByActivity($activity);
        self::assertContains(
            $album->id,
            array_map(
                static function (Album $found): int {
                    self::assertNotNull($found->id);

                    return $found->id;
                },
                $albums,
            ),
        );

        foreach ($albums as $found) {
            self::assertSame(
                $activity,
                $found->activity,
            );
            self::assertTrue($found->published);
        }
    }

    private function album(
        string $name,
        bool $published,
        ?string $startDateTime,
        ?Album $parent = null,
    ): Album {
        $album = new Album();
        $album->name = $name;
        $album->published = $published;

        if (null !== $startDateTime) {
            $album->startDateTime = new DateTimeImmutable($startDateTime);
            $album->endDateTime = $album->startDateTime;
        }

        if (null !== $parent) {
            $album->setParent($parent);
        }

        $this->entityManager->persist($album);

        return $album;
    }

    /**
     * @param Album[] $albums
     *
     * @return list<string>
     */
    private function names(array $albums): array
    {
        $names = [];
        foreach ($albums as $album) {
            $names[] = $album->name;
        }

        return $names;
    }
}
