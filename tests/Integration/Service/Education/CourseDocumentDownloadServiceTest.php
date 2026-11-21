<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Education;

use App\Entity\Education\CourseDocument;
use App\Entity\Education\CourseDocumentDownload;
use App\Entity\Education\Enums\DownloadStatus;
use App\Entity\Education\Exam;
use App\Entity\Education\Summary;
use App\Entity\User\User;
use App\Service\Application\FileStorage;
use App\Service\Education\CourseDocumentDownloadService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function str_ends_with;

/**
 * A download is never built on the request thread: requesting one writes a row and queues the build, and what comes
 * back is served from a file that exists for one minute.
 */
final class CourseDocumentDownloadServiceTest extends DatabaseTestCase
{
    public function testRequestingADownloadCreatesTheRowAndQueuesTheBuild(): void
    {
        $document = $this->document();
        $user = $this->user();

        $download = $this->service()->request(
            $document,
            $user,
            '192.0.2.10',
        );

        self::assertNotNull($download->id);
        self::assertSame(
            $document,
            $download->document,
        );
        self::assertSame(
            $user,
            $download->requestedBy,
        );
        self::assertSame(
            $user->getDisplayName(),
            $download->requestedByName,
        );
        self::assertSame(
            '192.0.2.10',
            $download->requestedFrom,
        );
        self::assertSame(
            DownloadStatus::Pending,
            $download->status,
        );
        self::assertSame(
            $document,
            $this->entityManager->getRepository(CourseDocumentDownload::class)->find($download->id)?->document,
        );
    }

    /**
     * An anonymous request from campus still has to be attributable, so the watermark names the address it came from.
     */
    public function testAnAnonymousRequestIsAttributedToItsAddress(): void
    {
        $download = $this->service()->request(
            $this->document(),
            null,
            '198.51.100.10',
        );

        self::assertNull($download->requestedBy);
        self::assertSame(
            '198.51.100.10',
            $download->requestedByName,
        );
    }

    public function testAnAnonymousRequestWithoutAnAddressStillNamesSomething(): void
    {
        $download = $this->service()->request(
            $this->document(),
            null,
            null,
        );

        self::assertSame(
            'an anonymous visitor',
            $download->requestedByName,
        );
        self::assertSame(
            '',
            $download->requestedFrom,
        );
    }

    public function testABuildThatFailedIsMarkedFailed(): void
    {
        $download = $this->service()->request(
            $this->document(),
            $this->user(),
            '192.0.2.10',
        );

        $this->service()->markFailed($download);

        self::assertSame(
            DownloadStatus::Failed,
            $download->status,
        );
        self::assertNull($download->path);
    }

    public function testCollectingRecordsTheMoment(): void
    {
        $download = $this->service()->request(
            $this->document(),
            $this->user(),
            '192.0.2.10',
        );

        $this->service()->markCollected($download);

        self::assertNotNull($download->collectedAt);
    }

    /**
     * A request that was never collected is dropped after its minute, and so is the file that was built for it.
     */
    public function testExpiredRequestsAndTheirFilesArePurged(): void
    {
        $download = $this->service()->request(
            $this->document(),
            $this->user(),
            '192.0.2.10',
        );
        $download->requestedAt = new DateTimeImmutable('now -5 minutes');
        $download->path = 'education/downloads/expired.pdf';
        $this->entityManager->flush();
        self::getContainer()->get(FileStorage::class)->write(
            'education/downloads/expired.pdf',
            'built',
        );
        $id = (int) $download->id;

        $removed = $this->service()->purgeExpired();

        self::assertGreaterThanOrEqual(
            1,
            $removed,
        );
        self::assertNull($this->entityManager->getRepository(CourseDocumentDownload::class)->find($id));
        self::assertFalse(self::getContainer()->get(FileStorage::class)->exists('education/downloads/expired.pdf'));
    }

    /**
     * A request that is still within its minute is left for the page that is waiting on it.
     */
    public function testARequestThatIsStillFreshIsKept(): void
    {
        $download = $this->service()->request(
            $this->document(),
            $this->user(),
            '192.0.2.10',
        );
        $id = (int) $download->id;

        $this->service()->purgeExpired();

        self::assertNotNull($this->entityManager->getRepository(CourseDocumentDownload::class)->find($id));
    }

    public function testTheFilenameOfASummaryNamesTheAuthor(): void
    {
        $summary = $this->entityManager->getRepository(Summary::class)->findOneBy([]);
        if (null === $summary) {
            self::markTestSkipped('The seed contains no summary.');
        }

        $summary->author = 'Ada Lovelace';

        $filename = $this->service()->filenameFor($summary);

        self::assertStringStartsWith(
            $summary->course->code . '-',
            $filename,
        );
        self::assertStringContainsString(
            'summary',
            $filename,
        );
        self::assertTrue(str_ends_with($filename, $summary->date->format('Y-m-d') . '.pdf'));
    }

    public function testTheFilenameOfAnExamNamesItsType(): void
    {
        $repository = $this->entityManager->getRepository(Exam::class);
        $exam = $repository->findOneBy([]);
        self::assertInstanceOf(
            Exam::class,
            $exam,
        );

        $filename = $this->service()->filenameFor($exam);

        self::assertSame(
            $exam->course->code . '-' . $exam->examType->value . '-' . $exam->date->format('Y-m-d') . '.pdf',
            $filename,
        );
    }

    private function service(): CourseDocumentDownloadService
    {
        return self::getContainer()->get(CourseDocumentDownloadService::class);
    }

    private function document(): CourseDocument
    {
        $document = $this->entityManager->getRepository(CourseDocument::class)->findOneBy([]);
        self::assertInstanceOf(
            CourseDocument::class,
            $document,
            'The seed is expected to contain a course document.',
        );

        return $document;
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
