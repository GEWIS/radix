<?php

declare(strict_types=1);

namespace App\Tests\Entity\Decision\SubDecision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Decision;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\Organ;
use App\Entity\Decision\SubDecision\Foundation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Foundation::class)]
final class FoundationTest extends TestCase
{
    /**
     * Two foundations may share a name; the five coordinates of the founding decision distinguish their pages, so the
     * hash is built from those.
     */
    public function testTheFoundationHashIsBuiltFromItsOwnCoordinates(): void
    {
        $foundation = $this->foundation();

        self::assertSame(
            'BV-18.5.1.4',
            $foundation->getHash(),
        );
        self::assertSame(
            [
                'meeting_type' => MeetingTypes::BV,
                'meeting_number' => 18,
                'decision_point' => 5,
                'decision_number' => 1,
                'subdecision_sequence' => 4,
                'abbr' => 'TC',
                'name' => 'Taartcommissie',
                'organtype' => OrganTypes::Committee,
            ],
            $foundation->toArray(),
        );
    }

    /**
     * When the projection is rebuilt without this foundation, the derived organ may not be left pointing at a body
     * that no longer exists.
     */
    public function testClearingTheOrganLeavesItUninitialised(): void
    {
        $foundation = $this->foundation();
        $organ = new Organ();
        $foundation->organ = $organ;
        self::assertSame(
            $organ,
            $foundation->organ,
        );

        $foundation->clearOrgan();

        self::assertFalse(isset($foundation->organ));
    }

    public function testANewFoundationHasNoReferencesYet(): void
    {
        self::assertCount(
            0,
            $this->foundation()->getReferences(),
        );
    }

    private function foundation(): Foundation
    {
        $meeting = new Meeting();
        $meeting->type = MeetingTypes::BV;
        $meeting->number = 18;
        $meeting->date = new DateTimeImmutable('2026-08-20');

        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->point = 5;
        $decision->number = 1;

        $foundation = new Foundation();
        $foundation->abbr = 'TC';
        $foundation->name = 'Taartcommissie';
        $foundation->organType = OrganTypes::Committee;
        $foundation->sequence = 4;
        $foundation->setDecision($decision);

        return $foundation;
    }
}
