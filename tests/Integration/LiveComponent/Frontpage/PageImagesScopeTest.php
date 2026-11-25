<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Frontpage;

use App\Repository\Frontpage\PageRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Frontpage\Admin\PageImages;

/**
 * The image list of the page editor. Its scope is fixed on mount and checksummed, so a page without an id and
 * without a form run has no images and nothing to remove.
 */
final class PageImagesScopeTest extends DatabaseTestCase
{
    public function testWithoutAPageOrARunThereIsNothingToBrowse(): void
    {
        $component = self::getContainer()->get(PageImages::class);
        $component->mount();

        self::assertNull($component->scope);
        self::assertNull($component->getTopic());
        self::assertSame(
            [],
            $component->getImages(),
        );
    }

    public function testRemovingSomethingWithoutAScopeLeavesEverythingAlone(): void
    {
        $component = self::getContainer()->get(PageImages::class);
        $component->mount();

        $component->remove('frontpage/page-images/whatever/an-image.jpg');

        self::assertNull(
            $component->feedback,
            'There is nothing to refuse either: no scope means nothing was looked at.',
        );
    }

    public function testAPageInTheEditorBrowsesItsOwnImages(): void
    {
        $page = self::getContainer()->get(PageRepository::class)->findAll()[0] ?? null;
        self::assertNotNull($page);

        $component = self::getContainer()->get(PageImages::class);
        $component->mount($page->id);

        self::assertSame(
            (string) $page->id,
            $component->scope,
        );
        self::assertNotNull($component->getTopic());
    }
}
