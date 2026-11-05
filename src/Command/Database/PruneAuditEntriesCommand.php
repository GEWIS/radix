<?php

declare(strict_types=1);

namespace App\Command\Database;

use App\Command\HoldsRunLockTrait;
use App\Repository\Database\AuditEntryRepository;
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
    name: 'app:database:prune-audit-entries',
    description: 'Forget audit log entries older than the retention period.',
)]
#[AsCronTask(
    expression: '50 3 * * *',
    transports: 'gdpr',
)]
final class PruneAuditEntriesCommand extends Command
{
    use HoldsRunLockTrait;

    /**
     * How long the personal data in an entry stays answerable for.
     *
     * Half a year covers the questions this table exists to answer: a member asking about a change to their details,
     * the secretary looking into how an address was updated, and a pattern of changes that only becomes visible over
     * a long window. After that, the entries remain but their personal data is anonymized, preserving the audit trail
     * while still meeting the data-minimization requirement: we know a change happened, but not what it changed from
     * and to. The SecurityLog rows recording the same events are kept a little longer than this on purpose
     * (see `config/packages/monolog.yaml`), so the file can still be read for a period the table no longer covers.
     */
    private const string RETENTION = '-180 days';

    public function __construct(private readonly AuditEntryRepository $repository)
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

        $anonymized = $this->repository->anonymizePersonalDataEntriesOccurredBefore(
            new DateTimeImmutable(self::RETENTION),
        );

        $io->success(sprintf(
            'Anonymized personal data in %d audit log entr%s.',
            $anonymized,
            1 !== $anonymized ? 'ies' : 'y',
        ));

        return Command::SUCCESS;
    }
}
