<?php

declare(strict_types=1);

namespace App\Command\Checker;

use App\Command\HoldsRunLockTrait;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCommand(
    name: 'check:database',
    description: 'Check if the database is sound.',
)]
// Weekly rather than nightly: the report is sent whether or not anything is wrong, and one that arrives every
// morning saying the same thing stops being read. Monday, so a week's decisions are checked before the next meeting.
#[AsCronTask(
    expression: '47 6 * * 1',
    jitter: 900,
    transports: 'maintenance',
)]
class CheckDatabaseCommand extends AbstractCheckerCommand
{
    use HoldsRunLockTrait;

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        return $this->runExclusively(
            $output,
            fn (): int => $this->executeExclusively(
                $input,
                $output,
            ),
        );
    }

    private function executeExclusively(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->checkerService->check();

        return Command::SUCCESS;
    }
}
