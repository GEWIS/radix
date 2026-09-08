<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\HoldsRunLockTrait;
use App\Repository\User\SecurityLogRepository;
use DateTimeImmutable;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function sprintf;

#[AsCommand(
    name: 'app:user:prune-security-log',
    description: 'Forget security events older than the retention period.',
)]
#[AsCronTask(
    expression: '45 3 * * *',
    jitter: 900,
    transports: 'gdpr',
)]
final class PruneSecurityLogCommand extends Command
{
    use HoldsRunLockTrait;

    /**
     * How long an event stays answerable for.
     *
     * Half a year covers the questions this table exists to answer: a member asking about a sign-in they do not
     * recognise, the board looking into an account months after the fact, and a pattern of failed sign-ins that
     * only becomes visible over a long window. Nothing here is worth keeping past the point where somebody could
     * still reasonably ask. The log files are kept a little longer than this on purpose (see
     * `config/packages/monolog.yaml`), so the file can still be read for a period the table has already forgotten.
     */
    private const string RETENTION = '-180 days';

    public function __construct(private readonly SecurityLogRepository $repository)
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
        $io = new SymfonyStyle(
            $input,
            $output,
        );

        $pruned = $this->repository->deleteOccurredBefore(new DateTimeImmutable(self::RETENTION));

        $io->success(sprintf(
            'Forgot %d security event%s.',
            $pruned,
            1 !== $pruned ? 's' : '',
        ));

        return Command::SUCCESS;
    }
}
