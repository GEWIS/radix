<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Education;

use App\Entity\Education\CourseDocumentStaging;
use App\Entity\Education\Enums\CourseDocumentTypes;
use App\Entity\Education\Exam;
use App\Entity\Education\Summary;
use App\Entity\User\User;
use App\Service\Application\FileStorage;
use App\Service\Education\DocumentStagingService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_diff;
use function array_values;
use function count;
use function file_put_contents;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * An uploaded exam is not visible to a member before an administrator has confirmed the guesses made from its
 * filename. Staging keeps it, publishing turns it into a document under the course it names, and discarding drops it.
 */
final class DocumentStagingServiceTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testStagingStoresTheFileAndWhatItsFilenameSays(): void
    {
        $staged = $this->service()->stage(
            $this->upload('2IL50-2024-06-15-exam.pdf'),
            $this->user(),
        );

        self::assertNotNull($staged->id);
        self::assertSame(
            '2IL50-2024-06-15-exam.pdf',
            $staged->originalFilename,
        );
        self::assertSame(
            '2IL50',
            $staged->courseCode,
        );
        self::assertSame(
            CourseDocumentTypes::Exam,
            $staged->type,
        );
        self::assertSame(
            '2024-06-15',
            $staged->date?->format('Y-m-d'),
        );
        self::assertSame(
            $this->user(),
            $staged->uploadedBy,
        );
        self::assertTrue(self::getContainer()->get(FileStorage::class)->exists($staged->path));
    }

    public function testStagingWithoutAUserStillWorks(): void
    {
        $staged = $this->service()->stage(
            $this->upload('2IL50-2024-06-15-exam.pdf'),
            null,
        );

        self::assertNull($staged->uploadedBy);
        self::assertNotNull($staged->id);
    }

    public function testPublishingAnExamFilesItUnderTheCourseAndDropsTheStagedCopy(): void
    {
        $staged = $this->service()->stage(
            $this->upload('2IL50-2024-06-15-exam.pdf'),
            $this->user(),
        );
        $stagedPath = $staged->path;
        $stagedId = (int) $staged->id;
        $before = $this->pathsOf(Exam::class);

        $this->service()->publish($staged);

        self::assertNull(
            $this->entityManager->getRepository(CourseDocumentStaging::class)->find($stagedId),
        );
        // The file was re-filed under the course, so the staged path is gone.
        self::assertFalse(self::getContainer()->get(FileStorage::class)->exists($stagedPath));

        $created = array_values(array_diff($this->pathsOf(Exam::class), $before));
        self::assertCount(
            1,
            $created,
        );
        $repository = $this->entityManager->getRepository(Exam::class);
        $exam = $repository->findOneBy(['path' => $created[0]]);
        self::assertInstanceOf(
            Exam::class,
            $exam,
        );
        self::assertSame(
            '2IL50',
            $exam->course->code,
        );
    }

    public function testPublishingASummaryKeepsItsAuthor(): void
    {
        $staged = $this->service()->stage(
            $this->upload('2IL50-summary-ada.pdf'),
            $this->user(),
        );
        self::assertSame(
            CourseDocumentTypes::Summary,
            $staged->type,
        );
        $staged->author = 'Ada';
        $this->entityManager->flush();
        $before = $this->pathsOf(Summary::class);

        $this->service()->publish($staged);

        $created = array_values(array_diff($this->pathsOf(Summary::class), $before));
        self::assertCount(
            1,
            $created,
        );
        $summary = $this->entityManager->getRepository(Summary::class)->findOneBy(['path' => $created[0]]);
        self::assertInstanceOf(
            Summary::class,
            $summary,
        );
        self::assertSame(
            'Ada',
            $summary->author,
        );
    }

    public function testAUploadWithoutAKnownCourseCannotBePublished(): void
    {
        $staged = new CourseDocumentStaging();
        $staged->originalFilename = 'unknown.pdf';
        $staged->path = 'education/documents/staging/unknown.pdf';
        $staged->uploadedAt = new DateTimeImmutable();
        $staged->courseCode = null;
        $this->entityManager->persist($staged);
        $this->entityManager->flush();

        $this->expectException(RuntimeException::class);
        $this->service()->publish($staged);
    }

    public function testDiscardingDropsTheRowAndTheFile(): void
    {
        $staged = $this->service()->stage(
            $this->upload('2IL50-2024-06-15-exam.pdf'),
            $this->user(),
        );
        $path = $staged->path;
        $id = (int) $staged->id;

        $this->service()->discard($staged);

        self::assertNull($this->entityManager->getRepository(CourseDocumentStaging::class)->find($id));
        self::assertFalse(self::getContainer()->get(FileStorage::class)->exists($path));
    }

    /**
     * @param class-string<Exam|Summary> $class
     *
     * @return list<string>
     */
    private function pathsOf(string $class): array
    {
        $paths = [];
        $repository = $this->entityManager->getRepository($class);
        foreach ($repository->findAll() as $document) {
            $paths[] = $document->path;
        }

        return $paths;
    }

    private function service(): DocumentStagingService
    {
        return self::getContainer()->get(DocumentStagingService::class);
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function upload(string $name): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'education-upload-',
        );
        self::assertIsString($path);
        $this->tempFiles[] = $path;
        file_put_contents(
            $path,
            $this->pdfBytes(),
        );

        return new UploadedFile(
            $path,
            $name,
            'application/pdf',
            null,
            true,
        );
    }

    /**
     * A one-page PDF with a real cross-reference table, so its content is a document by any measure.
     */
    private function pdfBytes(): string
    {
        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $startxref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf(
                "%010d 00000 n \n",
                $offset,
            );
        }

        return $pdf . 'trailer<</Size ' . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n"
            . $startxref . "\n%%EOF\n";
    }
}
