<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\RevisableInterface;
use App\Entity\Application\RevisionInterface;
use App\Service\Application\StaleRevisionPolicyInterface;
use DateTime;
use Override;

/**
 * When an activity has been walked away from. What keeps one alive is its own schedule: nobody looking at next
 * month's drink for a month is how a finished draft behaves, and the evening itself is what says whether anyone is
 * still waiting on it.
 */
final readonly class ActivityStaleRevisionPolicy implements StaleRevisionPolicyInterface
{
    #[Override]
    public function revisionClass(): string
    {
        return ActivityRevision::class;
    }

    #[Override]
    public function keepUntil(RevisionInterface $revision): ?DateTime
    {
        if (!$revision instanceof ActivityRevision) {
            return null;
        }

        // A revision that never got as far as a schedule is a draft somebody opened and closed again; there is
        // nothing it is ahead of.
        return $revision->getEndTime();
    }

    #[Override]
    public function deletionBlockedBy(RevisableInterface $revisable): ?string
    {
        if (!$revisable instanceof Activity) {
            return null;
        }

        // Sign-ups only ever live on the live revision's lists, so an activity that was never approved should not
        // have any; check every revision's lists anyway before destroying anything.
        foreach ($revisable->getRevisions() as $revision) {
            foreach ($revision->getSignupLists() as $signupList) {
                if ($signupList->hasSignUps()) {
                    return 'a sign-up list already has sign-ups';
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
