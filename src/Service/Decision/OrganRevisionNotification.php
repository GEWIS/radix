<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\RevisionInterface;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\User\Enums\UserRoles;
use App\Service\Application\OfficeMailboxes;
use App\Service\Application\RevisionNotificationInterface;
use Override;

use function assert;
use function sprintf;

/**
 * What a body writes about itself is the board's to look at, and nobody else's.
 */
final readonly class OrganRevisionNotification implements RevisionNotificationInterface
{
    public function __construct(private OfficeMailboxes $mailboxes)
    {
    }

    #[Override]
    public function supports(RevisionInterface $revision): bool
    {
        return $revision instanceof OrganInformationRevision;
    }

    #[Override]
    public function awaitingReviewType(RevisionInterface $revision): NotificationType
    {
        return NotificationType::OrganInformationRevisionAwaitingReview;
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
        assert($revision instanceof OrganInformationRevision);

        return sprintf(
            'Body page submitted for review: %s',
            $revision->getOrgan()->getAbbr(),
        );
    }
}
