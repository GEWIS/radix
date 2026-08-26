<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Message\Application\PregenerateImageVariantMessage;
use App\MessageHandler\Application\PregenerateImageVariantHandler;
use App\Service\Application\FileStorage;
use App\Service\Application\VariantGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function dirname;

final class PregenerateImageVariantHandlerTest extends KernelTestCase
{
    public function testMessageRoutesToTheImagesTransport(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(MessageBusInterface::class)->dispatch(
            new PregenerateImageVariantMessage(
                $this->storeSource(),
                ImageVariant::W320,
            ),
        );

        $transport = $container->get('messenger.transport.images');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );
        self::assertCount(
            1,
            $transport->getSent(),
        );
    }

    public function testHandlerGeneratesTheRequestedVariant(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $source = $this->storeSource();
        $container->get(PregenerateImageVariantHandler::class)(new PregenerateImageVariantMessage(
            $source,
            ImageVariant::W320,
        ));

        self::assertTrue(
            $container->get(VariantGenerator::class)->variantExists(
                $source,
                ImageVariant::W320,
            ),
        );
    }

    public function testHandlerSkipsAWidthFitVariantWiderThanTheOriginal(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // The opposite of GenerateImageVariantHandler, which caps at the original width rather than leave a request
        // unanswered. Nobody is waiting on a backfilled variant, so an 800px original gets no 2560px one.
        $source = $this->storeSource();
        $container->get(PregenerateImageVariantHandler::class)(new PregenerateImageVariantMessage(
            $source,
            ImageVariant::W2560,
        ));

        self::assertFalse(
            $container->get(VariantGenerator::class)->variantExists(
                $source,
                ImageVariant::W2560,
            ),
        );
    }

    public function testForcedMessageReEncodesOverAnExistingVariant(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $generator = $container->get(VariantGenerator::class);
        $storage = $container->get(FileStorage::class);

        $source = $this->storeSource();
        $cachePath = $generator->cachePath(
            $source,
            ImageVariant::W320,
        );
        $storage->write(
            $cachePath,
            'stale',
        );

        $container->get(PregenerateImageVariantHandler::class)(new PregenerateImageVariantMessage(
            $source,
            ImageVariant::W320,
            true,
        ));

        self::assertNotSame(
            'stale',
            $storage->read($cachePath),
        );
    }

    public function testUnforcedMessageLeavesAnExistingVariantAlone(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $generator = $container->get(VariantGenerator::class);
        $storage = $container->get(FileStorage::class);

        $source = $this->storeSource();
        $cachePath = $generator->cachePath(
            $source,
            ImageVariant::W320,
        );
        $storage->write(
            $cachePath,
            'stale',
        );

        $container->get(PregenerateImageVariantHandler::class)(new PregenerateImageVariantMessage(
            $source,
            ImageVariant::W320,
        ));

        self::assertSame(
            'stale',
            $storage->read($cachePath),
        );
    }

    private function storeSource(): string
    {
        return self::getContainer()->get(FileStorage::class)->store(
            StorageNamespace::PhotoOriginal,
            dirname(
                __DIR__,
                4,
            ) . '/tests/Resources/images/gala-dinner-1.jpg',
            '1',
        )->path;
    }
}
