<?php

declare(strict_types=1);

namespace App\Tests\Entity\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Entity\Career\CompanyRevision;
use App\Entity\Decision\Member as DecisionMember;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;

/**
 * What an activity derives about itself: which revision is shown, which sign-up lists the public may read, and the
 * three states the board can put it in (cancelled, unpublished, or running). A list of the working revision is not
 * public until the activity itself is approved.
 */
#[CoversClass(Activity::class)]
final class ActivityTest extends TestCase
{
    public function testAnActivityCanOnlyBeMadeLiveByItsOwnRevision(): void
    {
        $activity = new Activity();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('An activity can only be made live by one of its own revisions.');

        $activity->markRevisionLive(new CompanyRevision());
    }

    public function testAnActivityWithNoRevisionsHasNothingToShow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Activity has no revision to display.');

        new Activity()->getName();
    }

    public function testTheLiveRevisionIsShownRatherThanTheWorkingHead(): void
    {
        $activity = new Activity();
        $working = $this->revision(
            $activity,
            'Working',
        );
        $activity->addRevision($working);
        $activity->setCurrentRevision($working);

        self::assertSame(
            'Working',
            $activity->getName()->getValueEN(),
        );

        $live = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($live);
        $activity->setLiveRevision($live);

        self::assertSame(
            'Live',
            $activity->getName()->getValueEN(),
        );
    }

    public function testRestoringPutsTheLiveRevisionBackAtTheWorkingHead(): void
    {
        $activity = new Activity();
        $working = $this->revision(
            $activity,
            'Working',
        );
        $live = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($working);
        $activity->addRevision($live);
        $activity->setCurrentRevision($working);
        $activity->setLiveRevision($live);

        $activity->restoreLiveRevision();

        self::assertSame(
            $live,
            $activity->getCurrentRevision(),
        );

        $activity->detachRevisions();

        self::assertNull($activity->getCurrentRevision());
        self::assertNull($activity->getLiveRevision());
    }

    public function testOnlyTheLiveRevisionsListsArePublic(): void
    {
        $activity = new Activity();
        $working = $this->revision(
            $activity,
            'Working',
        );
        $activity->addRevision($working);
        $activity->setCurrentRevision($working);
        $working->addSignupList($this->list('-1 week', '+1 week'));

        self::assertSame(
            [],
            iterator_to_array(
                $activity->getLiveSignupLists(),
                false,
            ),
        );
        self::assertNull($activity->getRelevantSignupList());
        self::assertSame(
            0,
            $activity->countPendingSignupLists(),
        );

        $live = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($live);
        $activity->setLiveRevision($live);
        $live->addSignupList($this->list('-1 week', '+1 week'));

        self::assertCount(
            1,
            $activity->getLiveSignupLists(),
        );
        self::assertSame(
            1,
            $activity->countPendingSignupLists(),
        );
    }

    public function testAClosedListIsNoLongerCountedOrOffered(): void
    {
        $activity = new Activity();
        $live = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($live);
        $activity->setLiveRevision($live);
        $live->addSignupList($this->list('-2 weeks', '-1 week'));

        self::assertSame(
            0,
            $activity->countPendingSignupLists(),
        );
        self::assertNull($activity->getRelevantSignupList());
    }

    /**
     * A closed list is skipped, not treated as the end of the collection; an open list after it still counts.
     */
    public function testAClosedListDoesNotHideALaterOpenList(): void
    {
        $activity = new Activity();
        $live = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($live);
        $activity->setLiveRevision($live);
        $live->addSignupList($this->list('-2 weeks', '-1 week'));
        $live->addSignupList($this->list('-1 week', '+1 week'));

        self::assertSame(
            1,
            $activity->countPendingSignupLists(),
        );
    }

    /**
     * A revision belongs to the activity once; adding the same one again must not make it appear twice.
     */
    public function testAddingTheSameRevisionTwiceKeepsOne(): void
    {
        $activity = new Activity();
        $revision = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($revision);
        $activity->addRevision($revision);

        self::assertCount(
            1,
            $activity->getRevisions(),
        );
    }

    public function testTheListWorthShowingIsTheOpenOneClosingSoonest(): void
    {
        $activity = new Activity();
        $live = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($live);
        $activity->setLiveRevision($live);

        $later = $this->list(
            '-1 week',
            '+3 weeks',
        );
        $sooner = $this->list(
            '-1 week',
            '+3 days',
        );
        $live->addSignupList($later);
        $live->addSignupList($sooner);

        self::assertSame(
            $sooner,
            $activity->getRelevantSignupList(),
        );
        self::assertSame(
            2,
            $activity->countPendingSignupLists(),
        );
    }

    public function testCancellingAndRepublishingAreBoardSwitches(): void
    {
        $activity = new Activity();
        $live = $this->revision(
            $activity,
            'Live',
        );
        $activity->addRevision($live);
        $activity->setLiveRevision($live);
        $member = new DecisionMember();

        self::assertTrue($activity->isPubliclyVisible());
        self::assertFalse($activity->isFrozen());

        $activity->cancel($member);
        self::assertTrue($activity->isCancelled());
        self::assertTrue($activity->isFrozen());
        // A cancelled activity keeps its page; it is the board's decision to take it out of view separately.
        self::assertTrue($activity->isPubliclyVisible());

        $activity->uncancel();
        self::assertFalse($activity->isCancelled());
        self::assertFalse($activity->isFrozen());

        $activity->unpublish($member);
        self::assertTrue($activity->isUnpublished());
        self::assertTrue($activity->isFrozen());
        self::assertFalse($activity->isPubliclyVisible());

        $activity->republish();
        self::assertFalse($activity->isUnpublished());
        self::assertTrue($activity->isPubliclyVisible());
    }

    public function testNothingThatWasNeverApprovedIsPublic(): void
    {
        $activity = new Activity();

        self::assertFalse($activity->isPubliclyVisible());
        self::assertFalse($activity->isFrozen());
    }

    public function testAnActivityHasPassedOnceItsShownScheduleIsOver(): void
    {
        $activity = new Activity();
        $live = $this->revision(
            $activity,
            'Live',
        );
        $ends = new DateTimeImmutable('-1 month');
        $live->beginTime = new DateTimeImmutable('-2 months');
        $live->endTime = $ends;
        $activity->addRevision($live);
        $activity->setLiveRevision($live);

        self::assertTrue($activity->hasPassed());
        self::assertSame(
            $ends->format('Y-m-d H:i'),
            $activity->getEndTime()->format('Y-m-d H:i'),
        );

        $upcoming = new Activity();
        $revision = $this->revision(
            $upcoming,
            'Upcoming',
        );
        $revision->beginTime = new DateTimeImmutable('+1 month');
        $revision->endTime = new DateTimeImmutable('+2 months');
        $upcoming->addRevision($revision);
        $upcoming->setLiveRevision($revision);

        self::assertFalse($upcoming->hasPassed());
    }

    public function testTheResourceIdIsTheActivitysOwn(): void
    {
        self::assertSame(
            'activity',
            new Activity()->getResourceId(),
        );
    }

    private function revision(
        Activity $activity,
        string $name,
    ): ActivityRevision {
        $revision = new ActivityRevision();
        $revision->name = new ActivityLocalisedText(
            $name,
            $name,
        );
        $activity->addRevision($revision);

        return $revision;
    }

    private function list(
        string $opens,
        string $closes,
    ): SignupList {
        $list = new SignupList();
        $list->name = new ActivityLocalisedText(
            'Lijst',
            'List',
        );
        $list->openDate = new DateTimeImmutable($opens);
        $list->closeDate = new DateTimeImmutable($closes);

        return $list;
    }
}
