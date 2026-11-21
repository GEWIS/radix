<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Photo;

use App\Entity\Application\Enums\ImageProfile;
use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Photo\Album;
use App\Entity\Photo\Photo;
use App\Message\Photo\GenerateAlbumCoverMessage;
use App\Message\Photo\ProcessImageVariantsMessage;
use App\MessageHandler\Photo\GenerateAlbumCoverHandler;
use App\Repository\Photo\AlbumRepository;
use App\Repository\Photo\PhotoRepository;
use App\Service\Application\FilePathResolver;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageManagerProvider;
use App\Service\Application\ImageSigner;
use App\Service\Application\ImageUrlBuilder;
use App\Service\Photo\AlbumCoverService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function dirname;
use function json_decode;
use function str_contains;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * The manage view waits for the cover, so every outcome is published: a cover URL, or an empty result when the album
 * has no photos. Variants are generated only for an existing cover.
 */
final class GenerateAlbumCoverHandlerTest extends TestCase
{
    public function testACoverThatWasMadeIsAnnouncedWithItsUrlAndHasItsVariantsQueued(): void
    {
        $published = [];
        $dispatched = [];
        $flushes = 0;
        $album = $this->album(3);
        $storage = $this->storage();

        $handler = $this->handler(
            $storage,
            $album,
            [
                $this->photo(
                    $storage,
                    'gala-dinner-1.jpg',
                ),
            ],
            $published,
            $dispatched,
            $flushes,
        );

        $handler(new GenerateAlbumCoverMessage(3));

        self::assertSame(
            1,
            $flushes,
        );
        self::assertNotNull($album->coverPath);
        self::assertCount(
            1,
            $published,
        );
        self::assertSame(
            ['photo/album/3/cover'],
            $published[0]->getTopics(),
        );
        self::assertTrue($published[0]->isPrivate());

        $payload = json_decode(
            $published[0]->getData(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            'ready',
            $payload['status'],
        );
        self::assertTrue(str_starts_with($payload['url'], '/img/cover/photos/covers/3/'));
        self::assertTrue(str_contains($payload['url'], 'signature='));

        self::assertCount(
            1,
            $dispatched,
        );
        self::assertInstanceOf(
            ProcessImageVariantsMessage::class,
            $dispatched[0],
        );
        self::assertSame(
            $album->coverPath,
            $dispatched[0]->getSourcePath(),
        );
        self::assertSame(
            ImageProfile::AlbumCover,
            $dispatched[0]->getProfile(),
        );
    }

    public function testAnAlbumWithoutPhotosReportsThatItIsEmptyAndQueuesNothing(): void
    {
        $published = [];
        $dispatched = [];
        $flushes = 0;
        $album = $this->album(4);

        $handler = $this->handler(
            $this->storage(),
            $album,
            [],
            $published,
            $dispatched,
            $flushes,
        );
        $handler(new GenerateAlbumCoverMessage(4));

        self::assertSame(
            1,
            $flushes,
        );
        self::assertNull($album->coverPath);
        self::assertCount(
            1,
            $published,
        );
        self::assertSame(
            'photo/album/4/cover',
            $published[0]->getTopics()[0],
        );
        self::assertSame(
            ['status' => 'empty'],
            json_decode(
                $published[0]->getData(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(
            [],
            $dispatched,
        );
    }

    public function testAnAlbumThatWasDeletedIsNotCovered(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $albums = self::createStub(AlbumRepository::class);
        $albums->method('find')->willReturn(null);

        $handler = new GenerateAlbumCoverHandler(
            $albums,
            $this->coverService(
                $this->storage(),
                [],
            ),
            $entityManager,
            $messageBus,
            $hub,
            new ImageUrlBuilder(
                new ImageSigner('test-key'),
                new FilePathResolver(),
            ),
        );
        $handler(new GenerateAlbumCoverMessage(404));
    }

    /**
     * @param list<Update> $published
     * @param list<object> $dispatched
     * @param list<Photo>  $photos
     */
    private function handler(
        FileStorage $storage,
        Album $album,
        array $photos,
        array &$published,
        array &$dispatched,
        int &$flushes,
    ): GenerateAlbumCoverHandler {
        $albums = self::createStub(AlbumRepository::class);
        $albums->method('find')->willReturn($album);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(
            static function () use (&$flushes): void {
                ++$flushes;
            },
        );

        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        $hub = self::createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(
            static function (Update $update) use (&$published): string {
                $published[] = $update;

                return 'published';
            },
        );

        return new GenerateAlbumCoverHandler(
            $albums,
            $this->coverService(
                $storage,
                $photos,
            ),
            $entityManager,
            $messageBus,
            $hub,
            new ImageUrlBuilder(
                new ImageSigner('test-key'),
                new FilePathResolver(),
            ),
        );
    }

    /**
     * @param list<Photo> $photos
     */
    private function coverService(
        FileStorage $storage,
        array $photos,
    ): AlbumCoverService {
        $photoRepository = self::createStub(PhotoRepository::class);
        $photoRepository->method('getRandomPhotosFromAlbums')->willReturn($photos);

        return new AlbumCoverService(
            new ImageManagerProvider(),
            $storage,
            $photoRepository,
        );
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }

    private function album(int $id): Album
    {
        $album = new Album();
        new ReflectionProperty(
            Album::class,
            'id',
        )->setValue(
            $album,
            $id,
        );

        return $album;
    }

    private function photo(
        FileStorage $storage,
        string $fixture,
    ): Photo {
        $path = $storage->store(
            StorageNamespace::PhotoOriginal,
            dirname(
                __DIR__,
                2,
            ) . '/Resources/images/' . $fixture,
            '1',
        )->path;

        $photo = new Photo();
        $photo->path = $path;
        $photo->album = new Album();

        return $photo;
    }
}
