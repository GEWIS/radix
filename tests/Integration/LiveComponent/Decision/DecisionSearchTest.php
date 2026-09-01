<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\DecisionSearch;

use function array_map;

/**
 * Exercises the decision search component as the framework does, with its real repositories behind it.
 */
final class DecisionSearchTest extends DatabaseTestCase
{
    /**
     * A prompt naming a meeting is asking for that meeting. It used to be answered with the decisions elsewhere that
     * mention it and nothing else, which is no answer at all when the meeting took no decisions of its own.
     */
    public function testTheMeetingAskedForIsShownEvenWhenNothingInItMatches(): void
    {
        $groups = $this->searchFor('BV 1815')->getResultsByMeeting();

        self::assertCount(
            1,
            $groups,
        );
        self::assertSame(
            MeetingTypes::BV,
            $groups[0]['meeting']->getType(),
        );
        self::assertSame(
            1815,
            $groups[0]['meeting']->getNumber(),
        );
        self::assertEmpty($groups[0]['decisions']);
    }

    public function testTheMeetingFilterNamesTheMeetingTheSameWay(): void
    {
        $groups = $this->searchFor('type:bm meeting:1815')->getResultsByMeeting();

        self::assertCount(
            1,
            $groups,
        );
        self::assertSame(
            1815,
            $groups[0]['meeting']->getNumber(),
        );
    }

    /**
     * Without a type there is no telling which meeting is meant, so every meeting carrying that number is shown.
     */
    public function testANumberWithoutATypeNamesEveryMeetingWithIt(): void
    {
        $groups = $this->searchFor('meeting:1')->getResultsByMeeting();

        self::assertSame(
            [
                MeetingTypes::VIRT,
                MeetingTypes::ALV,
                MeetingTypes::BV,
            ],
            array_map(
                static fn (array $group): MeetingTypes => $group['meeting']->getType(),
                $groups,
            ),
        );
    }

    /**
     * The meeting asked for leads the answer; the decisions that only mention it follow.
     */
    public function testTheMeetingAskedForLeadsTheResults(): void
    {
        $groups = $this->searchFor('BV 1801')->getResultsByMeeting();

        self::assertCount(
            2,
            $groups,
        );
        self::assertSame(
            1801,
            $groups[0]['meeting']->getNumber(),
        );
        self::assertCount(
            1,
            $groups[0]['decisions'],
        );
        self::assertSame(
            1804,
            $groups[1]['meeting']->getNumber(),
        );
    }

    public function testAMeetingThatDoesNotExistIsNotShown(): void
    {
        self::assertEmpty($this->searchFor('BV 9999')->getResultsByMeeting());
    }

    private function searchFor(string $prompt): DecisionSearch
    {
        $component = self::getContainer()->get(DecisionSearch::class);
        $component->q = $prompt;

        return $component;
    }
}
