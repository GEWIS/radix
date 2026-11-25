<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingActivityLog;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingDocumentVersion;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\Decision\MeetingPoint;
use App\Entity\User\User;
use App\Service\Application\FileStorage;
use App\Service\Application\FileStorageException;
use App\Service\Decision\MeetingDocumentService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function array_map;
use function array_reverse;
use function count;
use function file_put_contents;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The write side of the agenda: points, documents and versions. Every mutation flushes on its own, so what the test
 * reads back is what the management page sees after the inline edit returns.
 */
final class MeetingDocumentServiceTest extends DatabaseTestCase
{
    public function testAPointIsCreatedAtTheEndOfTheAgenda(): void
    {
        $meeting = $this->emptyMeeting();

        $first = $this->service()->createPoint(
            $meeting,
            '1',
            'Opening',
            $this->user(),
        );
        $second = $this->service()->createPoint(
            $meeting,
            '2',
            'Agenda',
            $this->user(),
        );

        self::assertNotNull($first->id);
        // The service counts the collection after the new point has joined it, so the first point lands at 1.
        self::assertSame(
            1,
            $first->displayPosition,
        );
        self::assertSame(
            2,
            $second->displayPosition,
        );
        self::assertSame(
            [
                $first->id,
                $second->id,
            ],
            array_map(
                static fn (MeetingPoint $point): ?int => $point->id,
                $this->entityManager->getRepository(MeetingPoint::class)->findBy(
                    ['meeting' => $meeting],
                    ['displayPosition' => 'ASC'],
                ),
            ),
        );
    }

