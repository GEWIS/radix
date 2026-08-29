<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Frontpage;

use App\Entity\Frontpage\Page;
use App\Repository\Frontpage\PageRepository;
use App\Service\Application\FileStorage;
use App\Service\Frontpage\PageImageStore;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Frontpage\Admin\PageImages;
use Override;

use function count;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function random_int;
use function strval;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class PageImagesTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $files = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testItListsWhatThePageHoldsAndRemovesOnlyThat(): void
    {
        $page = $this->aPage();
        $path = $this->uploadFor($page);

        $component = $this->component($page);

        $images = $component->getImages();
        self::assertCount(
            1,
            $images,
        );
        self::assertSame(
            $path,
            $images[0]->path,
        );
        self::assertFalse($images[0]->ready);

        $component->remove($path);

        self::assertNull($component->feedback);
        self::assertSame(
            [],
            $component->getImages(),
        );
        self::assertFalse(self::getContainer()->get(FileStorage::class)->exists($path));
    }

    public function testAnImageOfAnotherPageIsLeftAlone(): void
    {
        [
            $page,
            $other,
        ] = $this->twoPages();

        $path = $this->uploadFor($other);
        $component = $this->component($page);

        $component->remove($path);

        self::assertNotNull($component->feedback);
        self::assertTrue(self::getContainer()->get(FileStorage::class)->exists($path));
    }

    public function testAPageThatDoesNotExistYetIsBrowsedByItsRun(): void
    {
        $run = '00112233445566aa';
        $store = self::getContainer()->get(PageImageStore::class);

        $stored = $store->store(
            $this->image(),
            strval($store->scope(
                null,
                $run,
            )),
        );

        $component = self::getContainer()->get(PageImages::class);
        $component->mount(
            null,
            $run,
        );

        $images = $component->getImages();
        self::assertCount(
            1,
            $images,
        );
        self::assertSame(
            $stored->path,
            $images[0]->path,
        );
    }

    private function component(Page $page): PageImages
    {
        $component = self::getContainer()->get(PageImages::class);
        $component->mount($page->getId());

        return $component;
    }

    private function uploadFor(Page $page): string
    {
        $store = self::getContainer()->get(PageImageStore::class);

        return $store->store(
            $this->image(),
            strval($store->scope(
                $page,
                null,
            )),
        )->path;
    }

    private function aPage(): Page
    {
        return $this->twoPages()[0];
    }

    /**
     * @return array{0: Page, 1: Page}
     */
    private function twoPages(): array
    {
        $pages = self::getContainer()->get(PageRepository::class)->findAll();
        self::assertGreaterThan(
            1,
            count($pages),
        );

        return [
            $pages[0],
            $pages[1],
        ];
    }

    private function image(): string
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'radix-page-image',
        );
        self::assertIsString($path);

        $image = imagecreatetruecolor(
            64,
            64,
        );
        self::assertNotFalse($image);
        $colour = imagecolorallocate(
            $image,
            random_int(
                0,
                255,
            ),
            random_int(
                0,
                255,
            ),
            random_int(
                0,
                255,
            ),
        );
        self::assertNotFalse($colour);
        imagefilledrectangle(
            $image,
            0,
            0,
            63,
            63,
            $colour,
        );
        imagejpeg(
            $image,
            $path,
        );

        $this->files[] = $path;

        return $path;
    }
}
