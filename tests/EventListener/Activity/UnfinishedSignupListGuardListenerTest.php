<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\EventListener\Activity\UnfinishedSignupListGuardListener;
use App\Tests\Support\BuildsGuardEvents;
use DateTime;
use Override;
use PHPUnit\Framework\TestCase;
use stdClass;

use function implode;

final class UnfinishedSignupListGuardListenerTest extends TestCase
{
    use BuildsGuardEvents;

    private UnfinishedSignupListGuardListener $listener;

    #[Override]
    protected function setUp(): void
    {
        $this->listener = new UnfinishedSignupListGuardListener();
    }

    public function testBlocksSubmitWhileAListHasNotBeenFilledIn(): void
    {
        $revision = $this->revisionWith($this->emptyList());

        $event = $this->guardEvent(
            $revision,
            'submit',
            'draft',
            'in-review',
        );
        ($this->listener)($event);

        self::assertTrue($event->isBlocked());
        self::assertStringContainsString(
            'has not been filled in',
            implode(
                "\n",
                $this->blockerMessages($event),
            ),
        );
    }

    public function testBlocksApproveWhileAListHasNotBeenFilledIn(): void
    {
        $event = $this->guardEvent($this->revisionWith($this->emptyList()));
        ($this->listener)($event);

        self::assertTrue($event->isBlocked());
    }

    public function testBlocksWhenTheCapacityIsMissingFromALimitedList(): void
    {
        $list = $this->filledList();
        $list->setLimitedCapacity(true);
        $list->setCapacity(null);

        $event = $this->guardEvent($this->revisionWith($list));
        ($this->listener)($event);

        self::assertTrue($event->isBlocked());
    }

    public function testAllowsAListThatHasBeenFilledIn(): void
    {
        $event = $this->guardEvent($this->revisionWith($this->filledList()));
        ($this->listener)($event);

        self::assertFalse($event->isBlocked());
    }

    public function testAllowsARevisionWithNoSignupListsAtAll(): void
    {
        $event = $this->guardEvent($this->revision());
        ($this->listener)($event);

        self::assertFalse($event->isBlocked());
    }

    public function testIgnoresNonActivityRevisions(): void
    {
        $event = $this->guardEvent(new stdClass());
        ($this->listener)($event);

        self::assertFalse($event->isBlocked());
    }

    /**
     * Written in English alone, so a list is asked for an English name and nothing else.
     */
    private function revision(): ActivityRevision
    {
        $revision = new ActivityRevision();
        new Activity()->addRevision($revision);
        $revision->setName(new ActivityLocalisedText('Test activity'));
        $revision->setLocation(new ActivityLocalisedText());
        $revision->setCosts(new ActivityLocalisedText());
        $revision->setDescription(new ActivityLocalisedText());

        return $revision;
    }

    private function revisionWith(SignupList $list): ActivityRevision
    {
        $revision = $this->revision();
        $revision->addSignupList($list);

        return $revision;
    }

    private function emptyList(): SignupList
    {
        $list = new SignupList();
        $list->setName(new ActivityLocalisedText());

        return $list;
    }

    private function filledList(): SignupList
    {
        $list = $this->emptyList();
        $list->setName(new ActivityLocalisedText(
            'Deelnemers',
            'Participants',
        ));
        $list->setOpenDate(new DateTime('2030-01-01 12:00'));
        $list->setCloseDate(new DateTime('2030-02-01 12:00'));

        return $list;
    }
}
