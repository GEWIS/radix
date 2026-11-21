<?php

declare(strict_types=1);

namespace App\Tests\Command\Database;

use App\Command\Database\MailingListFetchListsCommand;
use App\Service\Database\ListmonkService;
use App\Service\Database\MailmanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command refreshes the register from the backend a run names. A fetch from the wrong server would
 * replace the register and every binding.
 */
#[CoversClass(MailingListFetchListsCommand::class)]
final class MailingListFetchListsCommandTest extends TestCase
{
    public function testFetchesFromBothBackendsByDefault(): void
    {
        $mailman = $this->createMock(MailmanService::class);
        $mailman->expects(self::once())->method('fetchMailingLists');

        $listmonk = $this->createMock(ListmonkService::class);
        $listmonk->expects(self::once())->method('fetchMailingLists');

        $tester = $this->tester(
            $listmonk,
            $mailman,
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Fetching mailing lists from all backends:',
            $tester->getDisplay(),
        );
    }

    public function testFetchesFromMailmanAlone(): void
    {
        $mailman = $this->createMock(MailmanService::class);
        $mailman->expects(self::once())->method('fetchMailingLists');

        $listmonk = $this->createMock(ListmonkService::class);
        $listmonk->expects(self::never())->method(self::anything());

        $tester = $this->tester(
            $listmonk,
            $mailman,
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--backend' => 'mailman']),
        );
        self::assertStringContainsString(
            'Fetching mailing lists from mailman:',
            $tester->getDisplay(),
        );
    }

    public function testFetchesFromListmonkAlone(): void
    {
        $listmonk = $this->createMock(ListmonkService::class);
        $listmonk->expects(self::once())->method('fetchMailingLists');

        $mailman = $this->createMock(MailmanService::class);
        $mailman->expects(self::never())->method(self::anything());

        $tester = $this->tester(
            $listmonk,
            $mailman,
        );

        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--backend' => 'listmonk']),
        );
        self::assertStringContainsString(
            'Fetching mailing lists from listmonk:',
            $tester->getDisplay(),
        );
    }

    /**
     * `local` is not a backend of this command, so it is refused instead of fetching from both.
     */
    public function testALocalBackendIsRefused(): void
    {
        $listmonk = $this->createMock(ListmonkService::class);
        $listmonk->expects(self::never())->method(self::anything());

        $mailman = $this->createMock(MailmanService::class);
        $mailman->expects(self::never())->method(self::anything());

        $this->expectException(InvalidArgumentException::class);

        $this->tester($listmonk, $mailman)->execute(['--backend' => 'local']);
    }

    private function tester(
        ListmonkService $listmonk,
        MailmanService $mailman,
    ): CommandTester {
        return new CommandTester(new MailingListFetchListsCommand(
            $listmonk,
            $mailman,
        ));
    }
}
