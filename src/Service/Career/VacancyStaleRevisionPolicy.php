<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Application\RevisableInterface;
use App\Entity\Application\RevisionInterface;
use App\Entity\Career\VacancyRevision;
use App\Service\Application\StaleRevisionPolicyInterface;
use DateTime;
use Override;

/**
 * When a vacancy has been walked away from. A posting is dated the way an activity is: while applications can still
 * come in, silence from the company means the advertisement is doing its job, not that it was forgotten. The
 * attachment a vacancy names is a link the company hosts itself, so there is nothing stored to reclaim.
 */
final readonly class VacancyStaleRevisionPolicy implements StaleRevisionPolicyInterface
{
    #[Override]
    public function revisionClass(): string
    {
        return VacancyRevision::class;
    }

    #[Override]
    public function keepUntil(RevisionInterface $revision): ?DateTime
    {
        if (!$revision instanceof VacancyRevision) {
            return null;
        }

        $endDate = $revision->getEndDate();
        if (null === $endDate) {
            return null;
        }

        // The closing day counts in full: a vacancy shown until Friday is still open all of Friday. Cloned because
        // the date belongs to the revision, and moving it here would be an edit nobody asked for.
        return (clone $endDate)->setTime(
            23,
            59,
            59,
        );
    }

    #[Override]
    public function deletionBlockedBy(RevisableInterface $revisable): ?string
    {
        return null;
    }

    #[Override]
    public function storedPaths(RevisionInterface $revision): array
    {
        return [];
    }
}
