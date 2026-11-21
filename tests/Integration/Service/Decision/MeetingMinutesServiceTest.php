<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingMinutes;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\User\User;
use App\Service\Application\FileStorage;
use App\Service\Application\FileStorageException;
use App\Service\Decision\MeetingMinutesService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function array_map;
use function file_put_contents;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A meeting has at most one set of minutes, with a new version per upload. The file lives in the meeting's own storage
 * directory; deleting the minutes removes the rows first and the files once nothing references them any more.
 */
final class MeetingMinutesServiceTest extends DatabaseTestCase
{
    public function testUploadingMinutesCreatesThemAndStoresTheFile(): void
    {
        $meeting = $this->meeting(215);
        self::assertNull($meeting->getMinutes());

        $version = $this->service()->uploadMinutes(
            $meeting,
            $this->pdfFile('Minutes v1.0'),
            'v1.0',
            $this->user(),
        );

        self::assertNotNull($meeting->getMinutes());
        self::assertSame(
            $meeting,
            $meeting->getMinutes()->meeting,
        );
        self::assertSame(
            'v1.0',
            $version->versionLabel,
        );
        self::assertTrue(str_contains(
            $version->path,
            sprintf(
                'meetings/%s/minutes/',
                $meeting->getStorageScope(),
            ),
        ));
        self::assertTrue($this->storage()->exists($version->path));
        self::assertSame(
            $this->user()->lidnr,
            $version->uploadedBy?->lidnr,
        );
        $minutes = $meeting->getMinutes();
        self::assertNotNull($minutes);
        self::assertSame(
            $version,
            $minutes->getLatestVersion(),
        );
    }

    public function testUploadingAnotherVersionKeepsThePreviousOnes(): void
    {
        $minutes = $this->minutes();
        $meeting = $minutes->meeting;
        $labelsBefore = $this->versionLabels($meeting);

        $version = $this->service()->uploadMinutes(
            $meeting,
            $this->pdfFile('Minutes v1.2'),
            'v1.2',
            $this->user(),
        );

        self::assertTrue($this->storage()->exists($version->path));
        self::assertSame(
            [
                ...$labelsBefore,
                'v1.2',
            ],
            $this->versionLabels($meeting),
        );
        self::assertSame(
            $version->id,
            $minutes->getLatestVersion()?->id,
        );
    }

    public function testAnInvalidUploadIsRefusedAndLeavesNoMinutes(): void
    {
        $meeting = $this->meeting(216);
        self::assertNull($meeting->getMinutes());

        try {
            $this->service()->uploadMinutes(
                $meeting,
                $this->textFile(),
                'v1.0',
                $this->user(),
            );
            self::fail('A text file is not accepted as minutes.');
        } catch (FileStorageException) {
            // Expected: the namespace only accepts PDFs.
        }

        // The refused call left a new MeetingMinutes scheduled; discarding it is what shows none was written.
        $this->entityManager->clear();

        $reloaded = $this->meeting(216);
        self::assertNull($reloaded->getMinutes());
    }

    public function testDeletingMinutesRemovesTheFileAndTheReference(): void
    {
        $meeting = $this->meeting(215);
        $version = $this->service()->uploadMinutes(
            $meeting,
            $this->pdfFile('Minutes to delete'),
            'v1.0',
            $this->user(),
        );
        self::assertTrue($this->storage()->exists($version->path));

        $this->service()->deleteMinutes(
            $meeting,
            $this->user(),
        );

        self::assertNull($meeting->getMinutes());
        self::assertFalse($this->storage()->exists($version->path));

        $this->entityManager->clear();

        self::assertNull($this->meeting(215)->getMinutes());
    }

    public function testDeletingMinutesThatDoNotExistIsSafe(): void
    {
        $meeting = $this->meeting(216);
        self::assertNull($meeting->getMinutes());

        $this->service()->deleteMinutes(
            $meeting,
            $this->user(),
        );

        self::assertNull($meeting->getMinutes());
        self::assertNull($this->meeting(216)->getMinutes());
    }

    /**
     * @return list<string>
     */
    private function versionLabels(Meeting $meeting): array
    {
        $minutes = $meeting->getMinutes();
        self::assertInstanceOf(
            MeetingMinutes::class,
            $minutes,
        );

        $versions = $this->entityManager->getRepository(MeetingMinutesVersion::class);

        return array_map(
            static fn (MeetingMinutesVersion $version): string => $version->versionLabel,
            $versions->findBy(
                ['minutes' => $minutes],
                ['id' => 'ASC'],
            ),
        );
    }

    private function minutes(): MeetingMinutes
    {
        $minutes = $this->entityManager->getRepository(MeetingMinutes::class)->findOneBy([]);
        self::assertInstanceOf(
            MeetingMinutes::class,
            $minutes,
        );

        return $minutes;
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
            'minutes.pdf',
            'application/pdf',
        );
    }

    private function textFile(): UploadedFile
    {
        return $this->uploadedFile(
            'This is not a set of minutes.',
            'minutes.txt',
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
            'gewisweb-meeting-minutes-test',
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

    private function service(): MeetingMinutesService
    {
        return self::getContainer()->get(MeetingMinutesService::class);
    }
}
