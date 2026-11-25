<?php

declare(strict_types=1);

namespace App\Tests\Entity\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Decision;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\SubDecision\Other;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubDecision::class)]
#[CoversClass(Other::class)]
final class SubDecisionTest extends TestCase
{
    /**
     * A sub-decision takes its identity from its decision, so the five coordinates are the key, and it carries both
     * language texts the projection generated.
     */
    public function testASubDecisionTakesItsIdentityFromItsDecision(): void
    {
        $decision = $this->decision();
        $other = new Other();
        $other->sequence = 3;
        $other->contentNL = 'Besloten.';
        $other->contentEN = 'Decided.';
        $other->setDecision($decision);

        self::assertSame(
            MeetingTypes::BV,
            $other->getMeetingType(),
        );
        self::assertSame(
            18,
            $other->getMeetingNumber(),
        );
        self::assertSame(
            5,
            $other->getDecisionPoint(),
        );
        self::assertSame(
            1,
            $other->getDecisionNumber(),
        );
        self::assertSame(
            $decision,
            $other->decision,
        );
        self::assertSame(
            [
                'meeting_type' => 'BV',
                'meeting_number' => 18,
                'decision_point' => 5,
                'decision_number' => 1,
                'subdecision_sequence' => 3,
                'contentNL' => 'Besloten.',
                'contentEN' => 'Decided.',
            ],
            $other->toGdprArray(),
        );
    }

    private function decision(): Decision
    {
        $meeting = new Meeting();
        $meeting->type = MeetingTypes::BV;
        $meeting->number = 18;
        $meeting->date = new DateTimeImmutable('2026-08-20');

        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->point = 5;
        $decision->number = 1;

        return $decision;
    }
}
