<?php

declare(strict_types=1);

namespace App\ViewModel\Application;

use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Application\RevisionInterface;
use DateTimeImmutable;

use function intval;

/**
 * One line of a review queue: what is waiting, who submitted it, which revision it is and where to go to look at it.
 * Every module's queue shows the same four things, so they render through one partial rather than each writing out its
 * own table.
 */
final readonly class ReviewQueueRow
{
    public function __construct(
        public string $subject,
        public string $author,
        public int $revisionNumber,
        public RevisionStatus $status,
        public string $reviewRoute,
        public int $revisionId,
        // How long it has been waiting, which a count on its own never says.
        public DateTimeImmutable $submittedAt,
        // The revision the public is seeing while this one waits, so a queue does not read as if nothing is live.
        public ?int $liveRevisionNumber = null,
    ) {
    }

    /**
     * How long something has been waiting is what makes a queue worth looking at, so it is shown in the colour of the
     * association's own service levels: fine the first day, pressing after one, overdue after three.
     */
    public function waitingBadgeClass(): string
    {
        $days = intval($this->submittedAt->diff(new DateTimeImmutable())->days);

        return match (true) {
            $days >= 3 => 'danger',
            $days >= 1 => 'warning',
            default => 'success',
        };
    }

    /**
     * @param string $subject what the reader recognises this by, which the domain has to provide: a localised activity
     *                        name, a company name, a vacancy slug
     */
    public static function fromRevision(
        RevisionInterface $revision,
        string $subject,
        string $reviewRoute,
    ): self {
        return new self(
            subject: $subject,
            author: $revision->getAuthorDisplayName(),
            revisionNumber: $revision->getRevisionNumber(),
            status: $revision->getStatus(),
            reviewRoute: $reviewRoute,
            revisionId: (int) $revision->id,
            // Everything in a queue has been submitted; the fallback is for a revision that reached a queue before
            // the submission timestamp was recorded at all.
            submittedAt: $revision->getSubmittedAt() ?? $revision->getCreatedAt(),
            liveRevisionNumber: $revision->getLiveCounterpart()?->getRevisionNumber(),
        );
    }

    /**
     * A whole queue at once. How a revision is named is still the domain's responsibility, so it is passed in as a
     * callback rather than the loop being written out again per module.
     *
     * @param iterable<RevisionInterface>        $revisions
     * @param callable(RevisionInterface):string $subject
     *
     * @return list<self>
     */
    public static function fromRevisions(
        iterable $revisions,
        callable $subject,
        string $reviewRoute,
    ): array {
        $rows = [];
        foreach ($revisions as $revision) {
            $rows[] = self::fromRevision(
                $revision,
                $subject($revision),
                $reviewRoute,
            );
        }

        return $rows;
    }
}
