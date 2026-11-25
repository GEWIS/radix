<?php

declare(strict_types=1);

namespace App\Tests\Service\Decision;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\RevisionInterface;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\User\Enums\UserRoles;
use App\Service\Application\AssociationMailboxes;
use App\Service\Decision\OrganRevisionNotification;
use PHPUnit\Framework\TestCase;

/**
 * A body writing about itself is reviewed by the board only, and the internal-affairs mailbox is told alongside the
 * members who hold that role.
 */
final class OrganRevisionNotificationTest extends TestCase
{
    public function testItOnlySupportsBodyPageRevisions(): void
    {
        $notification = $this->notification();

        self::assertTrue($notification->supports($this->revision('BAC')));
        self::assertFalse($notification->supports(self::createStub(RevisionInterface::class)));
    }

    public function testTheReviewIsRaisedAsABodyPageAwaitingReview(): void
    {
        $notification = $this->notification();
        $revision = $this->revision('BAC');

        self::assertSame(
            NotificationType::OrganInformationRevisionAwaitingReview,
            $notification->awaitingReviewType($revision),
        );
        self::assertSame(
            UserRoles::Board,
            $notification->audienceRole($revision),
        );
    }

    public function testTheReviewersAreReachedAtInternalAffairs(): void
    {
        $mailboxes = $this->notification()->reviewerMailboxes($this->revision('BAC'));

        self::assertCount(
            1,
            $mailboxes,
        );
        self::assertSame(
            'internal@gewis.nl',
            $mailboxes[0]->getAddress(),
        );
        self::assertSame(
            'Internal affairs',
            $mailboxes[0]->getName(),
        );
    }

    public function testTheSubjectNamesTheBodyNotTheRevision(): void
    {
        $notification = $this->notification();
        $revision = $this->revision('BAC');

        self::assertSame(
            'Body page submitted for review: BAC',
            $notification->reviewerMailSubject($revision),
        );
        self::assertCount(
            1,
            $notification->reviewerMailboxes($revision),
        );
    }

    private function notification(): OrganRevisionNotification
    {
        return new OrganRevisionNotification(new AssociationMailboxes(
            'internal@gewis.nl',
            'Internal affairs',
            'external@gewis.nl',
            'External affairs',
            'c4@gewis.nl',
            'C4',
            'geflitst@gewis.nl',
            'GEFLITST',
            'kaneo@gewis.nl',
            'GEFLITST Kaneo',
            'project-1',
            'treasurer@gewis.nl',
            'Treasurer',
        ));
    }

    private function revision(string $abbr): OrganInformationRevision
    {
        $organ = new Organ();
        $organ->abbr = $abbr;

        $information = new OrganInformation();
        $information->organ = $organ;

        $revision = new OrganInformationRevision();
        $revision->organInformation = $information;

        return $revision;
    }
}
