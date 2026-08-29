<?php

declare(strict_types=1);

namespace App\Tests\Service\Frontpage;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Frontpage\Page;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageManagerProvider;
use App\Service\Application\VariantGenerator;
use App\Service\Frontpage\PageImageStore;
use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function base64_decode;
use function end;
use function explode;
use function file_put_contents;
use function strval;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class PageImageStoreTest extends TestCase
{
    private const string PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const string RUN = 'a1b2c3d4e5f60718';

    /** @var list<string> */
    private array $tempFiles = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];
    }

    public function testAnUploadIsFiledUnderThePageItWasMadeFor(): void
    {
        [
            $store,
            $storage,
        ] = $this->store();

        $stored = $store->store(
            $this->png(),
            $this->scopeOf(
                $store,
                12,
            ),
        );

        self::assertStringStartsWith(
            'pages/images/12/',
            $stored->path,
        );
        self::assertTrue($storage->exists($stored->path));
    }

    public function testAnUploadWithoutAPageWaitsUnderTheRunOfTheFlow(): void
    {
        [
            $store,
        ] = $this->store();

        $scope = $store->scope(
            null,
            self::RUN,
        );
        self::assertSame(
            'pending/' . self::RUN,
            $scope,
        );

        $stored = $store->store(
            $this->png(),
            $scope,
        );

        self::assertStringStartsWith(
            'pages/images/pending/' . self::RUN . '/',
            $stored->path,
        );
    }

    public function testAnythingElseHasNoScopeAtAll(): void
    {
        [
            $store,
        ] = $this->store();

        self::assertNull($store->scope(
            null,
            null,
        ));
        self::assertNull($store->scope(
            null,
            '../../etc',
        ));
        self::assertNull($store->scope(
            null,
            'testing',
        ));
        self::assertNull($store->scope(
            new Page(),
            null,
        ));
    }

    public function testClaimingMovesThePendingUploadsAndPointsTheContentAtThem(): void
    {
        [
            $store,
            $storage,
        ] = $this->store();

        $pending = $store->store(
            $this->png(),
            (string) $store->scope(
                null,
                self::RUN,
            ),
        )->path;

        $page = $this->page(7);
        $content = new FrontpageLocalisedText();
        $content->updateValues(
            '<p><img src="/img/w1280/' . $pending . '"></p>',
            null,
        );

        self::assertTrue($store->claim(
            $page,
            self::RUN,
            $content,
        ));

        $moved = 'pages/images/7/' . $this->fileName($pending);
        self::assertTrue($storage->exists($moved));
        self::assertFalse($storage->exists($pending));
        self::assertSame(
            '<p><img src="/img/w1280/' . $moved . '"></p>',
            $content->getValueEN(),
        );
        self::assertNull($content->getValueNL());
    }

    public function testClaimingAnEmptyRunChangesNothing(): void
    {
        [
            $store,
        ] = $this->store();

        $content = new FrontpageLocalisedText();
        $content->updateValues(
            '<p>Hello</p>',
            null,
        );

        self::assertFalse($store->claim(
            $this->page(7),
            self::RUN,
            $content,
        ));
        self::assertSame(
            '<p>Hello</p>',
            $content->getValueEN(),
        );
    }

    public function testAnImageIsUnfinishedUntilTheRendererReportsBack(): void
    {
        [
            $store,
        ] = $this->store();

        $scope = $this->scopeOf(
            $store,
            2,
        );
        $path = $store->store(
            $this->png(),
            $scope,
        )->path;

        self::assertTrue($store->isPending($path));
        self::assertFalse($store->list($scope)[0]->ready);

        $store->settle($path);

        self::assertFalse($store->isPending($path));
        self::assertTrue($store->list($scope)[0]->ready);
    }

    public function testRemovingAnImageTakesItsRenditionsWithIt(): void
    {
        [
            $store,
            $storage,
            $generator,
        ] = $this->store();

        $scope = $this->scopeOf(
            $store,
            3,
        );
        $path = $store->store(
            $this->png(),
            $scope,
        )->path;

        $generator->generateVariant(
            $path,
            ImageVariant::W320,
            85,
            false,
        );
        $cached = $generator->cachePath(
            $path,
            ImageVariant::W320,
        );
        self::assertTrue($storage->exists($cached));

        self::assertTrue($store->remove(
            $scope,
            $path,
        ));
        self::assertFalse($storage->exists($path));
        self::assertFalse($storage->exists($cached));
    }

    public function testTheTopicNamesTheScopeAndNothingWider(): void
    {
        [
            $store,
        ] = $this->store();

        self::assertSame(
            'frontpage/page-images/12',
            $store->topic($this->scopeOf(
                $store,
                12,
            )),
        );
        self::assertSame(
            'frontpage/page-images/pending/' . self::RUN,
            $store->topic(strval($store->scope(
                null,
                self::RUN,
            ))),
        );
    }

    public function testAnImageOfAnotherPageIsLeftAlone(): void
    {
        [
            $store,
            $storage,
        ] = $this->store();

        $path = $store->store(
            $this->png(),
            $this->scopeOf(
                $store,
                3,
            ),
        )->path;

        self::assertFalse($store->remove(
            $this->scopeOf(
                $store,
                4,
            ),
            $path,
        ));
        self::assertFalse($store->remove(
            $this->scopeOf(
                $store,
                4,
            ),
            'pages/images/4/../3/' . $this->fileName($path),
        ));
        self::assertTrue($storage->exists($path));
    }

    public function testTakingDownAPageClearsWhatItHeld(): void
    {
        [
            $store,
            $storage,
        ] = $this->store();

        $scope = $this->scopeOf(
            $store,
            9,
        );
        $path = $store->store(
            $this->png(),
            $scope,
        )->path;

        $store->removeAll($scope);

        self::assertFalse($storage->exists($path));
        self::assertSame(
            [],
            $store->list($scope),
        );
    }

    public function testWhatAnUnfinishedPageLeftBehindIsPrunedOnceItIsOldEnough(): void
    {
        [
            $store,
            $storage,
        ] = $this->store();

        $path = $store->store(
            $this->png(),
            (string) $store->scope(
                null,
                self::RUN,
            ),
        )->path;

        self::assertSame(
            0,
            $store->prune(new DateTimeImmutable('-1 day')),
        );
        self::assertTrue($storage->exists($path));

        self::assertSame(
            1,
            $store->prune(new DateTimeImmutable('+1 day')),
        );
        self::assertFalse($storage->exists($path));
    }

    public function testTheImagesOfAnOldPageAreRecognisedAndFiledUnderIt(): void
    {
        [
            $store,
            $storage,
        ] = $this->store();

        $legacy = 'pages/images/deadbeef.png';
        $storage->write(
            $legacy,
            $this->pngBytes(),
        );

        $content = '<p><img src="/img/w1280/' . $legacy . '">'
            . '<img src="/img/w640/pages/images/5/already-filed.png"></p>';

        self::assertSame(
            [$legacy],
            $store->legacyPaths($content),
        );

        $filed = $store->adopt(
            $this->page(5),
            $legacy,
        );

        self::assertSame(
            'pages/images/5/deadbeef.png',
            $filed,
        );
        self::assertTrue($storage->exists($filed));
        self::assertTrue($storage->exists($legacy));

        self::assertTrue($store->discardLegacy($legacy));
        self::assertFalse($storage->exists($legacy));
    }

    public function testWhatIsAlreadyFiledUnderAPageIsNeverDiscardedAsLegacy(): void
    {
        [
            $store,
            $storage,
        ] = $this->store();

        $path = $store->store(
            $this->png(),
            $this->scopeOf(
                $store,
                5,
            ),
        )->path;

        self::assertFalse($store->discardLegacy($path));
        self::assertTrue($storage->exists($path));
    }

    /**
     * @return array{PageImageStore, FileStorage, VariantGenerator}
     */
    private function store(): array
    {
        $storage = new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
        $generator = new VariantGenerator(
            $storage,
            new ImageManagerProvider(),
        );

        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        return [
            new PageImageStore(
                $storage,
                $generator,
                $messageBus,
                new ArrayAdapter(),
            ),
            $storage,
            $generator,
        ];
    }

    private function scopeOf(
        PageImageStore $store,
        int $id,
    ): string {
        return (string) $store->scope(
            $this->page($id),
            null,
        );
    }

    private function page(int $id): Page
    {
        $page = new Page();

        $property = new ReflectionProperty(
            Page::class,
            'id',
        );
        $property->setValue(
            $page,
            $id,
        );

        return $page;
    }

    private function fileName(string $path): string
    {
        $segments = explode(
            '/',
            $path,
        );

        return end($segments);
    }

    private function png(): string
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'radix-page-image',
        );
        self::assertIsString($path);

        file_put_contents(
            $path,
            $this->pngBytes(),
        );
        $this->tempFiles[] = $path;

        return $path;
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            self::PNG_BASE64,
            true,
        );
        self::assertIsString($bytes);

        return $bytes;
    }
}
