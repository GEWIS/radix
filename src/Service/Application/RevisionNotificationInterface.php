<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\RevisionInterface;
use App\Entity\User\Enums\UserRoles;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Mime\Address;

/**
 * Who a domain wants told that one of its revisions is waiting for a reviewer, and under which kind of notification.
 *
 * One per module rather than a branch in the listener that sends it, so a new revisable domain arrives with its own
 * answer instead of everybody editing the same match. The listener itself is
 * {@see \App\EventListener\Application\NotifyOnRevisionSubmissionListener}.
 */
#[AutoconfigureTag('app.revision_notification')]
interface RevisionNotificationInterface
{
    public function supports(RevisionInterface $revision): bool;

    /**
     * The kind of notification a submission of this domain's revisions raises.
     */
    public function awaitingReviewType(RevisionInterface $revision): NotificationType;

    /**
     * The role it is addressed to. A role rather than each member holding it, because who holds a role is worked out
     * from current installations rather than stored, and one row per submission beats one per reviewer either way.
     */
    public function audienceRole(RevisionInterface $revision): UserRoles;

    /**
     * The mailboxes told that a submission is waiting, which is a different question from the role above: the
     * notification reaches whoever holds the role in the website, and this reaches the office that answers for it
     * whether or not anybody has signed in lately.
     *
     * @return Address[]
     */
    public function reviewerMailboxes(RevisionInterface $revision): array;

    /**
     * The subject line of that mail. English, as all outgoing mail is, and naming the thing rather than the kind, so
     * a mailbox with several waiting can tell them apart without opening them.
     */
    public function reviewerMailSubject(RevisionInterface $revision): string;
}
