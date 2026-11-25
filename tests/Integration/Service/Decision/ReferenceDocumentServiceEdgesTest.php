<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingActivityLog;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\User\User;
use App\Service\Decision\ReferenceDocumentService;
use App\Tests\Integration\DatabaseTestCase;

use function count;

/**
 * The library writes that change nothing: renaming to the name already there, and carrying a selection over from a
 * meeting that has no previous one of its type.
 */
final class ReferenceDocumentServiceEdgesTest extends DatabaseTestCase
{
    public function testRenamingToTheSameNameRecordsNothing(): void
    {
        $document = $this->entityManager->getRepository(ReferenceDocument::class)->findOneBy([]);
        self::assertInstanceOf(
            ReferenceDocument::class,
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

    /**
     * The first virtual meeting has no earlier virtual meeting to copy from, so there is nothing to carry over.
     */
    public function testCarryingOverFromNoPreviousMeetingCopiesNothing(): void
    {
        $meeting = $this->entityManager->find(
            Meeting::class,
            [
                'type' => MeetingTypes::VIRT,
                'number' => 1,
            ],
        );
        self::assertInstanceOf(
            Meeting::class,
            $meeting,
        );
        $before = $this->activityCount();

        self::assertSame(
            0,
            $this->service()->carryOverSelection(
                $meeting,
                $this->user(),
            ),
        );
        self::assertSame(
            $before,
            $this->activityCount(),
        );
    }

    private function service(): ReferenceDocumentService
    {
        return self::getContainer()->get(ReferenceDocumentService::class);
    }

    private function activityCount(): int
    {
        return count($this->entityManager->getRepository(MeetingActivityLog::class)->findAll());
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
