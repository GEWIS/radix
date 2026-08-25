<?php

declare(strict_types=1);

namespace App\Command\Application;

use App\Command\HoldsRunLockTrait;
use App\Service\Application\StaleRevisionCleaner;
use DateTime;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function sprintf;

/**
 * Nightly removal of work that was started and walked away from, in every revisable domain there is: activities,
 * vacancies, company profiles, bodies' pages and polls.
 *
 * Anything that is still the working head of its aggregate, was never approved and has not been written to for
 * {@see self::STALE_AFTER_DAYS} days has been abandoned, whoever's turn it was. A draft the author never finished, a
 * submission the board never got to and a rejection nobody did anything with all lapse on the same day, because a
 * month of silence about something that has already happened says the same thing in each case.
 *
 * What that means per domain, and what may not be removed regardless, is the domain's own to say through a
 * {@see \App\Service\Application\StaleRevisionPolicyInterface}; {@see StaleRevisionCleaner} does the rest.
 */
#[AsCommand(
    name: 'app:application:delete-stale-revisions',
    description: 'Delete revisions, and the things they revise, that have been abandoned for a month.',
)]
#[AsCronTask(
    expression: '15 3 * * *',
    jitter: 900,
    transports: 'gdpr',
)]
final class DeleteStaleRevisionsCommand extends Command
{
    use HoldsRunLockTrait;

    private const int STALE_AFTER_DAYS = 30;

    public function __construct(
        private readonly StaleRevisionCleaner $staleRevisionCleaner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would be removed without changing anything.',
        );
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
        $dryRun = true === $input->getOption('dry-run');
        $cutoff = new DateTime(sprintf('-%d days', self::STALE_AFTER_DAYS));

        $this->logger->info(sprintf(
            'Cleaning up revisions left untouched since %s.%s',
            $cutoff->format('Y-m-d'),
            $dryRun ? ' (dry-run)' : '',
        ));

        $report = $this->staleRevisionCleaner->clean(
            $cutoff,
            $dryRun,
        );

        $message = sprintf(
            'Reverted %d revision(s) to live, deleted %d abandoned, skipped %d, reclaimed %d file(s).%s',
            $report->reverted,
            $report->deleted,
            $report->skipped,
            $report->filesReclaimed,
            $dryRun ? ' (dry-run; nothing changed)' : '',
        );
        $this->logger->info($message);
        $io->success($message);

        return Command::SUCCESS;
    }
}
