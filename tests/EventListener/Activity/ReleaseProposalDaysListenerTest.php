<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Activity;

use App\Entity\Activity\ActivityDateOption;
use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\DateOptionStatus;
use App\EventListener\Activity\ReleaseProposalDaysListener;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

/**
 * A released day must be free immediately, or the calendar keeps claims that no longer apply.
 */
final class ReleaseProposalDaysListenerTest extends TestCase
{
    #[DataProvider('releases')]
    public function testEveryWayOutOfTheRunningReleasesTheStandingDays(
        string $method,
        DateOptionStatus $expected,
    ): void {
        $proposal = $this->proposal();
        $standing = $this->option(
            $proposal,
            DateOptionStatus::Proposed,
        );
        $decided = $this->option(
            $proposal,
            DateOptionStatus::Approved,
        );

        $listener = new ReleaseProposalDaysListener();
        $event = $this->event($proposal);
        match ($method) {
            'onWithdrawn' => $listener->onWithdrawn($event),
            'onLapsed' => $listener->onLapsed($event),
            'onDeclined' => $listener->onDeclined($event),
            default => self::fail('Unknown transition ' . $method),
        };

        self::assertSame(
            $expected,
            $standing->status,
        );
        // A day that was already decided is not re-decided by a later transition.
        self::assertSame(
            $expected,
            $decided->status,
        );
    }

    /**
     * @return iterable<string, array{string, DateOptionStatus}>
     */
    public static function releases(): iterable
    {
        yield 'withdrawn' => [
            'onWithdrawn',
            DateOptionStatus::Withdrawn,
        ];

        yield 'lapsed' => [
            'onLapsed',
            DateOptionStatus::Declined,
        ];

        yield 'declined' => [
            'onDeclined',
            DateOptionStatus::Declined,
        ];
    }

    public function testADayThatIsNoLongerStandingIsLeftWhereItIs(): void
    {
        $proposal = $this->proposal();
        $declined = $this->option(
            $proposal,
            DateOptionStatus::Declined,
        );

        new ReleaseProposalDaysListener()->onWithdrawn($this->event($proposal));

        self::assertSame(
            DateOptionStatus::Declined,
            $declined->status,
        );
    }

    /**
     * Reaching `scheduled` means the budget is not settled: either it never was, or the board withdrew a clearance.
     * The timestamp is cleared and the reminder is scheduled again.
     */
    public function testReachingScheduledClearsTheBudgetClearance(): void
    {
        $proposal = $this->proposal();
        $proposal->budgetClearedAt = new DateTimeImmutable();
        $proposal->budgetRemindedAt = new DateTimeImmutable();

        new ReleaseProposalDaysListener()->onScheduled($this->event($proposal));

        self::assertNull($proposal->budgetClearance);
        self::assertNull($proposal->budgetClearedBy);
        self::assertNull($proposal->budgetClearedAt);
        self::assertNull($proposal->budgetRemindedAt);
    }

    public function testASubjectThatIsNotAProposalIsIgnored(): void
    {
        $this->expectNotToPerformAssertions();

        $listener = new ReleaseProposalDaysListener();
        $listener->onWithdrawn($this->event(new stdClass()));
        $listener->onScheduled($this->event(new stdClass()));
    }

    private function proposal(): ActivityProposal
    {
        return new ActivityProposal();
    }

    private function option(
        ActivityProposal $proposal,
        DateOptionStatus $status,
    ): ActivityDateOption {
        $option = new ActivityDateOption();
        $option->status = $status;
        $proposal->addDateOption($option);

        return $option;
    }

    /**
     * @return Event<object>
     */
    private function event(object $subject): Event
    {
        return new Event(
            $subject,
            new Marking(),
            new Transition(
                'withdraw',
                'from',
                'to',
            ),
        );
    }
}
