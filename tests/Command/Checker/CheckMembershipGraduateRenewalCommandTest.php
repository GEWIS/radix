<?php

declare(strict_types=1);

namespace App\Tests\Command\Checker;

use App\Command\Checker\CheckMembershipGraduateRenewalCommand;
use App\Service\Checker\Renewal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The scheduled job mails expiring graduates a renewal link, and a command that stopped running would fail
 * silently. The test checks that the command calls the renewal service.
 */
#[CoversClass(CheckMembershipGraduateRenewalCommand::class)]
final class CheckMembershipGraduateRenewalCommandTest extends TestCase
{
    public function testSendsTheGraduateRenewals(): void
    {
        $renewal = $this->createMock(Renewal::class);
        $renewal->expects(self::once())->method('sendRenewalGraduates');

        $tester = new CommandTester(new CheckMembershipGraduateRenewalCommand($renewal));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
    }
}
