<?php

declare(strict_types=1);

namespace App\Tests\Command\Checker;

use App\Command\Checker\CheckDischargesCommand;
use App\Service\Checker\Checker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The discharge command must call `checkDischarges()`, not `check()`, which mails a different report.
 */
#[CoversClass(CheckDischargesCommand::class)]
final class CheckDischargesCommandTest extends TestCase
{
    public function testChecksDischargesAndNotTheWholeDatabase(): void
    {
        $checker = $this->createMock(Checker::class);
        $checker->expects(self::never())->method('check');
        $checker->expects(self::once())->method('checkDischarges');

        $tester = new CommandTester(new CheckDischargesCommand($checker));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
    }
}
