<?php

declare(strict_types=1);

namespace App\Tests\Command\Education;

use App\Command\Education\PruneExpiredDownloadsCommand;
use App\Entity\Education\CourseDocumentDownload;
use App\Entity\Education\Enums\DownloadStatus;
use App\Repository\Education\CourseDocumentDownloadRepository;
use App\Service\Application\FileStorage;
use App\Service\Application\ImageManagerProvider;
use App\Service\Education\CourseDocumentDownloadService;
use App\Service\Education\WatermarkedPdfBuilder;
use App\Service\Education\WatermarkRenderer;
use App\Service\Education\WatermarkTextBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A watermarked copy is meant to be collected within the minute it was built. An expired download must go from the
 * table and storage, and the run reports how many went.
 */
#[CoversClass(PruneExpiredDownloadsCommand::class)]
final class PruneExpiredDownloadsCommandTest extends TestCase
{
    public function testAnExpiredDownloadIsRemovedFromTheTableAndStorage(): void
    {
        $storage = new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
        $path = 'education/download/uncollected.pdf';
        $storage->write(
            $path,
            'a watermarked exam',
        );

        $download = new CourseDocumentDownload();
        $download->token = Uuid::v4();
        $download->requestedByName = 'A Member';
        $download->requestedFrom = '10.0.0.1';
        $download->requestedAt = new DateTimeImmutable('-1 hour');
        $download->status = DownloadStatus::Ready;
        $download->path = $path;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('remove')
            ->with($download);
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new PruneExpiredDownloadsCommand(
            $this->service(
                $storage,
                $entityManager,
                [$download],
            ),
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertFalse(
            $storage->exists($path),
            'The watermarked bytes must not outlive the request they were built for.',
        );
        self::assertStringContainsString(
            'Removed 1 expired download.',
            $tester->getDisplay(),
        );
    }

    public function testNothingExpiredRemovesNothing(): void
    {
        $storage = new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');
        $entityManager->expects(self::never())->method('flush');

        $tester = new CommandTester(new PruneExpiredDownloadsCommand(
            $this->service(
                $storage,
                $entityManager,
                [],
            ),
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Removed 0 expired downloads.',
            $tester->getDisplay(),
        );
    }

    /**
     * The repository query is stubbed to the rows this case needs; the service around it is real, because the
     * command is what is tested.
     *
     * @param CourseDocumentDownload[] $expired
     */
    private function service(
        FileStorage $storage,
        EntityManagerInterface $entityManager,
        array $expired,
    ): CourseDocumentDownloadService {
        $repository = self::createStub(CourseDocumentDownloadRepository::class);
        $repository->method('findExpired')->willReturn($expired);

        return new CourseDocumentDownloadService(
            $storage,
            new WatermarkedPdfBuilder(
                $storage,
                new WatermarkRenderer(
                    new ImageManagerProvider(),
                    '/dev/null',
                ),
                new WatermarkTextBuilder('https://gewis.nl'),
                new SymfonyFilesystem(),
                'https://gewis.nl',
            ),
            $repository,
            $entityManager,
            self::createStub(MessageBusInterface::class),
            self::createStub(SluggerInterface::class),
        );
    }
}
