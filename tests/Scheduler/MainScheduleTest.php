<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Command\HoldsRunLockTrait;
use App\Scheduler\MainSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

use function ksort;

/**
 * Nothing runs the schedule by hand, so its contents and its behaviour across a restart are only checked here.
 */
#[CoversClass(MainSchedule::class)]
class MainScheduleTest extends KernelTestCase
{
    /**
     * Stated here rather than read off twenty-six attributes. The transport decides which consumers run a job, and
     * keeps `app:decision:generate` off the queue carrying the every-minute jobs.
     */
    private const array EXPECTED = [
        'app:activity:delete-old-signups' => 'gdpr',
        'app:activity:lapse-overdue-options' => 'cron',
        'app:activity:prune-unverified-signups' => 'gdpr',
        'app:activity:remind-closing-signups' => 'cron',
        'app:activity:remind-option-budget' => 'cron',
        'app:activity:run-due-draws' => 'cron',
        'app:activity:sync-agenda' => 'cron',
        'app:application:delete-stale-revisions' => 'gdpr',
        'app:decision:generate' => 'maintenance',
        'app:education:prune-expired-downloads' => 'cron',
        'app:infimum:rotate' => 'cron',
        'app:messenger:recover-failures' => 'maintenance',
        'app:notification:run-digests' => 'cron',
        'app:page:prune-images' => 'maintenance',
        'app:photo:weekly' => 'cron',
        'app:poll:anonymise-votes' => 'gdpr',
        'app:public-archive:sync' => 'cron',
        'app:user:prune-expired-data-exports' => 'gdpr',
        'app:user:purge-expired-sessions' => 'gdpr',
        'app:users:force-relogin' => 'cron',
        'check:database' => 'maintenance',
        'check:membership:conversion:graduate' => 'maintenance',
        'check:membership:renewal:graduate' => 'maintenance',
        'database:mailinglist:fetch all' => 'maintenance',
        'database:mailinglist:maintenance -f -vv' => 'maintenance',
        'database:mailinglist:sync-membership -f -vv all' => 'maintenance',
        'database:prospective-members:delete-expired' => 'gdpr',
    ];

    public function testDispatchesTheHousekeepingTheCrontabUsedTo(): void
    {
        $dispatched = [];

        foreach ($this->schedule()->getRecurringMessages() as $recurringMessage) {
            foreach ($recurringMessage->getMessages($this->context($recurringMessage)) as $message) {
                // Only one consumer ever advances the schedule, so anything handled here runs behind everything
                // else.
                self::assertInstanceOf(
                    RedispatchMessage::class,
                    $message,
                );
                self::assertInstanceOf(
                    RunCommandMessage::class,
                    $message->envelope,
                );

                $dispatched[(string) $message->envelope] = $message->transportNames;
            }
        }

        ksort($dispatched);

        self::assertSame(
            self::EXPECTED,
            $dispatched,
        );
    }

    /**
     * The serial worker used to be the mutual exclusion. Now that consumers scale, a command without a lock can run
     * twice, so this stops a new `#[AsCronTask]` arriving without one.
     */
    public function testEveryScheduledCommandHoldsALockOverItsOwnRun(): void
    {
        $scheduled = 0;

        foreach (new Application(self::bootKernel())->all() as $command) {
            // A LazyCommand stands in for the real one and carries none of its attributes.
            $reflection = new ReflectionClass($command instanceof LazyCommand ? $command->getCommand() : $command);

            if ([] === $reflection->getAttributes(AsCronTask::class)) {
                continue;
            }

            ++$scheduled;

            self::assertContains(
                HoldsRunLockTrait::class,
                $reflection->getTraitNames(),
                $command->getName() . ' is scheduled but does not hold a lock over its own run.',
            );
        }

        self::assertCount(
            $scheduled,
            self::EXPECTED,
        );
    }

    /**
     * An in-process checkpoint starts at "now" on every boot, so a restart cannot distinguish a due time it has
     * handled from one it has not.
     */
    public function testKeepsItsCheckpointOutsideTheProcess(): void
    {
        self::assertNotNull($this->schedule()->getState());
    }

    /**
     * Both containers consume briefly during a rolling deploy, and would each dispatch the same run.
     */
    public function testLetsOnlyOneWorkerAdvanceTheSchedule(): void
    {
        self::assertNotNull($this->schedule()->getLock());
    }

    /**
     * Every job recomputes what the previous run would have done, so an outage is caught up once, not replayed.
     */
    public function testCatchesUpOnceAfterAnOutage(): void
    {
        self::assertTrue($this->schedule()->shouldProcessOnlyLastMissedRun());
    }

    /**
     * What the generator hands a message provider when a run comes due.
     */
    private function context(RecurringMessage $recurringMessage): MessageContext
    {
        return new MessageContext(
            'default',
            $recurringMessage->getId(),
            $recurringMessage->getTrigger(),
            new DateTimeImmutable('2026-08-20 01:00:00'),
        );
    }

    /**
     * The compiled schedule: tasks are collected onto it by the compiler pass, so one built by hand would be empty.
     */
    private function schedule(): Schedule
    {
        $provider = self::getContainer()->get('scheduler.provider.default');
        self::assertInstanceOf(
            ScheduleProviderInterface::class,
            $provider,
        );

        return $provider->getSchedule();
    }
}