    public function testUpdatingAPointPersistsItsNumberAndTitle(): void
    {
        $point = $this->point(
            '7a',
            $this->documentedMeeting(),
        );

        $this->service()->updatePoint(
            $point,
            '7c',
            'Budget, revised',
            $this->user(),
        );

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            MeetingPoint::class,
            $point->id,
        );
        self::assertInstanceOf(
            MeetingPoint::class,
            $reloaded,
        );
        self::assertSame(
            '7c',
            $reloaded->number,
        );
        self::assertSame(
            'Budget, revised',
            $reloaded->title,
        );
    }

    public function testUpdatingAPointToTheSameValuesRecordsNothing(): void
    {
        $point = $this->point(
            '7a',
            $this->documentedMeeting(),
        );
        $before = $this->activityCount();

        $this->service()->updatePoint(
            $point,
            $point->number,
            $point->title,
            $this->user(),
        );

        self::assertSame(
            $before,
            $this->activityCount(),
        );
    }

    /**
     * A point's documents are moved to the end of the meeting-level group rather than dropped with the point.
     */
    public function testDeletingAPointMovesItsDocumentsIntoTheMeetingLevelGroup(): void
    {
        $meeting = $this->documentedMeeting();
        $point = $this->point(
            '2',
            $meeting,
        );

        $pointId = $point->id;
        $documentIds = [];
        $paths = [];
        foreach ($point->getDocuments() as $document) {
            $documentIds[] = $document->id;
            $paths[(int) $document->id] = $document->getLatestVersion()?->path;
        }

        self::assertNotCount(
            0,
            $documentIds,
        );

        $this->service()->deletePoint(
            $point,
            $this->user(),
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->entityManager->find(
                MeetingPoint::class,
                $pointId,
            ),
        );

        $expectedPosition = 1;
        foreach ($documentIds as $documentId) {
            $document = $this->entityManager->find(
                MeetingDocument::class,
                $documentId,
            );
            self::assertInstanceOf(
                MeetingDocument::class,
                $document,
            );
            self::assertNull($document->point);
            self::assertSame(
                $expectedPosition,
                $document->displayPosition,
            );
            // The files are not touched by removing the point; the surviving version still points at the same path.
            self::assertSame(
                $paths[(int) $documentId],
                $document->getLatestVersion()?->path,
            );

            $expectedPosition++;
        }
    }

    public function testReorderingPointsAssignsTheGivenOrder(): void
    {
        $meeting = $this->upcomingMeeting();
        $points = [];
        foreach ($meeting->getPoints() as $point) {
            $points[] = $point;
        }

        self::assertCount(
            3,
            $points,
        );

        $ids = array_map(
            static function (MeetingPoint $point): int {
                self::assertNotNull($point->id);

                return $point->id;
            },
            $points,
        );

        $this->service()->reorderPoints(
            $meeting,
            array_reverse($ids),
            $this->user(),
        );

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            Meeting::class,
            [
                'type' => $meeting->type,
                'number' => $meeting->number,
            ],
        );
        self::assertInstanceOf(
            Meeting::class,
            $reloaded,
        );

        $numbers = [];
        foreach ($reloaded->getPoints() as $point) {
            $numbers[] = $point->number;
        }

        self::assertSame(
            [
                '3',
                '2',
                '1',
            ],
            $numbers,
        );
    }

    public function testADocumentIsStoredUnderTheMeetingAndFiledUnderItsPoint(): void
    {
        $meeting = $this->upcomingMeeting();
        $point = $this->point(
            '2',
            $meeting,
        );

        $document = $this->service()->uploadDocument(
            $meeting,
            $point,
            'Minutes draft',
            $this->pdfFile('Minutes draft v1.0'),
            'v1.0',
            $this->user(),
        );

        self::assertNotNull($document->id);
        self::assertSame(
            $point,
            $document->point,
        );
        // The point already carries the seeded agenda, so this one follows it.
        self::assertSame(
            1,
            $document->displayPosition,
        );

        $version = $document->getLatestVersion();
        self::assertInstanceOf(
            MeetingDocumentVersion::class,
            $version,
        );
        self::assertSame(
            'v1.0',
            $version->versionLabel,
        );
        self::assertTrue(str_contains(
            $version->path,
            sprintf(
                'meetings/%s/documents/',
                $meeting->getStorageScope(),
            ),
        ));
        self::assertTrue($this->storage()->exists($version->path));
        self::assertSame(
            $this->user()->lidnr,
            $version->uploadedBy?->lidnr,
        );
    }

    public function testAMeetingLevelDocumentIsPositionedAfterThoseAlreadyThere(): void
    {
        $meeting = $this->documentedMeeting();

        $document = $this->service()->uploadDocument(
            $meeting,
            null,
            'Carried over letter',
            $this->pdfFile('Carried over letter v1'),
            'v1',
            $this->user(),
        );

        self::assertNull($document->point);
        // The seeded legacy document sits at 0 in this group.
        self::assertSame(
            1,
            $document->displayPosition,
        );
    }

    public function testAnInvalidUploadIsRefusedAndLeavesNoDocument(): void
    {
        $meeting = $this->upcomingMeeting();
        $before = $this->documentCount();

        try {
            $this->service()->uploadDocument(
                $meeting,
                null,
                'Notes',
                $this->textFile(),
                'v1',
                $this->user(),
            );
            self::fail('A text file is not accepted as a meeting document.');
        } catch (FileStorageException) {
            // Expected: the namespace only accepts PDFs.
        }

        // The refused document is still scheduled for insertion on the manager; discarding it is what proves nothing
        // was written rather than that the count was taken before the insert.
        $this->entityManager->clear();

        self::assertSame(
            $before,
            $this->documentCount(),
        );
    }

    public function testUploadingAVersionKeepsTheEarlierOnes(): void
    {
        $document = $this->document('Financial report');
        $documentId = $document->id;
        $labelsBefore = $this->versionLabels($documentId);

        $uploaded = $this->service()->uploadVersion(
            $document,
            $this->pdfFile('Financial report v1.1'),
            'v1.1',
            $this->user(),
        );

        self::assertTrue($this->storage()->exists($uploaded->path));

        $this->entityManager->clear();

        self::assertSame(
            [
                ...$labelsBefore,
                'v1.1',
            ],
            $this->versionLabels($documentId),
        );
    }

    public function testAnInvalidVersionUploadIsRefused(): void
    {
        $document = $this->document('Financial report');
        $before = count($document->getVersions());

        try {
            $this->service()->uploadVersion(
                $document,
                $this->textFile(),
                'v1.1',
                $this->user(),
            );
            self::fail('A text file is not accepted as a document version.');
        } catch (FileStorageException) {
            // Expected: the namespace only accepts PDFs.
        }

        $versions = $this->entityManager->getRepository(MeetingDocumentVersion::class);
        self::assertCount(
            $before,
            $versions->findBy(['document' => $document->id]),
        );
    }

    public function testRenamingADocumentPersists(): void
    {
        $document = $this->document('Financial report');

        $this->service()->renameDocument(
            $document,
            'Financial statements',
            $this->user(),
        );

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            MeetingDocument::class,
            $document->id,
        );
        self::assertInstanceOf(
            MeetingDocument::class,
            $reloaded,
        );
        self::assertSame(
            'Financial statements',
            $reloaded->name,
        );
    }

    public function testDeletingADocumentRemovesItsVersionsAndFiles(): void
    {
        $meeting = $this->upcomingMeeting();
        $document = $this->service()->uploadDocument(
            $meeting,
            null,
            'Temporary',
            $this->pdfFile('Temporary v1'),
            'v1',
            $this->user(),
        );
        $documentId = $document->id;
        self::assertNotNull($documentId);

        $version = $document->getLatestVersion();
        self::assertInstanceOf(
            MeetingDocumentVersion::class,
            $version,
        );
        $versionId = $version->id;
        $path = $version->path;
        self::assertTrue($this->storage()->exists($path));

        $this->service()->deleteDocument(
            $document,
            $this->user(),
        );

        self::assertNull($this->entityManager->find(
            MeetingDocument::class,
            $documentId,
        ));
        self::assertNull($this->entityManager->find(
            MeetingDocumentVersion::class,
            $versionId,
        ));
        self::assertFalse($this->storage()->exists($path));
    }

    public function testReorderingDocumentsWithinAPointAssignsTheGivenOrder(): void
    {
        $meeting = $this->documentedMeeting();
        $point = $this->point(
            '2',
            $meeting,
        );

        $ids = [];
        foreach ($point->getDocuments() as $document) {
            self::assertNotNull($document->id);
            $ids[] = $document->id;
        }

        self::assertCount(
            4,
            $ids,
        );

        $this->service()->reorderDocuments(
            $meeting,
            $point,
            array_reverse($ids),
            $this->user(),
        );

        $this->entityManager->clear();

        $documents = $this->entityManager->getRepository(MeetingDocument::class)->findBy(
            ['point' => $point->id],
            ['displayPosition' => 'ASC'],
        );

        $names = array_map(
            static fn (MeetingDocument $document): string => $document->name,
            $documents,
        );

        self::assertSame(
            [
                'Election results',
                'Activity report',
                'Financial report',
                'Agenda',
            ],
            $names,
        );
    }

    public function testReorderingTheMeetingLevelGroupLeavesPointDocumentsAlone(): void
    {
        $meeting = $this->documentedMeeting();
        $point = $this->point(
            '2',
            $meeting,
        );

        $pointIds = [];
        foreach ($point->getDocuments() as $document) {
            self::assertNotNull($document->id);
            $pointIds[] = $document->id;
        }

        $this->service()->reorderDocuments(
            $meeting,
            $point,
            array_reverse($pointIds),
            $this->user(),
        );

        $positionsBefore = $this->positions($pointIds);

        $this->service()->reorderDocuments(
            $meeting,
            null,
            $pointIds,
            $this->user(),
        );

        $this->entityManager->clear();

        self::assertSame(
            $positionsBefore,
            $this->positions($pointIds),
        );
    }

    /**
     * @param list<int|null> $ids
     *
     * @return array<int, int>
     */
    private function positions(array $ids): array
    {
        $positions = [];
        foreach ($ids as $id) {
            $document = $this->entityManager->find(
                MeetingDocument::class,
                $id,
            );
            self::assertInstanceOf(
                MeetingDocument::class,
                $document,
            );
            $positions[(int) $id] = $document->displayPosition;
        }

        return $positions;
    }

    /**
     * @return list<string>
     */
    private function versionLabels(?int $documentId): array
    {
        $labels = [];
        $versions = $this->entityManager->getRepository(MeetingDocumentVersion::class);
        foreach (
            $versions->findBy(
                ['document' => $documentId],
                ['id' => 'ASC'],
            ) as $version
        ) {
            $labels[] = $version->versionLabel;
        }

        return $labels;
    }

    private function documentCount(): int
    {
        return count($this->entityManager->getRepository(MeetingDocument::class)->findAll());
    }

    private function activityCount(): int
    {
        return count($this->entityManager->getRepository(MeetingActivityLog::class)->findAll());
    }

    private function document(string $name): MeetingDocument
    {
        $document = $this->entityManager->getRepository(MeetingDocument::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(
            MeetingDocument::class,
            $document,
        );

        return $document;
    }

    private function point(
        string $number,
        Meeting $meeting,
    ): MeetingPoint {
        $point = $this->entityManager->getRepository(MeetingPoint::class)->findOneBy([
            'meeting' => $meeting,
            'number' => $number,
        ]);
        self::assertInstanceOf(
            MeetingPoint::class,
            $point,
        );

        return $point;
    }

    /**
     * The one past meeting the seed hangs documents and minutes on.
     */
    private function documentedMeeting(): Meeting
    {
        $repository = $this->entityManager->getRepository(MeetingMinutesVersion::class);
        $version = $repository->findOneBy([]);
        self::assertInstanceOf(
            MeetingMinutesVersion::class,
            $version,
        );

        return $version->minutes->meeting;
    }

    /**
     * The upcoming GMM, whose agenda points carry one seeded document.
     */
    private function upcomingMeeting(): Meeting
    {
        $meeting = $this->entityManager->find(
            Meeting::class,
            [
                'type' => MeetingTypes::ALV,
                'number' => 215,
            ],
        );
        self::assertInstanceOf(
            Meeting::class,
            $meeting,
        );

        return $meeting;
    }

    private function emptyMeeting(): Meeting
    {
        $meeting = $this->entityManager->find(
            Meeting::class,
            [
                'type' => MeetingTypes::ALV,
                'number' => 100,
            ],
        );
        self::assertInstanceOf(
            Meeting::class,
            $meeting,
        );

        return $meeting;
    }

    private function user(): User
    {
        $user = $this->entityManager->find(
            User::class,
            8000,
        );
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function pdfFile(string $marker): UploadedFile
    {
        return $this->uploadedFile(
            sprintf(
                "%%PDF-1.4\n%% %s\n%%%%EOF\n",
                $marker,
            ),
            'document.pdf',
            'application/pdf',
        );
    }

    private function textFile(): UploadedFile
    {
        return $this->uploadedFile(
            'This is not a document.',
            'notes.txt',
            'text/plain',
        );
    }

    private function uploadedFile(
        string $contents,
        string $name,
        string $mimeType,
    ): UploadedFile {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-meeting-document-test',
        );
        self::assertIsString($path);
        file_put_contents(
            $path,
            $contents,
        );

        try {
            return new UploadedFile(
                $path,
                $name,
                $mimeType,
                null,
                true,
            );
        } catch (Throwable $exception) {
            unlink($path);

            throw $exception;
        }
    }

    private function storage(): FileStorage
    {
        return self::getContainer()->get(FileStorage::class);
    }

    private function service(): MeetingDocumentService
    {
        return self::getContainer()->get(MeetingDocumentService::class);
    }
}
