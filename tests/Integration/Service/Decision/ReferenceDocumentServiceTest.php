<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingReferenceSelection;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\Decision\ReferenceDocumentVersion;
use App\Entity\User\User;
use App\Service\Application\FileStorage;
use App\Service\Application\FileStorageException;
use App\Service\Decision\ReferenceDocumentService;
use App\Tests\Integration\DatabaseTestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function array_map;
use function count;
use function file_put_contents;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The association-wide reference library, and the selections through which each meeting pins the version it was held
 * against. A selection never follows a newer library version on its own.
 */
final class ReferenceDocumentServiceTest extends DatabaseTestCase
{
    public function testADocumentIsCreatedWithItsFirstVersion(): void
    {
        $document = $this->service()->createDocument(
            'Rules of procedure',
            $this->pdfFile('Rules of procedure v1.0'),
            'v1.0',
            $this->user(),
        );

        self::assertNotNull($document->id);
        self::assertSame(
            'Rules of procedure',
            $document->name,
        );

        $version = $document->getLatestVersion();
        self::assertInstanceOf(
            ReferenceDocumentVersion::class,
            $version,
        );
        self::assertSame(
            'v1.0',
            $version->versionLabel,
        );
        self::assertTrue(str_contains($version->path, 'meetings/reference/'));
        self::assertTrue($this->storage()->exists($version->path));
        self::assertSame(
            $this->user()->lidnr,
            $version->uploadedBy?->lidnr,
        );
    }

    public function testAnInvalidDocumentIsRefusedAndLeavesNothingBehind(): void
    {
        $before = $this->documentCount();

        try {
            $this->service()->createDocument(
                'Refused',
                $this->textFile(),
                'v1.0',
                $this->user(),
            );
            self::fail('A text file is not accepted as a reference document.');
        } catch (FileStorageException) {
            // Expected: the namespace only accepts PDFs.
        }

        // The refused call left a document scheduled; discarding it shows the count is unchanged because nothing was
        // written rather than because the query ran before the insert.
        $this->entityManager->clear();

        self::assertSame(
            $before,
            $this->documentCount(),
        );
    }

    public function testANewVersionIsAppendedToTheExistingOnes(): void
    {
        $document = $this->document('Financial Definition List');
        $documentId = $document->id;
        $before = $this->versionLabels($documentId);

        $version = $this->service()->uploadVersion(
            $document,
            $this->pdfFile('Financial Definition List v1.1'),
            'v1.1',
            $this->user(),
        );

        self::assertTrue($this->storage()->exists($version->path));
        self::assertSame(
            [
                ...$before,
                'v1.1',
            ],
            $this->versionLabels($documentId),
        );
    }

