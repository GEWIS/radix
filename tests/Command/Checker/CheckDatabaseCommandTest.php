<?php

declare(strict_types=1);

namespace App\Tests\Command\Checker;

use App\Command\Checker\AbstractCheckerCommand;
use App\Command\Checker\CheckDatabaseCommand;
use App\Service\Checker\Checker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The report is mailed on every run, so the test checks only that the command runs and that the lock
 * prevents a concurrent run.
 */
#[CoversClass(CheckDatabaseCommand::class)]
#[CoversClass(AbstractCheckerCommand::class)]
final class CheckDatabaseCommandTest extends TestCase
{
    public function testRunsTheCheck(): void
    {
        $checker = $this->createMock(Checker::class);
        $checker->expects(self::once())->method('check');

        $tester = new CommandTester(new CheckDatabaseCommand($checker));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
    }

    /**
     * A run that finds the lock taken returns successfully without running the check.
     */
    public function testStandsDownWhileAnotherRunIsStillInFlight(): void
    {
        $lockFactory = new LockFactory(new InMemoryStore());
        $inFlight = $lockFactory->createLock('check:database');
        self::assertTrue($inFlight->acquire());

        $checker = $this->createMock(Checker::class);
        $checker->expects(self::never())->method('check');

        $command = new CheckDatabaseCommand($checker);
        $command->setRunLockFactory($lockFactory);
        $tester = new CommandTester($command);

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'still in flight, standing down',
            $tester->getDisplay(),
        );
    }

    /**
     * The lock is released after a run, so the next run can take it.
     */
    public function testTheLockIsFreeForTheNextRun(): void
    {
        $lockFactory = new LockFactory(new InMemoryStore());
        $checker = $this->createMock(Checker::class);
        $checker->expects(self::exactly(2))->method('check');

        for ($run = 0; 2 > $run; ++$run) {
            $command = new CheckDatabaseCommand($checker);
            $command->setRunLockFactory($lockFactory);

            self::assertSame(
                Command::SUCCESS,
                new CommandTester($command)->execute([]),
            );
        }
    }
}
