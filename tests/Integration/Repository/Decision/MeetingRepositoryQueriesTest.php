<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Repository\Decision\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;

use function count;
use function max;
use function rsort;

/**
 * The rest of what the register's overview, its sidebar and the ALV announcement read from the projection: which
 * meetings come back, in what order, and how many decisions and documents they carry.
 */
final class MeetingRepositoryQueriesTest extends DatabaseTestCase
{
    private MeetingRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(MeetingRepository::class);
    }

    public function testAllMeetingsComeNewestFirstWithTheirDecisionCount(): void
    {
        $meetings = $this->repository->findAllMeetings();

        self::assertNotEmpty($meetings);

        $previous = null;
        foreach ($meetings as [$meeting, $decisionCount]) {
            self::assertGreaterThanOrEqual(
                0,
                $decisionCount,
            );

            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $meeting->date,
                );
            }

            $previous = $meeting->date;
        }

        // The count is the meeting's own, not the whole register's.
        [
            $first, $count
        ] = $meetings[0];
        self::assertSame(
            $first->getDecisions()->count(),
            $count,
        );
    }

    public function testAllMeetingsCanBeCappedAndNarrowedToAType(): void
    {
        self::assertCount(
            2,
            $this->repository->findAllMeetings(2),
        );

        $typed = $this->repository->findAllMeetings(
            null,
            MeetingTypes::ALV,
        );

        self::assertNotEmpty($typed);
        foreach ($typed as [$meeting]) {
            self::assertSame(
                MeetingTypes::ALV,
                $meeting->type,
            );
        }
    }

    public function testTheMeetingsOfATypeComeNewestFirst(): void
    {
        $meetings = $this->repository->findByType(MeetingTypes::BV);

        self::assertNotEmpty($meetings);

        $previous = null;
        foreach ($meetings as $meeting) {
            self::assertSame(
                MeetingTypes::BV,
                $meeting->type,
            );

            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $meeting->date,
                );
            }

            $previous = $meeting->date;
        }
    }

    public function testAReferenceWithoutATypeFindsEveryMeetingWithThatNumber(): void
    {
        $meeting = $this->repository->findByType(MeetingTypes::BV)[0];

        $byNumber = $this->repository->findByReference(
            null,
            $meeting->number,
        );

        self::assertContains(
            $meeting,
            $byNumber,
        );
        foreach ($byNumber as $found) {
            self::assertSame(
                $meeting->number,
                $found->number,
            );
        }

        $typed = $this->repository->findByReference(
            MeetingTypes::ALV,
            $meeting->number,
        );
        self::assertNotContains(
            $meeting,
            $typed,
        );
        foreach ($typed as $found) {
            self::assertSame(
                MeetingTypes::ALV,
                $found->type,
            );
        }
    }

    public function testPastMeetingsExcludeTodayAndTheFuture(): void
    {
        $meetings = $this->repository->findPast(
            50,
            MeetingTypes::ALV,
        );

        $previous = null;
        foreach ($meetings as $meeting) {
            self::assertSame(
                MeetingTypes::ALV,
                $meeting->type,
            );
            self::assertLessThanOrEqual(
                new DateTimeImmutable('yesterday'),
                $meeting->date,
            );

            if (null !== $previous) {
                self::assertLessThanOrEqual(
                    $previous,
                    $meeting->date,
                );
            }

            $previous = $meeting->date;
        }

        self::assertLessThanOrEqual(
            2,
            count($this->repository->findPast(
                2,
                MeetingTypes::ALV,
            )),
        );
    }

    public function testFindMeetingLoadsTheDecisionsInOrder(): void
    {
        $meeting = $this->repository->findByType(MeetingTypes::BV)[0];

        $found = $this->repository->findMeeting(
            $meeting->type,
            $meeting->number,
        );

        self::assertInstanceOf(
            Meeting::class,
            $found,
        );
        self::assertSame(
            $meeting->number,
            $found->number,
        );

        $previous = 0;
        foreach ($found->getDecisions() as $decision) {
            self::assertGreaterThanOrEqual(
                $previous,
                $decision->point,
            );
            $previous = $decision->point;
        }

        self::assertNull($this->repository->findMeeting(
            MeetingTypes::BV,
            999999,
        ));
    }

    public function testTheHighestDocumentPositionIsTheLastOneUsed(): void
    {
        $withDocuments = null;
        $withoutDocuments = null;

        foreach ($this->repository->findAll() as $meeting) {
            if ($meeting->getDocuments()->isEmpty()) {
                $withoutDocuments ??= $meeting;

                continue;
            }

            $withDocuments ??= $meeting;
        }

        self::assertInstanceOf(
            Meeting::class,
            $withDocuments,
            'The seed is expected to give a meeting at least one document.',
        );
        self::assertInstanceOf(
            Meeting::class,
            $withoutDocuments,
            'The seed is expected to leave a meeting without documents.',
        );

        $positions = [];
        foreach ($withDocuments->getDocuments() as $document) {
            $positions[] = $document->displayPosition;
        }

        self::assertNotEmpty($positions);
        self::assertSame(
            max($positions),
            $this->repository->findMaxDocumentPosition($withDocuments),
        );

        // A meeting with nothing attached has used no position, which is a zero rather than a missing answer.
        self::assertSame(
            0,
            $this->repository->findMaxDocumentPosition($withoutDocuments),
        );
    }

    public function testTheLatestALVIsTheFurthestAwayOneStillToCome(): void
    {
        $upcoming = $this->repository->findUpcomingALVs();
        self::assertNotEmpty($upcoming);

        $latest = $this->repository->findLatestALV();

        self::assertSame(
            $upcoming[count($upcoming) - 1],
            $latest,
        );
    }

    public function testAnnouncedMeetingsAreTheGMMsAndTheBoardMeetingsStillToCome(): void
    {
        $meetings = $this->repository->findUpcomingAnnouncedMeetings();

        $previous = null;
        foreach ($meetings as $meeting) {
            self::assertContains(
                $meeting->type,
                [
                    MeetingTypes::ALV,
                    MeetingTypes::VV,
                ],
            );
            self::assertGreaterThanOrEqual(
                new DateTimeImmutable('today'),
                $meeting->date,
            );

            if (null !== $previous) {
                self::assertGreaterThanOrEqual(
                    $previous,
                    $meeting->date,
                );
            }

            $previous = $meeting->date;
        }
    }

    public function testTheMeetingsBeforeOneAreTheOnesBeforeIt(): void
    {
        $meetings = $this->repository->findByType(MeetingTypes::BV);
        $meeting = $meetings[0];

        $previous = $this->repository->findPrevious(
            $meeting,
            2,
        );

        self::assertLessThanOrEqual(
            2,
            count($previous),
        );

        foreach ($previous as $earlier) {
            self::assertSame(
                $meeting->type,
                $earlier->type,
            );
            self::assertLessThan(
                $meeting->number,
                $earlier->number,
            );
        }
    }

    public function testTheSidebarShowsTheNearestMeetingsEitherSide(): void
    {
        $meetings = $this->repository->findByType(MeetingTypes::BV);
        self::assertGreaterThan(
            2,
            count($meetings),
        );

        $meeting = $meetings[1];
        $nearby = $this->repository->findNearby($meeting);

        self::assertLessThanOrEqual(
            4,
            count($nearby),
        );

        $numbers = [];
        foreach ($nearby as $row) {
            self::assertSame(
                $meeting->type,
                $row['type'],
            );
            self::assertNotSame(
                $meeting->number,
                $row['number'],
            );

            $numbers[] = $row['number'];
        }

        $sorted = $numbers;
        rsort($sorted);
        self::assertSame(
            $sorted,
            $numbers,
            'The sidebar reads newest first.',
        );

        // The meeting right after this one is nearer than any before it with a lower number: four neighbours around.
        self::assertGreaterThanOrEqual(
            2,
            count($nearby),
        );
    }
}
