<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Message\Application\GenerateImageVariantMessage;
use App\MessageHandler\Application\GenerateImageVariantHandler;
use App\Service\Application\FileStorage;
use App\Service\Application\VariantGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function dirname;

final class GenerateImageVariantHandlerTest extends KernelTestCase
{
    public function testMessageRoutesToTheImagesTransport(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $source = $this->storeSource();
        $container->get(MessageBusInterface::class)->dispatch(
            new GenerateImageVariantMessage(
                $source,
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
        $handler = $container->get(GenerateImageVariantHandler::class);
        $handler(new GenerateImageVariantMessage(
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

    public function testHandlerCapsAWidthFitVariantWiderThanTheOriginal(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // The test photo is narrower than 2560, exactly the case pre-generation skips.
        $source = $this->storeSource();
        $handler = $container->get(GenerateImageVariantHandler::class);
        $handler(new GenerateImageVariantMessage(
            $source,
            ImageVariant::W2560,
        ));

        self::assertTrue(
            $container->get(VariantGenerator::class)->variantExists(
                $source,
                ImageVariant::W2560,
            ),
        );
    }

    public function testHandlerIsANoOpForARemovedSource(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $handler = $container->get(GenerateImageVariantHandler::class);
        $handler(new GenerateImageVariantMessage(
            'photos/albums/1/never-stored.jpg',
            ImageVariant::W320,
        ));

        self::assertFalse(
            $container->get(VariantGenerator::class)->variantExists(
                'photos/albums/1/never-stored.jpg',
                ImageVariant::W320,
            ),
        );
    }

    private function storeSource(): string
    {
        $storage = self::getContainer()->get(FileStorage::class);

        return $storage->store(
            StorageNamespace::PhotoOriginal,
            dirname(
                __DIR__,
                4,
            ) . '/tests/Resources/images/gala-dinner-1.jpg',
            '1',
        )->path;
    }
}
