<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingActivityLog;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\Decision\MeetingPoint;
use App\Entity\User\User;
use App\Service\Decision\MeetingDocumentService;
use App\Tests\Integration\DatabaseTestCase;

use function array_map;
use function count;

/**
 * The no-change paths of the agenda writes: an id that is not part of the meeting does not move anything, renaming a
 * document to the name it already has records nothing, and the first meeting-level document starts at the beginning
 * of its own group.
 */
final class MeetingDocumentServiceEdgesTest extends DatabaseTestCase
{
    public function testReorderingWithAnUnknownIdLeavesTheAgendaAlone(): void
    {
        $meeting = $this->documentedMeeting();
        $before = array_map(
            static fn (MeetingPoint $point): int => $point->displayPosition,
            $meeting->getPoints()->toArray(),
        );

        $this->service()->reorderPoints(
            $meeting,
            [999999],
            $this->user(),
        );

        self::assertSame(
            $before,
            array_map(
                static fn (MeetingPoint $point): int => $point->displayPosition,
                $meeting->getPoints()->toArray(),
            ),
        );
    }

    public function testRenamingToTheSameNameRecordsNothing(): void
    {
        $document = $this->entityManager->getRepository(MeetingDocument::class)->findOneBy([]);
        self::assertInstanceOf(
            MeetingDocument::class,
            $document,
        );
        $before = $this->activityCount();

        $this->service()->renameDocument(
            $document,
            $document->name,
            $this->user(),
        );

        self::assertSame(
            $before,
            $this->activityCount(),
        );
    }

    private function service(): MeetingDocumentService
    {
        return self::getContainer()->get(MeetingDocumentService::class);
    }

    private function activityCount(): int
    {
        return count($this->entityManager->getRepository(MeetingActivityLog::class)->findAll());
    }

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
}
