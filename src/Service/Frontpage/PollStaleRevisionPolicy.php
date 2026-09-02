<?php

declare(strict_types=1);

namespace App\Service\Frontpage;

use App\Entity\Application\RevisableInterface;
use App\Entity\Application\RevisionInterface;
use App\Entity\Frontpage\Poll;
use App\Entity\Frontpage\PollRevision;
use App\Service\Application\StaleRevisionDeletionBlock;
use App\Service\Application\StaleRevisionPolicyInterface;
use DateTime;
use Override;

/**
 * When a question put to the members has been walked away from. A poll is dated by the expiry the reviewer gave it,
 * so one still open for answers is kept whatever the silence around it; a question that was never approved never got
 * a date, and lapses on silence alone.
 *
 * Everything anyone answered with stands in the way of removing it. A poll that was never approved should have
 * neither votes nor discussion, but both are wiped by the database along with the poll, so both are checked.
 */
final readonly class PollStaleRevisionPolicy implements StaleRevisionPolicyInterface
{
    #[Override]
    public function revisionClass(): string
    {
        return PollRevision::class;
    }

    #[Override]
    public function keepUntil(RevisionInterface $revision): ?DateTime
    {
        $poll = $revision->getRevisable();
        if (!$poll instanceof Poll) {
            return null;
        }

        $expiryDate = $poll->getExpiryDate();
        if (null === $expiryDate) {
            return null;
        }

        // A poll closes at the end of its expiry date, so it is still being answered all of that day. Cloned because
        // the date belongs to the poll, and moving it here would be an edit nobody asked for.
        return (clone $expiryDate)->setTime(
            23,
            59,
            59,
        );
    }

    #[Override]
    public function deletionBlockedBy(RevisableInterface $revisable): ?StaleRevisionDeletionBlock
    {
        if (!$revisable instanceof Poll) {
            return null;
        }

        if (!$revisable->getComments()->isEmpty()) {
            return StaleRevisionDeletionBlock::hard('it has already been commented on');
        }

        foreach ($revisable->getRevisions() as $revision) {
            foreach ($revision->getOptions() as $option) {
                if ($option->getVotesCount() > 0) {
                    return StaleRevisionDeletionBlock::hard('somebody has already voted on it');
                }
            }
        }

        return null;
    }

    #[Override]
    public function storedPaths(RevisionInterface $revision): array
    {
        return [];
    }
}
