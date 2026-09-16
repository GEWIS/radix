<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\RevisableInterface;
use App\Entity\Application\RevisionInterface;
use App\Service\Application\StaleRevisionDeletionBlock;
use App\Service\Application\StaleRevisionPolicyInterface;
use DateTimeImmutable;
use Override;

/**
 * When an activity has been abandoned. What keeps one in existence is its own schedule: a draft for next month's drink
 * that has not been opened for a month looks the same as a finished draft, and the evening itself determines whether
 * anyone is still waiting on it.
 */
final readonly class ActivityStaleRevisionPolicy implements StaleRevisionPolicyInterface
{
    #[Override]
    public function revisionClass(): string
    {
        return ActivityRevision::class;
    }

    #[Override]
    public function keepUntil(RevisionInterface $revision): ?DateTimeImmutable
    {
        if (!$revision instanceof ActivityRevision) {
            return null;
        }

        // A revision that never got as far as a schedule is a draft a user opened and closed again; there is nothing it
        // is ahead of.
        return $revision->endTime;
    }

    #[Override]
    public function deletionBlockedBy(RevisableInterface $revisable): ?StaleRevisionDeletionBlock
    {
        if (!$revisable instanceof Activity) {
            return null;
        }

        // Sign-ups are only ever on the live revision's lists, so an activity that was never approved should not have
        // any; check every revision's lists anyway before destroying anything.
        //
        // Forceable, because a sign-up that got onto a list of an activity nobody ever approved is not reachable from
        // anywhere on the site: nobody can see it, withdraw from it or be drawn on it, and the activity it belongs to
        // is never going to happen. Left alone it is skipped every night for good, so an operator who has looked at
        // what a dry run names may delete it with the activity.
        foreach ($revisable->getRevisions() as $revision) {
            foreach ($revision->getSignupLists() as $signupList) {
                if ($signupList->hasSignUps()) {
                    return StaleRevisionDeletionBlock::forceable('a sign-up list already has sign-ups');
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
