<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\DataFixtures\Database\MeetingScheduleFixture;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Repository\Database\MeetingRepository;
use App\Service\Database\Meeting as MeetingService;
use App\Tests\Integration\DatabaseTestCase;
use App\ViewModel\Database\MeetingNumberSuggestion;
use DateTimeImmutable;

use function array_map;

final class MeetingNumberSuggestionTest extends DatabaseTestCase
{
    public function testTheNextNumberFollowsTheHighestOneOnRecord(): void
    {
        $suggestion = $this->suggestionFor(MeetingTypes::BV);
        $latest = $this->meetingRepository()->findOneBy(
            ['type' => MeetingTypes::BV],
            ['number' => 'DESC'],
        );

        self::assertNotNull($latest);
        self::assertGreaterThan(
            MeetingScheduleFixture::FIRST_BM_NUMBER,
            $latest->getNumber(),
        );
        self::assertSame(
            $latest->getNumber(),
            $suggestion->latestNumber,
        );
        self::assertSame(
            $latest->getNumber() + 1,
            $suggestion->next(),
        );
        self::assertEquals(
            $latest->date,
            $suggestion->latestDate,
        );
        self::assertSame(
            [],
            $suggestion->missingNumbers,
        );
    }

    public function testNumbersSkippedBelowTheHighestOneAreReported(): void
    {
        $before = $this->suggestionFor(MeetingTypes::BV);
        $next = $before->next();
        $skipped = [
            $next,
            $next + 1,
        ];

        $meeting = new Meeting();
        $meeting->type = MeetingTypes::BV;
        $meeting->setNumber($next + 2);
        $meeting->date = new DateTimeImmutable('today');
        $this->meetingRepository()->persist($meeting);

        $after = $this->suggestionFor(MeetingTypes::BV);

        self::assertSame(
            $meeting->getNumber(),
            $after->latestNumber,
        );
        self::assertSame(
            $meeting->getNumber() + 1,
            $after->next(),
        );
        self::assertSame(
            $skipped,
            $after->missingNumbers,
        );
    }

    public function testTheHistoryFurtherBackIsNotReported(): void
    {
        $suggestion = $this->suggestionFor(MeetingTypes::BV);

        self::assertNotNull($suggestion->latestNumber);
        self::assertNotEmpty(
            $this->meetingRepository()->findBy([
                'type' => MeetingTypes::BV,
                'number' => 1,
            ]),
        );
        self::assertSame(
            [],
            $suggestion->missingNumbers,
        );
    }

    public function testEveryTypeGetsASuggestionInTheOrderTheTypesAreListed(): void
    {
        $suggestions = self::getContainer()->get(MeetingService::class)->getMeetingNumberSuggestions();

        self::assertSame(
            MeetingTypes::cases(),
            array_map(
                static fn (MeetingNumberSuggestion $suggestion): MeetingTypes => $suggestion->type,
                $suggestions,
            ),
        );
    }

    private function meetingRepository(): MeetingRepository
    {
        return self::getContainer()->get(MeetingRepository::class);
    }

    private function suggestionFor(MeetingTypes $type): MeetingNumberSuggestion
    {
        foreach (self::getContainer()->get(MeetingService::class)->getMeetingNumberSuggestions() as $suggestion) {
            if ($suggestion->type === $type) {
                return $suggestion;
            }
        }

        self::fail('Every type is expected to get a suggestion.');
    }
}
