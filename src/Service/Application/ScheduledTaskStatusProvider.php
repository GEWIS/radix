<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\ViewModel\Application\LateScheduledTask;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

use function usort;

/**
 * Which scheduled commands have stopped running, for the administration dashboard.
 *
 * The schedule is the list of what should be running, so a command added with a new `#[AsCronTask]` is covered
 * without being registered here. A task is late when the run its own trigger places after the last one recorded is
 * further in the past than the grace period below.
 *
 * A schedule that stops dispatching produces the same empty queues as a schedule with nothing due, which is why
 * this is needed at all. {@see IpDatabaseStatusProvider} answers the same question for one task from the file that
 * command writes; this answers it for every task from the schedule, and neither depends on the other.
 */
final readonly class ScheduledTaskStatusProvider
{
    /**
     * How long after a due time a task is still considered to be running. It covers a queue working through a
     * backlog and a worker between restarts, and is short enough that a daily task which missed a run is reported
     * the same morning.
     */
    private const string GRACE = '-15 minutes';

    public function __construct(
        #[Autowire(service: 'scheduler.provider.default')]
        private ScheduleProviderInterface $schedule,
        private ScheduledRunRecorder $recorder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<LateScheduledTask>
     */
    public function late(): array
    {
        $cutoff = $this->clock->now()->modify(self::GRACE);
        $since = $this->recorder->since();
        $late = [];

        foreach ($this->schedule->getSchedule()->getRecurringMessages() as $recurringMessage) {
            $command = $this->command($recurringMessage);

            if (null === $command) {
                continue;
            }

            $lastRun = $this->recorder->lastRun($command);
            $dueAt = $recurringMessage->getTrigger()->getNextRunDate($lastRun ?? $since);

            if (
                null === $dueAt
                || $dueAt > $cutoff
            ) {
                continue;
            }

            $late[] = new LateScheduledTask(
                $command,
                (string) $recurringMessage->getTrigger(),
                $lastRun,
                $dueAt,
            );
        }

        // Oldest due time first, which is the task that has been failing longest.
        usort(
            $late,
            static fn (LateScheduledTask $a, LateScheduledTask $b): int => $a->dueAt <=> $b->dueAt,
        );

        return $late;
    }

    /**
     * The command line a recurring message runs. Every task on this schedule redispatches a `RunCommandMessage`
     * onto a transport, so anything else cannot be identified by command and is skipped.
     */
    private function command(RecurringMessage $recurringMessage): ?string
    {
        $context = new MessageContext(
            'default',
            $recurringMessage->getId(),
            $recurringMessage->getTrigger(),
            new DateTimeImmutable(),
        );

        foreach ($recurringMessage->getMessages($context) as $message) {
            if (
                !$message instanceof RedispatchMessage
                || !$message->envelope instanceof RunCommandMessage
            ) {
                continue;
            }

            return (string) $message->envelope;
        }

        return null;
    }
}
