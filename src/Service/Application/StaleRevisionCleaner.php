<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\RevisableInterface;
use App\Repository\Application\StaleRevisionRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_unique;
use function array_values;
use function sprintf;

/**
 * Removes work that was started and walked away from, in every revisable domain at once.
 *
 * A revision that is still the working head of its aggregate, was never approved and has not been written to since
 * the cutoff is abandoned, and either way:
 *  - if the aggregate already has a live (approved) revision, only the stale head is discarded and it falls back to
 *    its live version (an abandoned re-edit);
 *  - if it was never approved at all, the whole aggregate is removed.
 *
 * What that means in a domain is the domain's to say, through a {@see StaleRevisionPolicyInterface}: whether the
 * thing it describes has happened yet, what would make removing it wrong, and which stored files would be orphaned by
 * removing a revision. Nothing here knows what an activity, a vacancy or a poll is.
 *
 * Files are reclaimed last, after the rows that named them are committed, because {@see FileStorage::remove()} asks
 * every domain whether the path is still referenced and must be answered from committed state. A crash between the
 * two leaves a file nothing points at, which costs disk and nothing else; the opposite order would take the bytes
 * out from under a revision that survived.
 */
final readonly class StaleRevisionCleaner
{
    public function __construct(
        /** @var iterable<StaleRevisionPolicyInterface> */
        #[AutowireIterator('app.stale_revision_policy')]
        private iterable $policies,
        private StaleRevisionRepository $staleRevisions,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private RevisionDiscarder $revisionDiscarder,
        private EditLockService $editLockService,
        private FileStorage $fileStorage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Clean up everything abandoned since the cutoff. A dry run counts and logs exactly what a real run would do, and
     * touches neither the database nor the storage.
     */
    public function clean(
        DateTime $cutoff,
        bool $dryRun = false,
    ): StaleRevisionCleanupReport {
        $now = new DateTime();
        $reverted = 0;
        $deleted = 0;
        $skipped = 0;

        /** @var list<string> $orphanedPaths */
        $orphanedPaths = [];

        foreach ($this->policies as $policy) {
            foreach (
                $this->staleRevisions->findUntouchedSince(
                    $policy->revisionClass(),
                    $cutoff,
                ) as $revision
            ) {
                $revisable = $revision->getRevisable();

                // Anything behind the head is the chain's history, which is not abandoned work but the record of how
                // the live version came about.
                if ($revisable->getCurrentRevision()?->getId() !== $revision->getId()) {
                    continue;
                }

                $keepUntil = $policy->keepUntil($revision);
                if (
                    null !== $keepUntil
                    && $keepUntil > $now
                ) {
                    continue;
                }

                if (null !== $revisable->getLiveRevision()) {
                    if (!$dryRun) {
                        $this->revisionDiscarder->discardToLive($revision);
                    }

                    foreach ($policy->storedPaths($revision) as $path) {
                        $orphanedPaths[] = $path;
                    }

                    ++$reverted;
                    $this->logger->info(sprintf(
                        '%s #%d: discarded abandoned %s revision #%d; reverted to the live version.',
                        $revisable->getResourceId(),
                        $revisable->getId() ?? 0,
                        $revision->getStatus()->value,
                        $revision->getId() ?? 0,
                    ));

                    continue;
                }

                $blockedBy = $policy->deletionBlockedBy($revisable);
                if (null !== $blockedBy) {
                    ++$skipped;
                    $this->logger->warning(sprintf(
                        '%s #%d: abandoned %s revision kept because %s.',
                        $revisable->getResourceId(),
                        $revisable->getId() ?? 0,
                        $revision->getStatus()->value,
                        $blockedBy,
                    ));

                    continue;
                }

                foreach ($revisable->getRevisions() as $chained) {
                    foreach ($policy->storedPaths($chained) as $path) {
                        $orphanedPaths[] = $path;
                    }
                }

                if (!$dryRun) {
                    $this->deleteRevisable($revisable);
                }

                ++$deleted;
                $this->logger->info(sprintf(
                    '%s #%d: deleted entirely (never approved, abandoned as %s).',
                    $revisable->getResourceId(),
                    $revisable->getId() ?? 0,
                    $revision->getStatus()->value,
                ));
            }
        }

        $orphanedPaths = array_values(array_unique($orphanedPaths));

        if ($dryRun) {
            return new StaleRevisionCleanupReport(
                $reverted,
                $deleted,
                $skipped,
                0,
            );
        }

        $this->entityManager->flush();

        return new StaleRevisionCleanupReport(
            $reverted,
            $deleted,
            $skipped,
            $this->reclaim($orphanedPaths),
        );
    }

    /**
     * Fully remove a never-approved aggregate and its chain. No revisable domain cascades its revisions (a chain
     * outlives the drafts in it, and a discard removes one revision without touching the rest), so each revision is
     * removed explicitly, which cascade-removes what hangs off it; the aggregate -> revision and revision ->
     * previousRevision foreign keys are nulled first so the deletes are unambiguous.
     */
    private function deleteRevisable(RevisableInterface $revisable): void
    {
        // Atomic per aggregate: the FK-nulling and the row removals are two separate flushes (the nulls must reach
        // the database first so the deletes are unambiguous), so wrap both in a single transaction. Otherwise a crash
        // between the flushes would commit the half-deleted state — an aggregate pointing at no revision while its
        // revisions still exist, which every "what do I display" accessor then fails on — until the next run repairs
        // it.
        $this->entityManager->wrapInTransaction(function () use ($revisable): void {
            // The edit lock (if any) has no foreign key to the aggregate, so drop it explicitly before it goes.
            $this->editLockService->purge($revisable);

            $revisable->detachRevisions();

            foreach ($revisable->getRevisions() as $revision) {
                $revision->detachPreviousRevision();
            }

            $this->entityManager->flush();

            foreach ($revisable->getRevisions() as $revision) {
                $this->revisionDiscarder->removeRevision($revision);
            }

            $this->entityManager->remove($revisable);
            $this->entityManager->flush();
        });
    }

    /**
     * Hand every path the removed rows named back to storage, which unlinks the ones no domain claims any more.
     *
     * @param list<string> $paths
     */
    private function reclaim(array $paths): int
    {
        $reclaimed = 0;

        foreach ($paths as $path) {
            if (!$this->fileStorage->remove($path)) {
                continue;
            }

            ++$reclaimed;
            $this->logger->info(sprintf(
                'Reclaimed "%s": nothing references it any more.',
                $path,
            ));
        }

        return $reclaimed;
    }
}
