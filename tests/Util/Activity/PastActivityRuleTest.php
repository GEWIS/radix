<?php

declare(strict_types=1);

namespace App\Tests\Util\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Util\Activity\PastActivityRule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The single definition of "this activity is in the past", shared by the guard, the review screen and the edit gate.
 */
final class PastActivityRuleTest extends TestCase
{
    public function testAnActivityWhoseEndHasPassedHasEnded(): void
    {
        self::assertTrue(PastActivityRule::ended($this->revision(end: '-1 hour')));
    }

    public function testAnActivityThatHasNotEndedHasNotEnded(): void
    {
        self::assertFalse(PastActivityRule::ended($this->revision(end: '+1 hour')));
    }

    public function testAnActivityWithoutAnEndHasNotEnded(): void
    {
        self::assertFalse(PastActivityRule::ended($this->revision()));
    }

    public function testAnActivityWhoseStartHasPassedHasStarted(): void
    {
        self::assertTrue(PastActivityRule::started($this->revision(begin: '-1 hour')));
        self::assertFalse(PastActivityRule::started($this->revision(begin: '+1 hour')));
        self::assertFalse(PastActivityRule::started($this->revision()));
    }

    /**
     * The established-activity case: the live schedule has ended and the revision in flight is a re-edit of it.
     */
    public function testALiveScheduleThatHasEndedFreezesAReEdit(): void
    {
        $live = $this->revision(end: '-1 hour');

        self::assertTrue(PastActivityRule::liveEnded($live, $this->revision()));
    }

    public function testALiveScheduleThatHasNotEndedDoesNotFreezeAReEdit(): void
    {
        self::assertFalse(PastActivityRule::liveEnded($this->revision(end: '+1 hour'), $this->revision()));
    }

    public function testWithoutALiveRevisionNothingIsFrozen(): void
    {
        self::assertFalse(PastActivityRule::liveEnded(null, $this->revision(end: '-1 hour')));
    }

    /**
     * The in-flight revision being the live one is the brand-new case, not the established one.
     */
    public function testTheLiveRevisionItselfIsNotAnEstablishedReEdit(): void
    {
        $revision = $this->revision(end: '-1 hour');

        self::assertFalse(PastActivityRule::liveEnded($revision, $revision));
    }

    /**
     * A brand-new activity can never debut once its own start has passed.
     */
    public function testABrandNewActivityWhoseStartHasPassedHasMissedItsDebut(): void
    {
        self::assertTrue(PastActivityRule::debutMissed(null, $this->revision(begin: '-1 hour')));
        self::assertFalse(PastActivityRule::debutMissed(null, $this->revision(begin: '+1 hour')));
    }

    public function testTheLiveRevisionItselfIsJudgedByItsOwnStart(): void
    {
        $revision = $this->revision(begin: '-1 hour');

        self::assertTrue(PastActivityRule::debutMissed($revision, $revision));
    }

    public function testAReEditIsNotABrandNewActivity(): void
    {
        self::assertFalse(PastActivityRule::debutMissed(
            $this->revision(),
            $this->revision(begin: '-1 hour'),
        ));
    }

    private function revision(
        ?string $begin = null,
        ?string $end = null,
    ): ActivityRevision {
        $revision = self::createStub(ActivityRevision::class);
        $revision->beginTime = null === $begin
            ? null
            : new DateTimeImmutable($begin);
        $revision->endTime = null === $end
            ? null
            : new DateTimeImmutable($end);

        return $revision;
    }
}
