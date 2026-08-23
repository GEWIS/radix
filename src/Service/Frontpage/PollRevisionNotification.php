<?php

declare(strict_types=1);

namespace App\Service\Frontpage;

use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\RevisionInterface;
use App\Entity\Frontpage\PollRevision;
use App\Entity\User\Enums\UserRoles;
use App\Service\Application\OfficeMailboxes;
use App\Service\Application\RevisionNotificationInterface;
use Override;

use function assert;
use function sprintf;

/**
 * A question put to the whole association is the board's to agree to, and nobody else's.
 */
final readonly class PollRevisionNotification implements RevisionNotificationInterface
{
    public function __construct(private OfficeMailboxes $mailboxes)
    {
    }

    #[Override]
    public function supports(RevisionInterface $revision): bool
    {
        return $revision instanceof PollRevision;
    }

    #[Override]
    public function awaitingReviewType(RevisionInterface $revision): NotificationType
    {
        return NotificationType::PollRevisionAwaitingReview;
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
        assert($revision instanceof PollRevision);

        return sprintf(
            'Poll requested: %s',
            $revision->getQuestion()->getText(Languages::English) ?? '',
        );
    }
}
