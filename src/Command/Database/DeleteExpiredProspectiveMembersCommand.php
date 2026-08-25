<?php

declare(strict_types=1);

namespace App\Command\Database;

use App\Command\HoldsRunLockTrait;
use App\Service\Database\Member as MemberService;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCommand(
    name: 'database:prospective-members:delete-expired',
    description: 'Delete prospective members whose Checkout Session has expired or failed.',
)]
#[AsCronTask(
    expression: '0 2 * * *',
    transports: 'gdpr',
)]
class DeleteExpiredProspectiveMembersCommand extends Command
{
    use HoldsRunLockTrait;

    public function __construct(private readonly MemberService $memberService)
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
        $output->writeln('Deleting expired prospective members...');
        $this->memberService->removeExpiredProspectiveMembers();

        return Command::SUCCESS;
    }
}
