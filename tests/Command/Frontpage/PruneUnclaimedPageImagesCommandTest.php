<?php

declare(strict_types=1);

namespace App\Tests\Command\Frontpage;

use App\Command\Frontpage\PruneUnclaimedPageImagesCommand;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageManagerProvider;
use App\Service\Application\VariantGenerator;
use App\Service\Frontpage\PageImageStore;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function mkdir;
use function strtotime;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function unlink;

/**
 * An image from a create form that was never finished has no owner and only the directory as a record. Anything
 * older than a day is removed, and the run reports the count.
 */
#[CoversClass(PruneUnclaimedPageImagesCommand::class)]
final class PruneUnclaimedPageImagesCommandTest extends TestCase
{
    private const string RUN = 'a1b2c3d4e5f60718';

    private ?string $directory = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null === $this->directory) {
            return;
        }

        new SymfonyFilesystem()->remove($this->directory);
        $this->directory = null;
    }

    public function testAnAbandonedUploadIsThrownAwayAndCounted(): void
    {
        $storage = $this->storage();

        $abandoned = 'pages/images/pending/' . self::RUN . '/0.png';
        $fresh = 'pages/images/pending/' . self::RUN . '/1.png';
        $storage->write(
            $abandoned,
            'png',
        );
        $storage->write(
            $fresh,
            'png',
        );

        // The sweep reads the adapter's modification time, so the abandoned upload is aged on disk.
        $this->age(
            $abandoned,
            '-2 days',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Threw away 1 image(s) uploaded for pages that were never written.');

        $tester = new CommandTester(new PruneUnclaimedPageImagesCommand(
            $this->store($storage),
            $logger,
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertFalse(
            $storage->exists($abandoned),
            'An image nobody claimed must not stay in storage for good.',
        );
        self::assertTrue(
            $storage->exists($fresh),
            'A form that is still open is within the day the sweep allows.',
        );
        self::assertStringContainsString(
            'Threw away 1 image(s) uploaded for pages that were never written.',
            $tester->getDisplay(),
        );
    }

    public function testNothingIsPrunedWhenEverythingIsRecent(): void
    {
        $storage = $this->storage();
        $recent = 'pages/images/pending/' . self::RUN . '/0.png';
        $storage->write(
            $recent,
            'png',
        );

        $tester = new CommandTester(new PruneUnclaimedPageImagesCommand(
            $this->store($storage),
            self::createStub(LoggerInterface::class),
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertTrue($storage->exists($recent));
        self::assertStringContainsString(
            'Threw away 0 image(s)',
            $tester->getDisplay(),
        );
    }

    private function store(FileStorage $storage): PageImageStore
    {
        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        return new PageImageStore(
            $storage,
            new VariantGenerator(
                $storage,
                new ImageManagerProvider(),
            ),
            $messageBus,
            new ArrayAdapter(),
        );
    }

    /**
     * A storage on the real filesystem, because the sweep ages files by modification time and an in-memory adapter
     * stamps everything with now.
     */
    private function storage(): FileStorage
    {
        $directory = (string) tempnam(
            sys_get_temp_dir(),
            'radix-page-images-',
        );
        unlink($directory);
        mkdir($directory);
        $this->directory = $directory;

        return new FileStorage(new Filesystem(new LocalFilesystemAdapter($directory)));
    }

    private function age(
        string $path,
        string $modifier,
    ): void {
        self::assertNotNull($this->directory);
        touch(
            $this->directory . '/' . $path,
            (int) strtotime($modifier),
        );
    }
}
