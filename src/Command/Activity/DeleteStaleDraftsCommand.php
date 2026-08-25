<?php

declare(strict_types=1);

namespace App\Command\Activity;

use App\Command\HoldsRunLockTrait;
use App\Entity\Activity\Activity;
use App\Entity\Application\Enums\RevisionStatus;
use App\Repository\Activity\ActivityRevisionRepository;
use App\Service\Application\EditLockService;
use App\Service\Application\RevisionDiscarder;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

use function sprintf;

/**
 * Removes abandoned activities. A revision that is still the working head of its activity and has not been touched
 * for long enough is considered abandoned, and either way:
 *  - if the activity already has a live (approved) revision, only the stale head is discarded and the activity falls
 *    back to its live version (an abandoned re-edit);
 *  - if the activity was never approved, the whole activity is removed.
 *
 * How long "long enough" is depends on whose turn it is. A Draft is the author's to finish, so it lapses after
 * {@see self::STALE_AFTER_DAYS} days. Everything else is either with the board (Submitted, InReview) or already
 * decided against (Rejected, Closed), and none of those lapse for {@see self::ABANDONED_AFTER_DAYS} days: a queue the
 * board is slow to work through is not the same thing as an author walking away, and a rejection is a record worth
 * keeping for a while.
 *
 * An approved head is never touched, and an activity whose sign-up lists carry sign-ups is never deleted.
 */
#[AsCommand(
    name: 'app:activity:delete-stale-drafts',
    description: 'Delete activity drafts that have been abandoned for a long time.',
)]
#[AsCronTask(
    expression: '15 3 * * *',
    jitter: 900,
    transports: 'gdpr',
)]
final class DeleteStaleDraftsCommand extends Command
{
    use HoldsRunLockTrait;

    private const int STALE_AFTER_DAYS = 30;

    private const int ABANDONED_AFTER_DAYS = 90;

    public function __construct(
        private readonly ActivityRevisionRepository $activityRevisionRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RevisionDiscarder $draftDiscarder,
        private readonly EditLockService $editLockService,
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
        $draftCutoff = new DateTime(sprintf('-%d days', self::STALE_AFTER_DAYS));
        $abandonedCutoff = new DateTime(sprintf('-%d days', self::ABANDONED_AFTER_DAYS));

        $reverted = 0;
        $deleted = 0;
        $skipped = 0;

        $this->logger->info(sprintf(
            'Cleaning up activity drafts untouched since %s, and anything else still open since %s.%s',
            $draftCutoff->format('Y-m-d'),
            $abandonedCutoff->format('Y-m-d'),
            $dryRun ? ' (dry-run)' : '',
        ));

        $heads = [
            ...$this->activityRevisionRepository->findStaleHeads(
                $draftCutoff,
                RevisionStatus::Draft,
            ),
            ...$this->activityRevisionRepository->findStaleHeads(
                $abandonedCutoff,
                RevisionStatus::Submitted,
                RevisionStatus::InReview,
                RevisionStatus::Rejected,
                RevisionStatus::Closed,
            ),
        ];

        foreach ($heads as $revision) {
            $activity = $revision->getActivity();

            if (null !== $activity->getLiveRevision()) {
                if (!$dryRun) {
                    $this->draftDiscarder->discardToLive($revision);
                }

                ++$reverted;
                $this->logger->info(sprintf(
                    'Activity #%d: discarded abandoned %s revision #%d; reverted to the live version.',
                    $activity->getId(),
                    $revision->getStatus()->value,
                    $revision->getId(),
                ));

                continue;
            }

            if ($this->hasSignUps($activity)) {
                ++$skipped;
                $this->logger->warning(sprintf(
                    'Activity #%d: abandoned %s revision kept because a sign-up list already has sign-ups.',
                    $activity->getId(),
                    $revision->getStatus()->value,
                ));

                continue;
            }

            if (!$dryRun) {
                $this->deleteActivity($activity);
            }

            ++$deleted;
            $this->logger->info(sprintf(
                'Activity #%d: deleted entirely (never approved, abandoned as %s).',
                $activity->getId(),
                $revision->getStatus()->value,
            ));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $message = sprintf(
            'Reverted %d draft(s) to live, deleted %d abandoned activit%s, skipped %d.%s',
            $reverted,
            $deleted,
            1 === $deleted ? 'y' : 'ies',
            $skipped,
            $dryRun ? ' (dry-run; nothing changed)' : '',
        );
        $this->logger->info($message);
        $io->success($message);

        return Command::SUCCESS;
    }

    private function hasSignUps(Activity $activity): bool
    {
        // Sign-ups only ever live on the live revision's lists, but check every revision's lists defensively before
        // destroying anything.
        foreach ($activity->getRevisions() as $revision) {
            foreach ($revision->getSignupLists() as $signupList) {
                if ($signupList->hasSignUps()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fully remove a never-approved activity and its dependent rows. There is deliberately no Doctrine cascade on
     * Activity::$revisions, so each revision is removed explicitly (which cascade-removes its own sign-up lists,
     * fields, options and texts); the activity -> revision and revision -> previousRevision foreign keys are nulled
     * first so the deletes are unambiguous.
     */
    private function deleteActivity(Activity $activity): void
    {
        // Atomic per activity: the FK-nulling and the row removals are two separate flushes (the nulls must reach the
        // database first so the deletes are unambiguous), so wrap both in a single transaction. Otherwise a crash
        // between the flushes would commit the half-deleted state (an activity pointing at no revision while its
        // revisions still exist, which getDisplayRevision() then fails on) until the next run repairs it.
        $this->entityManager->wrapInTransaction(function () use ($activity): void {
            // The edit lock (if any) has no foreign key to the activity, so drop it explicitly before it goes.
            $this->editLockService->purge($activity);

            $activity->setCurrentRevision(null);
            $activity->setLiveRevision(null);

            foreach ($activity->getRevisions() as $revision) {
                $revision->setPreviousRevision(null);
            }

            $this->entityManager->flush();

            foreach ($activity->getRevisions() as $revision) {
                $this->draftDiscarder->removeRevision($revision);
            }

            $this->entityManager->remove($activity);
            $this->entityManager->flush();
        });
    }
}