    public function testRenamingADocumentPersists(): void
    {
        $document = $this->document('Financial Definition List');

        $this->service()->renameDocument(
            $document,
            'Financial terms',
            $this->user(),
        );

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            ReferenceDocument::class,
            $document->id,
        );
        self::assertInstanceOf(
            ReferenceDocument::class,
            $reloaded,
        );
        self::assertSame(
            'Financial terms',
            $reloaded->name,
        );
    }

    /**
     * Removing a document a meeting still selects would change what that meeting was held against.
     */
    public function testADocumentThatAMeetingSelectsCannotBeDeleted(): void
    {
        $document = $this->document('Scenarios and Procedures');
        $versionCount = count($document->getVersions());

        try {
            $this->service()->deleteDocument(
                $document,
                $this->user(),
            );
            self::fail('A selected document must not be deleted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'used by 3 meetings',
                $exception->getMessage(),
            );
        }

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            ReferenceDocument::class,
            $document->id,
        );
        self::assertInstanceOf(
            ReferenceDocument::class,
            $reloaded,
        );
        self::assertCount(
            $versionCount,
            $reloaded->getVersions(),
        );
    }

    public function testDeletingAnUnusedDocumentRemovesItsVersionsAndFiles(): void
    {
        $document = $this->service()->createDocument(
            'Temporary',
            $this->pdfFile('Temporary v1'),
            'v1',
            $this->user(),
        );
        $documentId = $document->id;
        self::assertNotNull($documentId);

        $version = $document->getLatestVersion();
        self::assertInstanceOf(
            ReferenceDocumentVersion::class,
            $version,
        );
        $versionId = $version->id;
        $path = $version->path;
        self::assertTrue($this->storage()->exists($path));

        $this->service()->deleteDocument(
            $document,
            $this->user(),
        );

        self::assertFalse($this->storage()->exists($path));
        self::assertNull($this->entityManager->find(
            ReferenceDocument::class,
            $documentId,
        ));
        self::assertNull($this->entityManager->find(
            ReferenceDocumentVersion::class,
            $versionId,
        ));
    }

    public function testTogglingSelectsTheLatestVersionAndTogglingAgainDeselects(): void
    {
        $meeting = $this->meeting(216);
        $document = $this->document('Financial Definition List');

        $this->service()->toggleSelection(
            $meeting,
            $document,
            $this->user(),
        );

        $selection = $this->selection(
            $meeting,
            $document,
        );
        self::assertInstanceOf(
            MeetingReferenceSelection::class,
            $selection,
        );
        self::assertSame(
            $document->getLatestVersion()?->id,
            $selection->pinnedVersion->id,
        );

        $this->service()->toggleSelection(
            $meeting,
            $document,
            $this->user(),
        );

        self::assertNull($this->selection(
            $meeting,
            $document,
        ));
    }

    public function testADocumentWithoutVersionsCannotBeSelected(): void
    {
        $document = new ReferenceDocument();
        $document->name = 'Empty';
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $meeting = $this->meeting(216);

        $this->service()->toggleSelection(
            $meeting,
            $document,
            $this->user(),
        );

        self::assertNull($this->selection(
            $meeting,
            $document,
        ));
    }

    /**
     * A selection pins the version members see; a newer library version changes nothing until the board repins.
     */
    public function testPinningSwitchesTheSelectedVersion(): void
    {
        $meeting = $this->meeting(213);
        $document = $this->document('Scenarios and Procedures');
        $versions = $this->versions($document);
        self::assertCount(
            2,
            $versions,
        );

        $selection = $this->selection(
            $meeting,
            $document,
        );
        self::assertInstanceOf(
            MeetingReferenceSelection::class,
            $selection,
        );
        self::assertSame(
            $versions[0]->id,
            $selection->pinnedVersion->id,
        );

        $this->service()->pinVersion(
            $meeting,
            $document,
            $versions[1],
            $this->user(),
        );

        $this->entityManager->clear();

        $selection = $this->selection(
            $this->meeting(213),
            $this->document('Scenarios and Procedures'),
        );
        self::assertInstanceOf(
            MeetingReferenceSelection::class,
            $selection,
        );
        self::assertSame(
            $versions[1]->id,
            $selection->pinnedVersion->id,
        );
    }

    public function testPinningWithoutASelectionDoesNothing(): void
    {
        $meeting = $this->meeting(216);
        $document = $this->document('Scenarios and Procedures');

        $this->service()->pinVersion(
            $meeting,
            $document,
            $this->versions($document)[0],
            $this->user(),
        );

        self::assertNull($this->selection(
            $meeting,
            $document,
        ));
    }

    public function testPinningAVersionOfAnotherDocumentDoesNothing(): void
    {
        $meeting = $this->meeting(213);
        $selected = $this->document('Scenarios and Procedures');
        $other = $this->document('Financial Definition List');
        $before = $this->selection(
            $meeting,
            $selected,
        );
        self::assertInstanceOf(
            MeetingReferenceSelection::class,
            $before,
        );
        $pinnedId = $before->pinnedVersion->id;

        $this->service()->pinVersion(
            $meeting,
            $selected,
            $this->versions($other)[0],
            $this->user(),
        );

        $after = $this->selection(
            $meeting,
            $selected,
        );
        self::assertInstanceOf(
            MeetingReferenceSelection::class,
            $after,
        );
        self::assertSame(
            $pinnedId,
            $after->pinnedVersion->id,
        );
    }

    public function testCarryingOverCopiesThePreviousMeetingsSelectionsIncludingTheirPins(): void
    {
        $source = $this->meeting(215);
        $target = $this->meeting(216);
        self::assertCount(
            0,
            $this->selections($target),
        );

        $copied = $this->service()->carryOverSelection(
            $target,
            $this->user(),
        );

        $sourceSelections = $this->selections($source);
        self::assertSame(
            count($sourceSelections),
            $copied,
        );
        self::assertNotSame(
            0,
            $copied,
        );

        $pins = [];
        foreach ($sourceSelections as $selection) {
            $pins[(int) $selection->referenceDocument->id] = $selection->pinnedVersion->id;
        }

        $targetSelections = $this->selections($target);
        self::assertCount(
            $copied,
            $targetSelections,
        );
        foreach ($targetSelections as $selection) {
            self::assertSame(
                $pins[(int) $selection->referenceDocument->id],
                $selection->pinnedVersion->id,
            );
        }
    }

    public function testCarryingOverKeepsTheSelectionsTheMeetingAlreadyHas(): void
    {
        $target = $this->meeting(215);
        $before = $this->selections($target);
        self::assertNotCount(
            0,
            $before,
        );

        self::assertSame(
            0,
            $this->service()->carryOverSelection(
                $target,
                $this->user(),
            ),
        );
        self::assertCount(
            count($before),
            $this->selections($target),
        );
    }

    /**
     * @return list<MeetingReferenceSelection>
     */
    private function selections(Meeting $meeting): array
    {
        return $this->entityManager->getRepository(MeetingReferenceSelection::class)->findBy(
            ['meeting' => $meeting],
            ['id' => 'ASC'],
        );
    }

    private function selection(
        Meeting $meeting,
        ReferenceDocument $document,
    ): ?MeetingReferenceSelection {
        return $this->entityManager->getRepository(MeetingReferenceSelection::class)->findOneBy([
            'meeting' => $meeting,
            'referenceDocument' => $document,
        ]);
    }

    /**
     * @return list<ReferenceDocumentVersion>
     */
    private function versions(ReferenceDocument $document): array
    {
        $versions = $this->entityManager->getRepository(ReferenceDocumentVersion::class);

        return $versions->findBy(
            ['referenceDocument' => $document],
            ['id' => 'ASC'],
        );
    }

    /**
     * @return list<string>
     */
    private function versionLabels(?int $documentId): array
    {
        $versions = $this->entityManager->getRepository(ReferenceDocumentVersion::class);

        return array_map(
            static fn (ReferenceDocumentVersion $version): string => $version->versionLabel,
            $versions->findBy(
                ['referenceDocument' => $documentId],
                ['id' => 'ASC'],
            ),
        );
    }

    private function documentCount(): int
    {
        return count($this->entityManager->getRepository(ReferenceDocument::class)->findAll());
    }

    private function document(string $name): ReferenceDocument
    {
        $document = $this->entityManager->getRepository(ReferenceDocument::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(
            ReferenceDocument::class,
            $document,
        );

        return $document;
    }

    private function meeting(int $number): Meeting
    {
        $meeting = $this->entityManager->find(
            Meeting::class,
            [
                'type' => MeetingTypes::ALV,
                'number' => $number,
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
            'reference.pdf',
            'application/pdf',
        );
    }

    private function textFile(): UploadedFile
    {
        return $this->uploadedFile(
            'This is not a reference document.',
            'reference.txt',
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
            'gewisweb-reference-document-test',
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

    private function service(): ReferenceDocumentService
    {
        return self::getContainer()->get(ReferenceDocumentService::class);
    }
}
