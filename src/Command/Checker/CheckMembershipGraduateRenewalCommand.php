<?php

declare(strict_types=1);

namespace App\Command\Checker;

use App\Command\HoldsRunLockTrait;
use App\Service\Checker\Renewal as RenewalService;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCommand(
    name: 'check:membership:renewal:graduate',
    description: 'Check graduates who are expiring at the end of the association year and send renewal emails.',
)]
#[AsCronTask(
    expression: '*/30 * * * *',
    transports: 'maintenance',
)]
class CheckMembershipGraduateRenewalCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(private readonly RenewalService $renewalService)
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
        $this->renewalService->sendRenewalGraduates();

        return Command::SUCCESS;
    }
}
