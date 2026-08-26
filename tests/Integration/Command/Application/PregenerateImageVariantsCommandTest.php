<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\StorageNamespace;
use App\Message\Application\PregenerateImageVariantMessage;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageVariantResponder;
use App\Service\Application\VariantGenerator;
use App\Tests\Integration\DatabaseTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function array_map;
use function dirname;
use function sort;

/**
 * Storage is the in-memory adapter, so the walk only sees what each test stores, and the `images` transport is
 * in-memory too, so what the command queued is what the transport holds. The command encodes nothing itself: every
 * assertion here is about the messages, not about files in the variant cache.
 */
final class PregenerateImageVariantsCommandTest extends DatabaseTestCase
{
    public function testQueuesEveryMissingVariantOfAStoredPhoto(): void
    {
        $source = $this->storeSource(StorageNamespace::PhotoOriginal);

        $this->executeCommand();

        // The whole album-photo ladder, including the widths the 800px original cannot fill: only the worker decodes,
        // so only the worker can find that out.
        self::assertSame(
            [
                'w1280',
                'w1920',
                'w2560',
                'w320',
                'w640',
                'w960',
            ],
            $this->queuedVariants(),
        );

        // Nothing was encoded in-process.
        self::assertFalse($this->variantGenerator()->variantExists(
            $source,
            ImageVariant::W320,
        ));
    }

    public function testCompanyImageWithoutABannerPackageIsTreatedAsALogo(): void
    {
        $this->storeSource(StorageNamespace::CompanyImage);

        $this->executeCommand();

        self::assertSame(
            [
                'w320',
                'w640',
            ],
            $this->queuedVariants(),
        );
    }

    public function testVariantsAlreadyInTheCacheAreNotQueued(): void
    {
        $source = $this->storeSource(StorageNamespace::PhotoOriginal);
        $this->variantGenerator()->generateVariant(
            $source,
            ImageVariant::W320,
            85,
        );

        $this->executeCommand();

        self::assertNotContains(
            'w320',
            $this->queuedVariants(),
        );
    }

    public function testForceQueuesVariantsThatAreAlreadyInTheCache(): void
    {
        $source = $this->storeSource(StorageNamespace::PhotoOriginal);
        $this->variantGenerator()->generateVariant(
            $source,
            ImageVariant::W320,
            85,
        );

        $this->executeCommand(['--force' => true]);

        $messages = $this->queuedMessages();
        self::assertContains(
            'w320',
            array_map(
                static fn (PregenerateImageVariantMessage $message): string => $message->getVariant()->value,
                $messages,
            ),
        );
        self::assertTrue($messages[0]->isForced());
    }

    public function testDryRunQueuesNothing(): void
    {
        $this->storeSource(StorageNamespace::PhotoOriginal);

        $this->executeCommand(['--dry-run' => true]);

        self::assertSame(
            [],
            $this->queuedVariants(),
        );
    }

    public function testLimitBoundsWhatIsQueued(): void
    {
        $this->storeSource(StorageNamespace::PhotoOriginal);

        $this->executeCommand(['--limit' => '2']);

        self::assertCount(
            2,
            $this->queuedMessages(),
        );
    }

    public function testPrefixBoundsTheRun(): void
    {
        $this->storeSource(StorageNamespace::PhotoOriginal);
        $logo = $this->storeSource(StorageNamespace::CompanyImage);

        $this->executeCommand(['--prefix' => 'career/']);

        foreach ($this->queuedMessages() as $message) {
            self::assertSame(
                $logo,
                $message->getSourcePath(),
            );
        }
    }

    public function testAQueuedVariantIsMarkedPendingForTheServingPath(): void
    {
        $source = $this->storeSource(StorageNamespace::PhotoOriginal);

        $this->executeCommand();

        // Without this a visitor hitting the variant before a worker reaches it would queue a second message for it.
        self::assertTrue(
            self::getContainer()->get(CacheItemPoolInterface::class)->getItem(
                ImageVariantResponder::pendingCacheKey(
                    $source,
                    ImageVariant::W320,
                ),
            )->isHit(),
        );
    }

    /** @param array<string, mixed> $input */
    private function executeCommand(array $input = []): void
    {
        $this->assertCommandIsSuccessful(static::runCommand(
            'app:image:pregenerate',
            $input,
        ));
    }

    /** @return list<PregenerateImageVariantMessage> */
    private function queuedMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.images');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(
                PregenerateImageVariantMessage::class,
                $message,
            );

            $messages[] = $message;
        }

        return $messages;
    }

    /** @return list<string> */
    private function queuedVariants(): array
    {
        $variants = array_map(
            static fn (PregenerateImageVariantMessage $message): string => $message->getVariant()->value,
            $this->queuedMessages(),
        );
        sort($variants);

        return $variants;
    }

    private function variantGenerator(): VariantGenerator
    {
        return self::getContainer()->get(VariantGenerator::class);
    }

    private function storeSource(StorageNamespace $namespace): string
    {
        return self::getContainer()->get(FileStorage::class)->store(
            $namespace,
            dirname(
                __DIR__,
                4,
            ) . '/tests/Resources/images/gala-dinner-1.jpg',
            '1',
        )->path;
    }
}
