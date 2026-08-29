<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Frontpage;

use App\Entity\Application\Enums\ImageVariant;
use App\Message\Frontpage\ProcessPageImageMessage;
use App\MessageHandler\Frontpage\ProcessPageImageHandler;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageManagerProvider;
use App\Service\Application\VariantGenerator;
use App\Service\Frontpage\PageImageStore;
use ArrayObject;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function dirname;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class ProcessPageImageHandlerTest extends TestCase
{
    public function testItRendersTheSizesAndSaysTheImageIsReady(): void
    {
        [
            $handler,
            $store,
            $storage,
            $generator,
            $published,
        ] = $this->handler();

        $path = $store->store(
            $this->fixture(),
            '4',
        )->path;
        self::assertTrue($store->isPending($path));

        $handler(new ProcessPageImageMessage(
            $path,
            '4',
        ));

        self::assertTrue($storage->exists($generator->cachePath(
            $path,
            ImageVariant::W320,
        )));
        self::assertFalse($store->isPending($path));

        self::assertCount(
            1,
            $published,
        );

        $update = $published[0];
        self::assertInstanceOf(
            Update::class,
            $update,
        );
        self::assertSame(
            'frontpage/page-images/4',
            $update->getTopics()[0],
        );

        $payload = json_decode(
            $update->getData(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            'ready',
            $payload['status'],
        );
        self::assertSame(
            $path,
            $payload['path'],
        );
    }

    public function testAnImageThatCannotBeRenderedIsStillReportedOn(): void
    {
        [
            $handler,
            $store,,,
            $published,
        ] = $this->handler();

        $handler(new ProcessPageImageMessage(
            'pages/images/4/nothing-is-here.jpg',
            '4',
        ));

        self::assertFalse($store->isPending('pages/images/4/nothing-is-here.jpg'));
        self::assertCount(
            1,
            $published,
        );
    }

    /**
     * @return array{ProcessPageImageHandler, PageImageStore, FileStorage, VariantGenerator, ArrayObject<int, Update>}
     */
    private function handler(): array
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

        $store = new PageImageStore(
            $storage,
            $generator,
            $messageBus,
            new ArrayAdapter(),
        );

        // An object, so a publish made after this method returns is still seen by the caller.
        /** @var ArrayObject<int, Update> $published */
        $published = new ArrayObject();
        $hub = self::createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(
            static function (Update $update) use ($published): string {
                $published[] = $update;

                return 'published';
            },
        );

        return [
            new ProcessPageImageHandler(
                $generator,
                $store,
                $hub,
            ),
            $store,
            $storage,
            $generator,
            $published,
        ];
    }

    private function fixture(): string
    {
        return dirname(
            __DIR__,
            2,
        ) . '/Resources/images/gala-dinner-1.jpg';
    }
}
