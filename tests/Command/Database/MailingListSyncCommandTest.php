<?php

declare(strict_types=1);

namespace App\Tests\Command\Database;

use App\Command\Database\MailingListSyncCommand;
use App\Service\Database\ListmonkService;
use App\Service\Database\MailingListService;
use App\Service\Database\MailmanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Which backend a run pushes to, and whether it pushes at all. Without `-f` every run is a rehearsal; with the
 * switch reversed, either nothing is written for weeks or the hourly schedule writes to both servers.
 */
#[CoversClass(MailingListSyncCommand::class)]
final class MailingListSyncCommandTest extends TestCase
{
    public function testWithoutForceEveryBackendIsRehearsed(): void
    {
        $local = $this->createMock(MailingListService::class);
        $mailman = $this->createMock(MailmanService::class);
        $listmonk = $this->createMock(ListmonkService::class);

        // No `-f` means a rehearsal, so no backend writes anything.
        $dry = self::callback(
            static fn (OutputInterface $output, bool $dryRun): bool => false === $dryRun,
        );
        $local->expects(self::once())
            ->method('syncLocalOnlyMembership')
            ->with(
                self::isInstanceOf(OutputInterface::class),
                $dry,
            );
        $mailman->expects(self::once())
            ->method('syncMembership')
            ->with(
                self::isInstanceOf(OutputInterface::class),
                $dry,
            );
        $listmonk->expects(self::once())
            ->method('syncMembership')
            ->with(
                self::isInstanceOf(OutputInterface::class),
                $dry,
            );

        $tester = $this->tester(
            $local,
            $mailman,
            $listmonk,
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'Syncing all mailing list backends:',
            $display,
        );
        self::assertStringContainsString(
            'assuming dry-run',
            $display,
        );
    }

    /**
     * The dry run reports every pending change, because a rehearsal that hides its changes is useless.
     */
    public function testADryRunRaisesTheVerbosityTheBackendsSee(): void
    {
        $local = $this->createMock(MailingListService::class);
        $local->expects(self::once())
            ->method('syncLocalOnlyMembership')
            ->with(self::callback(
                static fn (OutputInterface $output): bool => OutputInterface::VERBOSITY_DEBUG
                    === $output->getVerbosity(),
            ));

        $tester = $this->tester(
            $local,
            $this->createStub(MailmanService::class),
            $this->createStub(ListmonkService::class),
        );

        $tester->execute(['--backend' => 'local']);

        self::assertStringContainsString(
            'displaying all pending changes',
            $tester->getDisplay(),
        );
    }

    public function testForcePerformsTheChanges(): void
    {
        $mailman = $this->createMock(MailmanService::class);
        $mailman->expects(self::once())
            ->method('syncMembership')
            ->with(
                self::isInstanceOf(OutputInterface::class),
                true,
            );

        $tester = $this->tester(
            $this->createMock(MailingListService::class),
            $mailman,
            $this->createMock(ListmonkService::class),
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([
                '--backend' => 'mailman',
                '--force' => true,
            ]),
        );

        self::assertStringNotContainsString(
            'assuming dry-run',
            $tester->getDisplay(),
        );
    }

    /**
     * A backend named on the command line is the only one written to, so repairing Mailman's bindings does not
     * also change Listmonk.
     */
    public function testOneBackendLeavesTheOthersAlone(): void
    {
        $local = $this->createMock(MailingListService::class);
        $local->expects(self::never())->method(self::anything());

        $mailman = $this->createMock(MailmanService::class);
        $mailman->expects(self::never())->method(self::anything());

        $listmonk = $this->createMock(ListmonkService::class);
        $listmonk->expects(self::once())->method('syncMembership');

        $tester = $this->tester(
            $local,
            $mailman,
            $listmonk,
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--backend' => 'listmonk']),
        );
        self::assertStringContainsString(
            'Syncing listmonk mailing list membership:',
            $tester->getDisplay(),
        );
    }

    /**
     * The local-only backend is a backend of its own, so a run that names it must not reach Mailman or Listmonk.
     */
    public function testTheLocalBackendDoesNotReachAnExternalServer(): void
    {
        $local = $this->createMock(MailingListService::class);
        $local->expects(self::once())->method('syncLocalOnlyMembership');

        $mailman = $this->createMock(MailmanService::class);
        $mailman->expects(self::never())->method(self::anything());

        $listmonk = $this->createMock(ListmonkService::class);
        $listmonk->expects(self::never())->method(self::anything());

        $tester = $this->tester(
            $local,
            $mailman,
            $listmonk,
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--backend' => 'local']),
        );
    }

    /**
     * The backend list is closed, so the console refuses a typo instead of falling through to `default`.
     */
    public function testAnUnknownBackendIsRefused(): void
    {
        $local = $this->createMock(MailingListService::class);
        $local->expects(self::never())->method(self::anything());

        $this->expectException(InvalidArgumentException::class);

        $this->tester(
            $local,
            $this->createMock(MailmanService::class),
            $this->createMock(ListmonkService::class),
        )->execute(['--backend' => 'mailmam']);
    }

    private function tester(
        MailingListService $local,
        MailmanService $mailman,
        ListmonkService $listmonk,
    ): CommandTester {
        return new CommandTester(new MailingListSyncCommand(
            $local,
            $mailman,
            $listmonk,
        ));
    }
}
