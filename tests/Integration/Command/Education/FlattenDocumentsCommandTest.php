<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Education;

use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Education\Course;
use App\Entity\Education\CourseDocument;
use App\Entity\Education\Enums\DocumentFlattenStatus;
use App\Entity\Education\Enums\ExamTypes;
use App\Entity\Education\Exam;
use App\Service\Application\FileStorage;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;
use FPDF;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** The seed only holds Ready documents, so the walk sees exactly what each test creates. */
final class FlattenDocumentsCommandTest extends DatabaseTestCase
{
    public function testFlattensPendingDocumentsAndMarksAnUnreadableOneFailed(): void
    {
        $readable = $this->aPendingDocument();
        $unreadable = $this->aPendingDocument(readable: false);
        $readableId = (int) $readable->getId();
        $unreadableId = (int) $unreadable->getId();

        $this->assertCommandFailed(static::runCommand(
            'app:education:flatten-documents',
            ['--delay' => '0'],
        ));

        // The command clears the identity map between documents; re-find for the asserts.
        $this->entityManager->clear();
        self::assertSame(
            DocumentFlattenStatus::Ready,
            $this->find($readableId)->getFlattenStatus(),
        );
        self::assertSame(
            DocumentFlattenStatus::Failed,
            $this->find($unreadableId)->getFlattenStatus(),
        );
    }

    public function testLimitBoundsTheBatch(): void
    {
        $first = $this->aPendingDocument();
        $second = $this->aPendingDocument();
        $firstId = (int) $first->getId();
        $secondId = (int) $second->getId();

        $this->assertCommandIsSuccessful(static::runCommand(
            'app:education:flatten-documents',
            [
                '--delay' => '0',
                '--limit' => '1',
            ],
        ));

        $this->entityManager->clear();
        // The walk is ordered by id, so the bounded batch is the first document alone.
        self::assertSame(
            DocumentFlattenStatus::Ready,
            $this->find($firstId)->getFlattenStatus(),
        );
        self::assertSame(
            DocumentFlattenStatus::Pending,
            $this->find($secondId)->getFlattenStatus(),
        );
    }

    private function find(int $documentId): CourseDocument
    {
        $document = $this->entityManager->getRepository(CourseDocument::class)->find($documentId);
        self::assertInstanceOf(
            CourseDocument::class,
            $document,
        );

        return $document;
    }

    private function aPendingDocument(bool $readable = true): CourseDocument
    {
        $course = $this->entityManager->getRepository(Course::class)->findOneBy([]);
        self::assertInstanceOf(
            Course::class,
            $course,
        );

        if ($readable) {
            $pdf = new FPDF();
            $pdf->AddPage();
            $contents = $pdf->Output('S');
        } else {
            $contents = "%PDF-1.4\nnot actually a pdf\n%%EOF\n";
        }

        $temporaryFile = (string) tempnam(
            sys_get_temp_dir(),
            'flatten-test-',
        );
        file_put_contents(
            $temporaryFile,
            $contents,
        );

        $document = new Exam();
        $document->setCourse($course);
        $document->setDate(new DateTime('2026-01-15'));
        $document->setLanguage(Languages::English);
        $document->setExamType(ExamTypes::Final);
        $document->setScanned(false);
        $document->setPath(self::getContainer()->get(FileStorage::class)->store(
            StorageNamespace::EducationDocument,
            $temporaryFile,
            $course->getCode(),
        )->path);
        unlink($temporaryFile);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }
}
