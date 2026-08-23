<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\Enums\RevisionStatus;
use App\Repository\Activity\ActivityRevisionRepository;
use App\Service\Activity\ActivityFacilityNotifier;
use App\Service\Application\OfficeMailboxes;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Mime\Email;

use function array_map;

/**
 * GEFLITST are told the moment an activity asks for a photographer, and their Planka board is addressed on the same
 * message so that it files a card and a reply reaches everybody. The board is named by a header rather than by
 * anything in the body, so these pin the envelope rather than the words.
 */
final class ActivityFacilityNotifierTest extends DatabaseTestCase
{
    public function testAskingForAPhotographerWritesToGeflitstAndTheirBoard(): void
    {
        $revision = $this->draft();
        $revision->setRequireGEFLITST(true);

        self::getContainer()->get(ActivityFacilityNotifier::class)->created($revision);

        $mailboxes = self::getContainer()->get(OfficeMailboxes::class);
        $message = $this->onlyMessage();

        // Against the configured mailboxes rather than literal addresses: what matters is that both are written to,
        // and a deployment is free to name them anything.
        self::assertEqualsCanonicalizing(
            [
                $mailboxes->geflitst()->getAddress(),
                $mailboxes->geflitstPlanka()->getAddress(),
            ],
            array_map(
                static fn (object $address): string => $address->getAddress(),
                $message->getTo(),
            ),
        );
        self::assertSame(
            $mailboxes->geflitstPlankaKey(),
            $message->getHeaders()->get('X-Planka-Board-Id')?->getBodyAsString(),
        );
    }

    /**
     * Planka answers by replying, so the reply has to reach whoever asked rather than the association at large.
     */
    public function testTheRequesterIsWhoAReplyReaches(): void
    {
        $revision = $this->draft();
        $revision->setRequireGEFLITST(true);

        self::getContainer()->get(ActivityFacilityNotifier::class)->created($revision);

        self::assertNotSame(
            [],
            $this->onlyMessage()->getReplyTo(),
        );
    }

    public function testAnActivityThatAsksForNothingSendsNothing(): void
    {
        $revision = $this->draft();
        $revision->setRequireGEFLITST(false);
        $revision->setRequireZettle(false);

        self::getContainer()->get(ActivityFacilityNotifier::class)->created($revision);

        self::assertSame(
            [],
            self::getMailerMessages(),
        );
    }

    private function onlyMessage(): Email
    {
        $messages = self::getMailerMessages();
        self::assertNotEmpty($messages);

        $message = $messages[0];
        self::assertInstanceOf(
            Email::class,
            $message,
        );

        return $message;
    }

    private function draft(): ActivityRevision
    {
        foreach (self::getContainer()->get(ActivityRevisionRepository::class)->findAll() as $revision) {
            if (RevisionStatus::Draft !== $revision->getStatus()) {
                continue;
            }

            return $revision;
        }

        self::fail('The seed is expected to contain a draft activity.');
    }
}
