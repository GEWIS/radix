<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\Enums\Languages;
use App\Service\Application\Email;
use App\Service\Application\OfficeMailboxes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

use function is_array;
use function sprintf;

/**
 * Tells GEFLITST and the treasurer that an activity is going to need them.
 *
 * Sent the moment the box is ticked on a draft rather than when the activity is submitted for review, because both
 * have to arrange people or equipment and the board may take days to decide. An activity that is never approved
 * therefore costs them a message they did not need, which is the cheaper of the two mistakes.
 *
 * Only the flip from off to on sends: a draft cloned from a revision that already asked for a photographer is not a
 * new request, and neither is saving the same draft again.
 *
 * The GEFLITST message is addressed to their Planka board as well as to themselves, carrying the board id in an
 * `X-Planka-Board-Id` header, which is what files it as a card. The subject format, the reply-to and that header are
 * kept exactly as the old website sent them, the board keying on all three.
 */
final readonly class ActivityFacilityNotifier
{
    public function __construct(
        private Email $email,
        private OfficeMailboxes $mailboxes,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The first revision of a brand-new activity: whatever it asks for, it asks for now.
     */
    public function created(ActivityRevision $revision): void
    {
        $this->notify(
            $revision,
            $revision->getRequireGEFLITST(),
            $revision->getRequireZettle(),
        );
    }

    /**
     * A draft being saved. What changed is read from the unit of work before the flush, so that ticking a box sends
     * and leaving it ticked does not.
     */
    public function draftSaved(ActivityRevision $revision): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();

        if (null === $revision->getId()) {
            $this->created($revision);

            return;
        }

        $unitOfWork->recomputeSingleEntityChangeSet(
            $this->entityManager->getClassMetadata(ActivityRevision::class),
            $revision,
        );
        $changes = $unitOfWork->getEntityChangeSet($revision);

        $this->notify(
            $revision,
            $this->justEnabled(
                $changes,
                'requireGEFLITST',
            ),
            $this->justEnabled(
                $changes,
                'requireZettle',
            ),
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function justEnabled(
        array $changes,
        string $field,
    ): bool {
        $change = $changes[$field] ?? null;

        return is_array($change)
            && true === ($change[1] ?? null)
            && true !== ($change[0] ?? null);
    }

    private function notify(
        ActivityRevision $revision,
        bool $geflitst,
        bool $zettle,
    ): void {
        if ($geflitst) {
            $this->requestGeflitst($revision);
        }

        if (!$zettle) {
            return;
        }

        $this->email->send(
            $this->mailboxes->treasurer(),
            sprintf(
                'Zettle requested: %s on %s',
                $this->title($revision),
                $this->when($revision),
            ),
            'emails/activity/facility-zettle.html.twig',
            [
                'name' => $this->title($revision),
                'beginTime' => $revision->getBeginTime(),
                'requester' => $this->requester($revision),
            ],
        );
    }

    /**
     * The subject and the reply-to are what GEFLITST's Planka board reads: it files the request under the subject and
     * expects a reply to reach whoever asked, which is the body when it has an address of its own and the member who
     * filled the form in when it does not.
     */
    private function requestGeflitst(ActivityRevision $revision): void
    {
        $organ = $revision->getOrgan();
        $requester = $this->requester($revision);

        $subject = null === $organ
            ? sprintf(
                'Member Initiative: %s on %s',
                $this->title($revision),
                $this->when($revision),
            )
            : sprintf(
                '%s: %s on %s',
                $organ->getAbbr(),
                $this->title($revision),
                $this->when($revision),
            );

        $author = $revision->getAuthor();
        $organEmail = $organ?->getOrganInformation()?->getEmail();
        $memberEmail = $author?->getEmail();
        $replyTo = match (true) {
            null !== $organEmail => new Address(
                $organEmail,
                $organ?->getAbbr() ?? '',
            ),
            null !== $memberEmail => new Address(
                $memberEmail,
                $author?->getFullName() ?? '',
            ),
            default => null,
        };

        // The Planka board is addressed on the same message rather than sent a copy: it files the card and GEFLITST
        // answer by replying to all, which only works while both are recipients of the one message.
        $this->email->send(
            $this->mailboxes->geflitst(),
            $subject,
            'emails/activity/facility-geflitst.html.twig',
            [
                'nameNL' => $revision->getName()->getText(Languages::Dutch),
                'nameEN' => $revision->getName()->getText(Languages::English),
                'descriptionNL' => $revision->getDescription()->getText(Languages::Dutch),
                'descriptionEN' => $revision->getDescription()->getText(Languages::English),
                'requester' => $requester,
            ],
            $replyTo,
            alsoTo: [$this->mailboxes->geflitstPlanka()],
            headers: ['X-Planka-Board-Id' => $this->mailboxes->geflitstPlankaKey()],
        );
    }

    private function requester(ActivityRevision $revision): string
    {
        return $revision->getOrgan()?->getName()
            ?? $revision->getAuthorDisplayName();
    }

    private function title(ActivityRevision $revision): string
    {
        return $revision->getName()->getText(Languages::English)
            ?? $revision->getName()->getText(Languages::Dutch)
            ?? '';
    }

    private function when(ActivityRevision $revision): string
    {
        return $revision->getBeginTime()?->format('d-m-Y H:i') ?? '';
    }
}
