<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Education;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Education\Course;
use App\Entity\Education\CourseDocumentDownload;
use App\Entity\Education\CourseDocumentPage;
use App\Entity\Education\Enums\DownloadStatus;
use App\Entity\Education\Enums\ExamTypes;
use App\Entity\Education\Exam;
use App\Message\Education\BuildWatermarkedDocumentMessage;
use App\MessageHandler\Education\BuildWatermarkedDocumentHandler;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

use function dirname;
use function str_starts_with;

/**
 * A download is built from the rasterized pages and stamped with the requester. On failure the request is marked
 * failed before the exception is rethrown, so the page stops waiting even though the message is retried.
 */
final class BuildWatermarkedDocumentHandlerTest extends TestCase
{
    public function testARequestThatExpiredBeforeItWasPickedUpIsNotBuilt(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $handler = new BuildWatermarkedDocumentHandler(
            $this->downloads(null),
            $this->service(
                $this->storage(),
                $entityManager,
            ),
            self::createStub(LoggerInterface::class),
        );

        $handler(new BuildWatermarkedDocumentMessage(404));
    }

    public function testAReadyDownloadIsStoredAndMarkedReady(): void
    {
        $storage = $this->storage();
        $download = $this->download($storage);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        // Once for the path and status, once for the page rows the pipeline would persist in a real run.
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $handler = new BuildWatermarkedDocumentHandler(
            $this->downloads($download),
            $this->service(
                $storage,
                $entityManager,
            ),
            $logger,
        );

        $handler(new BuildWatermarkedDocumentMessage(9));

        self::assertSame(
            DownloadStatus::Ready,
            $download->status,
        );
        self::assertNotNull($download->path);
        self::assertTrue(str_starts_with($download->path, 'education/downloads/'));
        self::assertTrue($storage->exists($download->path));
        self::assertStringStartsWith(
            '%PDF',
            $storage->read($download->path),
        );
    }

    public function testAFailedBuildIsMarkedFailedAndStillRethrown(): void
    {
        $storage = $this->storage();
        $download = $this->download(
            $storage,
            withPages: false,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Could not build watermarked download {download}: {reason}',
                self::callback(
                    static fn (array $context): bool => 9 === $context['download']
                        && '' !== $context['reason'],
                ),
            );

        $handler = new BuildWatermarkedDocumentHandler(
            $this->downloads($download),
            $this->service(
                $storage,
                self::createStub(EntityManagerInterface::class),
            ),
            $logger,
        );

        try {
            $handler(new BuildWatermarkedDocumentMessage(9));
            self::fail('The failure must reach Messenger so the message can be retried and then failed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'no rendered pages',
                $exception->getMessage(),
            );
        }

        // Marked before the rethrow, so the page waiting on the download stops waiting on the first attempt.
        self::assertSame(
            DownloadStatus::Failed,
            $download->status,
        );
        self::assertNull($download->path);
    }

    private function downloads(?CourseDocumentDownload $download): CourseDocumentDownloadRepository
    {
        $repository = self::createStub(CourseDocumentDownloadRepository::class);
        $repository->method('find')->willReturn($download);

        return $repository;
    }

    private function service(
        FileStorage $storage,
        EntityManagerInterface $entityManager,
    ): CourseDocumentDownloadService {
        return new CourseDocumentDownloadService(
            $storage,
            new WatermarkedPdfBuilder(
                $storage,
                new WatermarkRenderer(
                    new ImageManagerProvider(),
                    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                ),
                new WatermarkTextBuilder('gewis.nl'),
                new SymfonyFilesystem(),
                'GEWIS test',
            ),
            self::createStub(CourseDocumentDownloadRepository::class),
            $entityManager,
            self::createStub(MessageBusInterface::class),
            new AsciiSlugger(),
        );
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }

    private function download(
        FileStorage $storage,
        bool $withPages = true,
    ): CourseDocumentDownload {
        $course = new Course();
        $course->code = '2PIPE0';
        $course->name = 'Document pipeline';

        $document = new Exam();
        $document->course = $course;
        $document->date = new DateTimeImmutable('2026-01-15');
        $document->examType = ExamTypes::Final;
        $document->scanned = false;

        if ($withPages) {
            $page = new CourseDocumentPage();
            $page->pageNumber = 1;
            $page->path = $storage->store(
                StorageNamespace::EducationDocumentPage,
                dirname(
                    __DIR__,
                    2,
                ) . '/Resources/images/gala-dinner-1.jpg',
                '1',
            )->path;
            $page->width = 800;
            $page->height = 600;
            $document->addPage($page);
        }

        $download = new CourseDocumentDownload();
        $download->token = Uuid::v4();
        $download->document = $document;
        $download->requestedByName = 'Ada Lovelace';
        $download->requestedFrom = '192.0.2.1';
        $download->requestedAt = new DateTimeImmutable('2026-01-15 12:00:00');

        return $download;
    }
}
