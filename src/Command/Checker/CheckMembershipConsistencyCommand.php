<?php

declare(strict_types=1);

namespace App\Command\Checker;

use App\Command\HoldsRunLockTrait;
use App\Service\Checker\Membership as MembershipService;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCommand(
    name: 'check:membership:consistency',
    description: 'Check that the memberships of every member run in order and do not overlap.',
)]
// Weekly, beside the database check: a report that arrives every day stops being read.
#[AsCronTask(
    expression: '52 6 * * 1',
    jitter: 900,
    transports: 'maintenance',
)]
class CheckMembershipConsistencyCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(private readonly MembershipService $membershipService)
    {
        parent::__construct();
    }

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
        $this->membershipService->check();

        return Command::SUCCESS;
    }
}
