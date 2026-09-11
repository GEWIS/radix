<?php

declare(strict_types=1);

namespace App\Tests\Service\Decision;

use App\Entity\Decision\Decision;
use App\Entity\Decision\MeetingPoint;
use App\Service\Decision\MeetingPointDecisionMatcher;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MeetingPointDecisionMatcherTest extends TestCase
{
    private MeetingPointDecisionMatcher $matcher;

    #[Override]
    protected function setUp(): void
    {
        $this->matcher = new MeetingPointDecisionMatcher();
    }

    public function testExactNumberBeatsLetteredVariantsRegardlessOfOrder(): void
    {
        $lettered = $this->point(
            1,
            '7a',
        );
        $exact = $this->point(
            2,
            '7',
        );

        $result = $this->matcher->match(
            [
                $lettered,
                $exact,
            ],
            [$this->decision(7)],
        );

        self::assertSame(
            [],
            $result->unmatched,
        );
        self::assertCount(
            1,
            $result->decisionsForPoint($exact),
        );
        self::assertSame(
            [],
            $result->decisionsForPoint($lettered),
        );
    }

    public function testFirstLetteredVariantWinsWhenNoExactNumberExists(): void
    {
        $first = $this->point(
            1,
            '2a',
        );
        $second = $this->point(
            2,
            '2b',
        );

        $result = $this->matcher->match(
            [
                $first,
                $second,
            ],
            [$this->decision(2)],
        );

        self::assertCount(
            1,
            $result->decisionsForPoint($first),
        );
        self::assertSame(
            [],
            $result->decisionsForPoint($second),
        );
    }

    public function testDecisionWithoutMatchingPointIsReportedUnmatchedInsteadOfMisattributed(): void
    {
        $point = $this->point(
            1,
            '3',
        );
        $decision = $this->decision(1);

        $result = $this->matcher->match(
            [$point],
            [$decision],
        );

        self::assertSame(
            [$decision],
            $result->unmatched,
        );
        self::assertSame(
            [],
            $result->decisionsForPoint($point),
        );
    }

    public function testPointsWithoutLeadingIntegerNeverMatch(): void
    {
        $point = $this->point(
            1,
            'Any other business',
        );

        $result = $this->matcher->match(
            [$point],
            [$this->decision(1)],
        );

        self::assertCount(
            1,
            $result->unmatched,
        );
    }

    public function testMultipleDecisionsKeepTheirOrderPerPoint(): void
    {
        $point = $this->point(
            1,
            '10',
        );
        $first = $this->decision(10);
        $second = $this->decision(10);

        $result = $this->matcher->match(
            [$point],
            [
                $first,
                $second,
            ],
        );

        self::assertSame(
            [
                $first,
                $second,
            ],
            $result->decisionsForPoint($point),
        );
    }

    private function point(
        int $id,
        string $number,
    ): MeetingPoint {
        $point = new MeetingPoint();
        // By reflection, because the identifier is Doctrine's to assign, and a point is told apart by it here.
        new ReflectionProperty(
            MeetingPoint::class,
            'id',
        )->setValue(
            $point,
            $id,
        );
        $point->number = $number;

        return $point;
    }

    private function decision(int $point): Decision
    {
        $decision = new Decision();
        $decision->point = $point;

        return $decision;
    }
}
