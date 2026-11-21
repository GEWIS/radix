<?php

declare(strict_types=1);

namespace App\Tests\Command\Database;

use App\Command\Database\MailingListMaintenanceCommand;
use App\Service\Database\MailingListService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command schedules the removal of memberships for expired and hidden members, so it is destructive
 * unless it rehearses. The switch defaults to a rehearsal.
 */
#[CoversClass(MailingListMaintenanceCommand::class)]
final class MailingListMaintenanceCommandTest extends TestCase
{
    public function testWithoutForceNothingIsScheduled(): void
    {
        $service = $this->createMock(MailingListService::class);
        $service->expects(self::once())
            ->method('performMaintenance')
            ->with(
                self::callback(
                    static fn (OutputInterface $output): bool => OutputInterface::VERBOSITY_DEBUG
                        === $output->getVerbosity(),
                ),
                false,
            );

        $tester = new CommandTester(new MailingListMaintenanceCommand($service));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'assuming dry-run',
            $tester->getDisplay(),
        );
    }

    public function testForceSchedulesTheRemovals(): void
    {
        $service = $this->createMock(MailingListService::class);
        $service->expects(self::once())
            ->method('performMaintenance')
            ->with(
                self::isInstanceOf(OutputInterface::class),
                true,
            );

        $tester = new CommandTester(new MailingListMaintenanceCommand($service));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--force' => true]),
        );
        self::assertStringNotContainsString(
            'assuming dry-run',
            $tester->getDisplay(),
        );
    }
}
