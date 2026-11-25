<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Photo;

use App\Entity\Photo\Album;
use App\Entity\User\User;
use App\Service\Photo\AlbumService;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Application\AbstractInfiniteScrollOverview;
use App\Twig\Components\Photo\AlbumOverview;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_keys;
use function array_merge;
use function array_values;
use function count;

/**
 * The album grid: one association year's published root albums, grouped by the month they happened in, or a search
 * across every year once the reader has typed something.
 *
 * The seed anchors its albums to the current association year, two of which are drafts and so never listed.
 */
final class AlbumOverviewTest extends DatabaseTestCase
{
    public function testWithoutAYearAndWithoutAQueryThereIsNothingToShow(): void
    {
        $this->signIn();
        $overview = self::getContainer()->get(AlbumOverview::class);

        self::assertSame(
            [],
            $overview->getAlbumsByMonth(),
        );
        self::assertFalse($overview->hasMore());
        self::assertSame(
            [
                'subAlbums' => [],
                'photos' => [],
            ],
            $overview->getCardCounts(),
        );
    }

    public function testAYearListsItsPublishedRootAlbumsByMonth(): void
    {
        $this->signIn();
        $overview = self::getContainer()->get(AlbumOverview::class);
        $overview->year = $this->year();

        $grouped = $overview->getAlbumsByMonth();

        self::assertNotEmpty($grouped);

        $names = [];
        foreach ($grouped as $month => $albums) {
            self::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}$/',
                (string) $month,
            );
            foreach ($albums as $album) {
                $names[] = $album->name;
                self::assertTrue(
                    $album->published,
                    'A draft is not part of the public grid.',
                );
                self::assertNull(
                    $album->getParent(),
                    'The grid shows the albums an evening consists of, not the parts of one.',
                );
            }
        }

        self::assertContains(
            'Trip 2024',
            $names,
        );
        self::assertNotContains(
            'Draft Album',
            $names,
        );
        self::assertNotContains(
            'Gala 2024 - Draft',
            $names,
        );
    }

    public function testSearchingWithinAYearNarrowsToWhatMatches(): void
    {
        $this->signIn();
        $overview = self::getContainer()->get(AlbumOverview::class);
        $overview->year = $this->year();
        $overview->search = 'Trip';

        $names = [];
        foreach ($overview->getAlbumsByMonth() as $albums) {
            foreach ($albums as $album) {
                $names[] = $album->name;
            }
        }

        self::assertContains(
            'Trip 2024',
            $names,
        );
        self::assertNotContains(
            'Movie Night',
            $names,
        );
    }

    /**
     * The whole photo archive is too large to list at once, so the cross-year search waits for a query.
     */
    public function testTheCrossYearSearchWaitsForAQuery(): void
    {
        $this->signIn();
        $empty = self::getContainer()->get(AlbumOverview::class);
        $empty->crossYear = true;

        self::assertSame(
            [],
            $empty->getAlbumsByMonth(),
        );

        $searching = self::getContainer()->get(AlbumOverview::class);
        $searching->crossYear = true;
        $searching->search = 'Gala';

        $found = $searching->getAlbumsByMonth();
        self::assertNotEmpty($found);
        foreach ($found as $albums) {
            foreach ($albums as $album) {
                self::assertStringContainsString(
                    'Gala',
                    $album->name,
                );
            }
        }
    }

    public function testTheWindowIsTheFirstFewAlbumsAndAskingForMoreExtendsIt(): void
    {
        $this->signIn();
        $narrow = self::getContainer()->get(AlbumOverview::class);
        $narrow->year = $this->year();
        $narrow->limit = 1;

        self::assertSame(
            1,
            $this->albumsIn($narrow->getAlbumsByMonth()),
        );
        self::assertTrue($narrow->hasMore());

        $wider = self::getContainer()->get(AlbumOverview::class);
        $wider->year = $this->year();
        $wider->limit = AbstractInfiniteScrollOverview::STEP;

        self::assertGreaterThan(
            1,
            $this->albumsIn($wider->getAlbumsByMonth()),
            'The year holds more than one published album.',
        );

        $wider->loadMore();
        self::assertSame(
            AbstractInfiniteScrollOverview::STEP * 2,
            $wider->limit,
        );
    }

    public function testTheCardCountsCoverTheAlbumsOfTheWindow(): void
    {
        $this->signIn();
        $overview = self::getContainer()->get(AlbumOverview::class);
        $overview->year = $this->year();

        $counts = $overview->getCardCounts();
        $albums = array_merge(...array_values($overview->getAlbumsByMonth()));

        self::assertArrayHasKey(
            'subAlbums',
            $counts,
        );
        self::assertArrayHasKey(
            'photos',
            $counts,
        );

        foreach (array_keys($counts['photos']) as $id) {
            $ids = [];
            foreach ($albums as $album) {
                $ids[] = $album->id;
            }

            self::assertContains(
                $id,
                $ids,
                'A count is only computed for an album on screen.',
            );
        }
    }

    /**
     * @param array<string, Album[]> $grouped
     */
    private function albumsIn(array $grouped): int
    {
        $total = 0;
        foreach ($grouped as $albums) {
            $total += count($albums);
        }

        return $total;
    }

    private function year(): int
    {
        $years = self::getContainer()->get(AlbumService::class)->getViewableRootAlbumYears();
        self::assertNotEmpty(
            $years,
            'The seed publishes an album in a year of its own.',
        );

        return $years[0];
    }

    private function signIn(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_USER'],
        ));
    }
}
