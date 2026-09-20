<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form\Photo;

use App\Entity\Photo\Album;
use App\Form\Photo\AlbumAutocompleter;
use App\Repository\Photo\AlbumRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;

use function array_map;

/**
 * The destination picker's search, run against the seed: it matches on a part of the name, labels a sub-album with
 * its parent, and leaves out the album the photos are already in.
 */
final class AlbumAutocompleterTest extends DatabaseTestCase
{
    public function testFindsAlbumsByPartOfTheNameWithTheParentInTheLabel(): void
    {
        $this->pushRequest(null);

        $labels = $this->labelsFor('Gala');

        self::assertContains(
            'Gala 2024',
            $labels,
        );
        self::assertContains(
            'Gala 2024 / Gala 2024 - Dinner',
            $labels,
        );
    }

    public function testLeavesOutTheExcludedAlbum(): void
    {
        $gala = self::getContainer()->get(AlbumRepository::class)->findOneBy(['name' => 'Gala 2024']);
        self::assertInstanceOf(
            Album::class,
            $gala,
        );
        $this->pushRequest((int) $gala->id);

        $labels = $this->labelsFor('Gala');

        self::assertNotContains(
            'Gala 2024',
            $labels,
        );
        self::assertContains(
            'Gala 2024 / Gala 2024 - Dinner',
            $labels,
        );
    }

    /**
     * @return string[]
     */
    private function labelsFor(string $query): array
    {
        $autocompleter = self::getContainer()->get(AlbumAutocompleter::class);
        $albums = $autocompleter->createFilteredQueryBuilder(
            self::getContainer()->get(AlbumRepository::class),
            $query,
        )->getQuery()->getResult();
        self::assertIsArray($albums);

        return array_map(
            static function (mixed $album) use ($autocompleter): string {
                self::assertInstanceOf(
                    Album::class,
                    $album,
                );

                return $autocompleter->getLabel($album);
            },
            $albums,
        );
    }

    private function pushRequest(?int $exclude): void
    {
        self::getContainer()->get('request_stack')->push(
            new Request(null === $exclude ? [] : ['exclude' => (string) $exclude]),
        );
    }
}
