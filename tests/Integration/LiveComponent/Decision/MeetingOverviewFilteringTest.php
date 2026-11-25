<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\MeetingOverview;
use App\ViewModel\Decision\MeetingOverviewRow;
use App\ViewModel\Decision\MeetingStatus;

use function array_map;
use function count;
use function strtoupper;
use function trim;

/**
 * The filters the meetings overview receives: a type from the tabs, a reference from the search box, or both. Paging
 * itself is tested elsewhere.
 */
final class MeetingOverviewFilteringTest extends DatabaseTestCase
{
    public function testTheTabsAreTheMemberFacingTypes(): void
    {
        $component = self::getContainer()->get(MeetingOverview::class);

        self::assertSame(
            [
                'gmm',
                'bm',
                'cm',
            ],
            array_map(
                static fn (array $option): string => $option['token'],
                $component->getTypeOptions(),
            ),
        );
        self::assertSame(
            [
                MeetingTypes::ALV,
                MeetingTypes::BV,
                MeetingTypes::VV,
            ],
            array_map(
                static fn (array $option): MeetingTypes => $option['type'],
                $component->getTypeOptions(),
            ),
        );
    }

    public function testPickingATabNarrowsToThatKindOfMeeting(): void
    {
        $component = $this->overview();
        $component->filterType('bm');

        $rows = $component->getMeetings();

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame(
                MeetingTypes::BV,
                $row->meeting->type,
            );
        }

        $component->filterType('nonsense');
        self::assertNull(
            $component->type,
            'A token that was never offered is not a filter.',
        );
    }

    /**
     * Virtual meetings exist to repair the record and are reachable only by naming one, never through the list.
     */
    public function testAVirtualMeetingIsNotListedUnlessItIsNamed(): void
    {
        $unfiltered = $this->overview()->getMeetings();
        foreach ($unfiltered as $row) {
            self::assertNotSame(
                MeetingTypes::VIRT,
                $row->meeting->type,
            );
        }

        $searching = $this->overview();
        $searching->search = 'VIRT 1';

        foreach ($searching->getMeetings() as $row) {
            self::assertSame(
                MeetingTypes::VIRT,
                $row->meeting->type,
            );
        }
    }

    public function testAReferenceTypedIntoTheSearchNarrowsToThatMeeting(): void
    {
        $any = $this->overview()->getMeetings()[0] ?? null;
        self::assertNotNull($any);
        $reference = trim($any->meeting->type->value . ' ' . $any->meeting->number);

        $searching = $this->overview();
        $searching->search = $reference;

        $rows = $searching->getMeetings();

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame(
                $any->meeting->type,
                $row->meeting->type,
            );
            self::assertSame(
                $any->meeting->number,
                $row->meeting->number,
            );
        }
    }

    public function testANumberAloneMatchesEveryMeetingWithIt(): void
    {
        $any = $this->overview()->getMeetings()[0] ?? null;
        self::assertNotNull($any);

        $searching = $this->overview();
        $searching->search = (string) $any->meeting->number;

        $rows = $searching->getMeetings();

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame(
                $any->meeting->number,
                $row->meeting->number,
            );
        }
    }

    public function testAKeywordThatIsNotAMeetingTypeNarrowsNothing(): void
    {
        $all = $this->overview()->getTotalCount();

        $searching = $this->overview();
        $searching->search = 'notatype';

        self::assertSame(
            $all,
            $searching->getTotalCount(),
        );
    }

    public function testEveryRowCarriesItsDecisionsAndItsMinutes(): void
    {
        $component = $this->overview();

        foreach ($component->getMeetings() as $row) {
            self::assertInstanceOf(
                MeetingOverviewRow::class,
                $row,
            );
            self::assertGreaterThanOrEqual(
                0,
                $row->decisionCount,
            );
            // The status is derived from the meeting itself, so it cannot disagree with what the row shows.
            self::assertSame(
                $row->status,
                MeetingStatus::derive(
                    $row->meeting->date,
                    $row->decisionCount > 0,
                    $row->hasMinutes,
                ),
            );
        }

        self::assertLessThanOrEqual(
            $component->getTotalCount(),
            count($component->getMeetings()),
        );
    }

    public function testTheSearchIsReadCaseInsensitively(): void
    {
        $any = $this->overview()->getMeetings()[0] ?? null;
        self::assertNotNull($any);

        $searching = $this->overview();
        $searching->search = strtoupper($any->meeting->type->value) . ' ' . $any->meeting->number;

        self::assertNotEmpty($searching->getMeetings());
    }

    private function overview(): MeetingOverview
    {
        return self::getContainer()->get(MeetingOverview::class);
    }
}
