<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Education;

use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Education\Course;
use App\Entity\Education\CourseDocument;
use App\Entity\Education\Enums\DocumentFlattenStatus;
use App\Entity\Education\Enums\ExamTypes;
use App\Entity\Education\Exam;
use App\Message\Education\FlattenCourseDocumentMessage;
use App\MessageHandler\Education\FlattenCourseDocumentHandler;
use App\Repository\Education\CourseDocumentRepository;
use App\Service\Application\FileStorage;
use App\Service\Education\CourseDocumentFlattener;
use App\Service\Education\PdfRasterizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use FPDF;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Flattening is the expensive part of the education pipeline. A document poppler cannot read is an upload problem,
 * so the failure is recorded on the document and the message is not retried.
 */
final class FlattenCourseDocumentHandlerTest extends TestCase
{
    public function testAnUnreadableDocumentIsRecordedAsFailedAndNotRethrown(): void
    {
        $storage = $this->storage();
        $document = $this->document(
            $storage,
            "%PDF-1.4\nthis is not a pdf\n%%EOF\n",
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Could not flatten course document {document}: {reason}',
                self::callback(
                    static fn (array $context): bool => 5 === $context['document']
                        && '' !== $context['reason'],
                ),
            );

        $handler = new FlattenCourseDocumentHandler(
            $this->documents($document),
            $this->flattener($storage),
            $logger,
        );

        // The handler records the failure and returns, so a bad upload does not stop the worker.
        $handler(new FlattenCourseDocumentMessage(5));

        self::assertSame(
            DocumentFlattenStatus::Failed,
            $document->flattenStatus,
        );
        self::assertNotNull($document->flattenError);
        self::assertFalse($document->isDownloadable());
    }

    public function testAReadableDocumentIsFlattenedAndBecomesDownloadable(): void
    {
        $storage = $this->storage();
        $document = $this->document(
            $storage,
            $this->pdf(),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $handler = new FlattenCourseDocumentHandler(
            $this->documents($document),
            $this->flattener($storage),
            $logger,
        );

        $handler(new FlattenCourseDocumentMessage(5));

        self::assertSame(
            DocumentFlattenStatus::Ready,
            $document->flattenStatus,
        );
        self::assertNull($document->flattenError);
        self::assertSame(
            1,
            $document->getPageCount(),
        );
        self::assertTrue($document->isDownloadable());
    }

    public function testADocumentThatWasDeletedIsNotFlattened(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $handler = new FlattenCourseDocumentHandler(
            $this->documents(null),
            new CourseDocumentFlattener(
                new PdfRasterizer(),
                $this->storage(),
                self::createStub(EntityManagerInterface::class),
                new SymfonyFilesystem(),
            ),
            $logger,
        );

        $handler(new FlattenCourseDocumentMessage(404));
    }

    private function flattener(FileStorage $storage): CourseDocumentFlattener
    {
        return new CourseDocumentFlattener(
            new PdfRasterizer(),
            $storage,
            self::createStub(EntityManagerInterface::class),
            new SymfonyFilesystem(),
        );
    }

    private function documents(?CourseDocument $document): CourseDocumentRepository
    {
        $repository = self::createStub(CourseDocumentRepository::class);
        $repository->method('find')->willReturn($document);

        return $repository;
    }

    private function storage(): FileStorage
    {
        return new FileStorage(new Filesystem(new InMemoryFilesystemAdapter()));
    }

    private function document(
        FileStorage $storage,
        string $contents,
    ): CourseDocument {
        $temporaryFile = tempnam(
            sys_get_temp_dir(),
            'education-upload-',
        );
        self::assertIsString($temporaryFile);
        file_put_contents(
            $temporaryFile,
            $contents,
        );

        $course = new Course();
        $course->code = '2PIPE0';
        $course->name = 'Document pipeline';

        $document = new Exam();
        new ReflectionProperty(
            CourseDocument::class,
            'id',
        )->setValue(
            $document,
            5,
        );
        $document->course = $course;
        $document->date = new DateTimeImmutable('2026-01-15');
        $document->language = Languages::English;
        $document->examType = ExamTypes::Final;
        $document->scanned = false;
        $document->path = $storage->store(
            StorageNamespace::EducationDocument,
            $temporaryFile,
            $course->code,
        )->path;

        unlink($temporaryFile);

        return $document;
    }

    private function pdf(): string
    {
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont(
            'Helvetica',
            '',
            14,
        );
        $pdf->Text(
            20,
            40,
            'A readable page.',
        );

        $contents = $pdf->Output('S');
        self::assertIsString($contents);

        return $contents;
    }
}
