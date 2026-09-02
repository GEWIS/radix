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
 *
 * A domain that refuses to let an aggregate go says whether the refusal may be overruled, and `--force` overrules the
 * ones that may be. In practice that is the activity whose sign-up lists have sign-ups on them: an activity nobody
 * ever approved is not reachable from anywhere on the site, so neither are the sign-ups on it, and the pair sits in
 * the skipped column of every nightly run for good. A vote, a comment, a sold package and a representative's account
 * are not overruled by anything, forced or not.
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
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Also remove abandoned activities that have sign-ups on them, which a scheduled run leaves standing.',
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
        $force = true === $input->getOption('force');
        $cutoff = new DateTime(sprintf('-%d days', self::STALE_AFTER_DAYS));

        // Only the run that is actually going to do it asks. A dry run reports what forcing would reach, which is the
        // list an operator wants in front of them before answering this, and answering "no" here would hide it.
        if (
            $force
            && !$dryRun
            && !$io->confirm(
                'Forcing removes abandoned activities together with the sign-ups on them, which cannot be undone. '
                . 'Do you want to continue?',
                !$input->isInteractive(),
            )
        ) {
            return Command::SUCCESS;
        }

        $this->logger->info(sprintf(
            'Cleaning up revisions left untouched since %s.%s%s',
            $cutoff->format('Y-m-d'),
            $dryRun ? ' (dry-run)' : '',
            $force ? ' (forced)' : '',
        ));

        $report = $this->staleRevisionCleaner->clean(
            $cutoff,
            $dryRun,
            $force,
        );

        $message = sprintf(
            'Reverted %d revision(s) to live, deleted %d abandoned (%d forced), skipped %d, reclaimed %d file(s).%s',
            $report->reverted,
            $report->deleted,
            $report->forced,
            $report->skipped,
            $report->filesReclaimed,
            $dryRun ? ' (dry-run; nothing changed)' : '',
        );
        $this->logger->info($message);
        $io->success($message);

        return Command::SUCCESS;
    }
}
