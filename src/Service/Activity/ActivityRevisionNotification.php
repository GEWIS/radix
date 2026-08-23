<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\RevisionInterface;
use App\Entity\User\Enums\UserRoles;
use App\Service\Application\OfficeMailboxes;
use App\Service\Application\RevisionNotificationInterface;
use Override;

use function assert;
use function sprintf;

/**
 * An activity waiting to be published is the board's to look at.
 */
final readonly class ActivityRevisionNotification implements RevisionNotificationInterface
{
    public function __construct(private OfficeMailboxes $mailboxes)
    {
    }

    #[Override]
    public function supports(RevisionInterface $revision): bool
    {
        return $revision instanceof ActivityRevision;
    }

    #[Override]
    public function awaitingReviewType(RevisionInterface $revision): NotificationType
    {
        return NotificationType::ActivityAwaitingReview;
    }

    #[Override]
    public function audienceRole(RevisionInterface $revision): UserRoles
    {
        return UserRoles::Board;
    }

    #[Override]
    public function reviewerMailboxes(RevisionInterface $revision): array
    {
        return [$this->mailboxes->internalAffairs()];
    }

    #[Override]
    public function reviewerMailSubject(RevisionInterface $revision): string
    {
        assert($revision instanceof ActivityRevision);

        return sprintf(
            'Activity submitted for review: %s',
            $revision->getName()->getText(Languages::English) ?? '',
        );
    }
}
